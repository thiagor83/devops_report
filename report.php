<?php
// Desabilitar exibição de erros
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Definir cabeçalhos
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Carrega as configurações
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Arquivo de configuração não encontrado'
    ]);
    exit;
}

$config = require $configFile;

// Configurações centralizadas
define('DEBUG', true);
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

// Handler de erros personalizado
function handleError($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor',
        'details' => DEBUG ? "$errstr in $errfile on line $errline" : 'Contate o administrador do sistema',
        'code' => 500
    ]);
    exit;
}

// Handler de exceções não capturadas
function handleException($e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor',
        'details' => DEBUG ? $e->getMessage() : 'Contate o administrador do sistema',
        'code' => 500
    ]);
    exit;
}

// Registrar handlers
set_error_handler('handleError');
set_exception_handler('handleException');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        logError("Iniciando processamento", ['method' => 'POST']);
        
        // Verificar se o autoload foi bem sucedido
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            logError("Classe Spreadsheet não encontrada");
            throw new Exception('Biblioteca PhpSpreadsheet não encontrada. Execute: composer update');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        logError("Dados recebidos", ['data' => $data]);
        
        if (empty($data['dateRange'])) {
            throw new Exception('Período é obrigatório');
        }

        // Processar o período com melhor tratamento de formato
        $dateRange = $data['dateRange'];
        $dates = explode(' to ', $dateRange);
        
        if (count($dates) !== 2) {
            throw new Exception('Período deve conter data inicial e final');
        }

        $startDate = DateTime::createFromFormat('d/m/Y', trim($dates[0]));
        $endDate = DateTime::createFromFormat('d/m/Y', trim($dates[1]));

        if (!$startDate || !$endDate) {
            throw new Exception('Formato de data inválido. Use: dd/mm/aaaa');
        }

        // Ajustar as datas para início e fim do dia (sem incluir o horário na query)
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        // Construir a query para a API do Azure DevOps
        $wiql = [
            'query' => "SELECT [System.Id], [System.Title], [System.State], [System.CreatedDate], [System.ChangedDate] " .
                      "FROM WorkItems " .
                      "WHERE [System.TeamProject] = '" . PROJECT . "' " .
                      "AND [System.ChangedDate] >= '" . $startDateStr . "' " .
                      "AND [System.ChangedDate] <= '" . $endDateStr . "' " .
                      "ORDER BY [System.Id]"
        ];

        // Iniciar a geração do relatório
        $baseUrl = "https://dev.azure.com/" . ORGANIZATION;
        $wiqlUrl = "$baseUrl/" . rawurlencode(PROJECT) . "/_apis/wit/wiql?api-version=" . API_VERSION;

        try {
            logError("Iniciando chamada API", ['url' => $wiqlUrl]);
            $result = callDevOpsApi($wiqlUrl, $config['azure']['personal_access_token'], 'POST', $wiql);
            
            if (isset($result['workItems'])) {
                logError("Items encontrados", ['count' => count($result['workItems'])]);
                
                // Verificar permissões do diretório
                $reportDir = __DIR__ . '/reports';
                if (!file_exists($reportDir)) {
                    logError("Criando diretório reports");
                    if (!mkdir($reportDir, 0777, true)) {
                        throw new Exception('Não foi possível criar o diretório de relatórios');
                    }
                }
                
                if (!is_writable($reportDir)) {
                    throw new Exception('Diretório de relatórios sem permissão de escrita');
                }
                
                $totalItems = count($result['workItems']);
                
                // Criar planilha
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                
                // Definir cabeçalhos
                $sheet->setCellValue('A1', 'ID');
                $sheet->setCellValue('B1', 'Título');
                $sheet->setCellValue('C1', 'Status');
                $sheet->setCellValue('D1', 'Data Criação');
                $sheet->setCellValue('E1', 'Última Alteração');
                
                // Estilizar cabeçalhos
                $sheet->getStyle('A1:E1')->getFont()->setBold(true);
                
                // Buscar detalhes de cada item
                $row = 2;
                foreach ($result['workItems'] as $index => $item) {
                    $itemUrl = $baseUrl . "/_apis/wit/workitems/" . $item['id'] . "?api-version=" . API_VERSION;
                    $itemDetails = callDevOpsApi($itemUrl, $config['azure']['personal_access_token']);
                    
                    // Adicionar dados à planilha
                    $sheet->setCellValue('A' . $row, $itemDetails['id']);
                    $sheet->setCellValue('B' . $row, $itemDetails['fields']['System.Title']);
                    $sheet->setCellValue('C' . $row, $itemDetails['fields']['System.State']);
                    $sheet->setCellValue('D' . $row, date('d/m/Y H:i', strtotime($itemDetails['fields']['System.CreatedDate'])));
                    $sheet->setCellValue('E' . $row, date('d/m/Y H:i', strtotime($itemDetails['fields']['System.ChangedDate'])));
                    
                    $row++;
                }
                
                // Ajustar largura das colunas
                foreach(range('A','E') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
                
                // Salvar arquivo
                $fileName = 'relatorio_' . date('Y-m-d_His') . '.xlsx';
                $filePath = $reportDir . '/' . $fileName;
                
                $writer = new Xlsx($spreadsheet);
                $writer->save($filePath);
                
                // Retornar sucesso com URL para download
                echo json_encode([
                    'success' => true,
                    'message' => 'Relatório gerado com sucesso',
                    'fileUrl' => 'reports/' . $fileName,
                    'totalItems' => $totalItems,
                    'processedItems' => $totalItems
                ]);
            } else {
                throw new Exception('Nenhum item encontrado no período');
            }
        } catch (Exception $e) {
            logError("Erro na geração", ['error' => $e->getMessage()]);
            throw $e;
        }
    } else {
        throw new Exception('Método HTTP não suportado. Use POST.');
    }
} catch (Exception $e) {
    logError("Erro principal", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ocorreu um erro ao gerar o relatório',
        'details' => DEBUG ? $e->getMessage() : 'Erro ao processar a requisição',
        'code' => 500
    ]);
}
?> 