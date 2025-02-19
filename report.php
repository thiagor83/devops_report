<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Configurações centralizadas
define('DEBUG', false);
define('API_VERSION', '7.1');
define('ORGANIZATION', 'clinicadoleite');
define('PROJECT', 'Legado e ETL BI');
define('CACHE_DURATION', 3600); // 1 hora
define('MAX_ITEMS_PER_PAGE', 200);

// Função para validar PAT
function validatePat($pat) {
    if (empty($pat) || strlen($pat) < 30) {
        throw new Exception('Token PAT inválido ou muito curto');
    }
    return true;
}

// Função para validar input
function validateInput($data) {
    validatePat($data['pat']);
    
    if (!empty($data['cardId']) && !is_numeric($data['cardId'])) {
        throw new Exception('O ID do card deve ser um número');
    }
    return true;
}

// Função para processar intervalo de datas
function processDateRange($dateRange) {
    if (empty($dateRange)) {
        return [
            'filter' => '',
            'startTimestamp' => null,
            'endTimestamp' => null
        ];
    }

    $dates = explode(' até ', str_replace('/', '-', $dateRange));
    if (count($dates) != 2) {
        throw new Exception('Formato de data inválido. Use: dd/mm/yyyy até dd/mm/yyyy');
    }

    $startDate = date('Y-m-d', strtotime($dates[0]));
    $endDate = date('Y-m-d', strtotime($dates[1]));
    
    return [
        'filter' => "AND [System.ChangedDate] >= '$startDate' AND [System.ChangedDate] <= '$endDate' ",
        'startTimestamp' => strtotime($dates[0] . ' 00:00:00'),
        'endTimestamp' => strtotime($dates[1] . ' 23:59:59')
    ];
}

// Função para log estruturado
function logError($message, $context = []) {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context
    ];
    error_log(json_encode($logEntry));
}

// Função para chamadas à API do Azure DevOps
function callDevOpsApi($url, $pat, $method = 'GET', $body = null) {
    $cacheKey = md5($url . serialize($body));
    $cacheFile = sys_get_temp_dir() . '/devops_cache_' . $cacheKey;

    // Verificar cache
    if ($method === 'GET' && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_DURATION) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $ch = curl_init();
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(':' . $pat)
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_VERBOSE => true
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            logError("Request body", ['url' => $url, 'body' => $body]);
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        logError("Curl error", ['error' => $error, 'url' => $url]);
        throw new Exception("Erro na conexão: $error");
    }
    
    curl_close($ch);
    
    if ($httpCode >= 400) {
        logError("API error", ['code' => $httpCode, 'response' => $response, 'url' => $url]);
        throw new Exception("Erro na API (HTTP $httpCode): $response");
    }
    
    $decodedResponse = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar resposta JSON: " . json_last_error_msg());
    }
    
    // Salvar no cache se for GET
    if ($method === 'GET') {
        file_put_contents($cacheFile, $response);
    }
    
    return $decodedResponse;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Dados JSON inválidos');
        }
        
        validateInput($data);
        
        $pat = $data['pat'];
        $cardId = $data['cardId'] ?? '';
        $dateInfo = processDateRange($data['dateRange'] ?? '');
        
        // URL base para as APIs
        $baseUrl = "https://dev.azure.com/" . ORGANIZATION;
        
        // WIQL query
        $wiqlUrl = "$baseUrl/" . rawurlencode(PROJECT) . "/_apis/wit/wiql?api-version=" . API_VERSION;
        
        $query = [
            'query' => "Select [System.Id] 
                       From WorkItems 
                       Where [System.TeamProject] = '" . PROJECT . "'
                       AND [System.WorkItemType] = 'Product Backlog Item'
                       AND [System.State] <> 'Removed'
                       " . (!empty($cardId) ? "AND [System.Id] = $cardId" : "") 
                       . $dateInfo['filter'] 
                       . "Order By [System.ChangedDate] DESC"
        ];
        
        logError("WIQL Query", ['query' => $query['query']]);
        
        $wiqlResult = callDevOpsApi($wiqlUrl, $pat, 'POST', $query);
        
        if (empty($wiqlResult['workItems'])) {
            throw new Exception('Nenhum item de trabalho encontrado');
        }
        
        // Processar work items em lotes
        $workItemIds = array_column($wiqlResult['workItems'], 'id');
        $batches = array_chunk($workItemIds, MAX_ITEMS_PER_PAGE);
        
        $allDetails = [];
        $processedItems = 0;
        $totalItems = count($workItemIds);
        
        foreach ($batches as $batch) {
            $detailsUrl = "$baseUrl/" . rawurlencode(PROJECT) . 
                         "/_apis/wit/workitems?ids=" . implode(',', $batch) . 
                         "&fields=System.Id,System.Title,System.State,System.ChangedDate,System.AssignedTo&api-version=" . API_VERSION;
            
            $detailsResult = callDevOpsApi($detailsUrl, $pat);
            if (isset($detailsResult['value'])) {
                $allDetails = array_merge($allDetails, $detailsResult['value']);
            }
            
            $processedItems += count($batch);
            $progress = round(($processedItems / $totalItems) * 100);
            logError("Progress", ['processed' => $processedItems, 'total' => $totalItems, 'percentage' => $progress]);
        }
        
        // Criar planilha Excel
        $spreadsheet = new Spreadsheet();
        
        // Primeira aba - Cards
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cards');
        
        // Cabeçalhos
        $headers = ['Código', 'Título', 'Status', 'Data Status', 'Responsável'];
        $sheet->fromArray($headers, null, 'A1');
        
        // Estilizar cabeçalhos
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'color' => ['rgb' => 'E0E0E0']]
        ];
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
        
        // Dados
        $row = 2;
        foreach ($allDetails as $item) {
            if (!empty($dateInfo['filter'])) {
                $itemDate = strtotime($item['fields']['System.ChangedDate']);
                if ($itemDate < $dateInfo['startTimestamp'] || $itemDate > $dateInfo['endTimestamp']) {
                    continue;
                }
            }
            
            $sheet->setCellValue('A' . $row, $item['id']);
            $sheet->setCellValue('B' . $row, $item['fields']['System.Title']);
            $sheet->setCellValue('C' . $row, $item['fields']['System.State']);
            $sheet->setCellValue('D' . $row, date('d/m/Y H:i', strtotime($item['fields']['System.ChangedDate'])));
            $sheet->setCellValue('E' . $row, $item['fields']['System.AssignedTo']['displayName'] ?? 'Não atribuído');
            $row++;
        }
        
        // Auto-size colunas
        foreach(range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Segunda aba - Histórico
        $historySheet = $spreadsheet->createSheet();
        $historySheet->setTitle('Histórico');
        
        // Cabeçalhos do histórico
        $historyHeaders = ['Código', 'Título', 'De', 'Para', 'Data Mudança', 'Responsável'];
        $historySheet->fromArray($historyHeaders, null, 'A1');
        $historySheet->getStyle('A1:F1')->applyFromArray($headerStyle);
        
        // Dados do histórico
        $row = 2;
        foreach ($allDetails as $item) {
            $historyUrl = "$baseUrl/" . rawurlencode(PROJECT) . 
                         "/_apis/wit/workitems/{$item['id']}/updates?api-version=" . API_VERSION;
            
            $historyResult = callDevOpsApi($historyUrl, $pat);
            
            if (isset($historyResult['value'])) {
                foreach ($historyResult['value'] as $update) {
                    if (isset($update['fields']['System.State'])) {
                        $updateDate = strtotime($update['fields']['System.ChangedDate']['newValue'] ?? $update['revisedDate']);
                        
                        if (!empty($dateInfo['filter'])) {
                            if ($updateDate < $dateInfo['startTimestamp'] || $updateDate > $dateInfo['endTimestamp']) {
                                continue;
                            }
                        }
                        
                        $stateChange = $update['fields']['System.State'];
                        
                        $historySheet->setCellValue('A' . $row, $item['id']);
                        $historySheet->setCellValue('B' . $row, $item['fields']['System.Title']);
                        $historySheet->setCellValue('C' . $row, $stateChange['oldValue'] ?? '');
                        $historySheet->setCellValue('D' . $row, $stateChange['newValue'] ?? '');
                        $historySheet->setCellValue('E' . $row, date('d/m/Y H:i', $updateDate));
                        $historySheet->setCellValue('F' . $row, $update['revisedBy']['displayName'] ?? 'Não identificado');
                        
                        $row++;
                    }
                }
            }
        }
        
        // Auto-size colunas do histórico
        foreach(range('A', 'F') as $col) {
            $historySheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Voltar para primeira aba
        $spreadsheet->setActiveSheetIndex(0);
        
        // Gerar arquivo
        $filename = 'relatorio_devops_' . date('Y-m-d_His') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($filename);
        
        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'totalItems' => $totalItems,
            'processedItems' => $processedItems,
            'message' => 'Relatório gerado com sucesso!'
        ]);
    } else {
        throw new Exception('Método HTTP não suportado. Use POST.');
    }
} catch (Exception $e) {
    logError("Error", ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => DEBUG ? $e->getMessage() : 'Ocorreu um erro ao gerar o relatório. Tente novamente.',
        'details' => DEBUG ? $e->getTraceAsString() : null,
        'code' => $e->getCode() ?: 500
    ]);
}
?> 