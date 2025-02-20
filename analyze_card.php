<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require 'vendor/autoload.php';

// Carrega as configurações
$configFile = __DIR__ . '/config.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Arquivo de configuração não encontrado. Por favor, copie config.example.php para config.php e configure-o.'
    ]);
    exit;
}

$config = require $configFile;

if (!isset($config['azure']) || 
    !isset($config['azure']['api_version']) || 
    !isset($config['azure']['organization']) || 
    !isset($config['azure']['project']) || 
    !isset($config['azure']['personal_access_token'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Configuração incompleta. Verifique se todos os campos necessários estão preenchidos no config.php.'
    ]);
    exit;
}

// Define as constantes
define('API_VERSION', $config['azure']['api_version']);
define('ORGANIZATION', $config['azure']['organization']);
define('PROJECT', $config['azure']['project']);

function callDevOpsApi($url, $pat, $method = 'GET', $body = null) {
    $ch = curl_init();
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(':' . $pat)
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    if ($method === 'POST' && $body) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }
    
    curl_close($ch);
    
    if ($httpCode >= 400) {
        throw new Exception("Erro na API (HTTP $httpCode): $response");
    }
    
    return json_decode($response, true);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['cardId'])) {
            throw new Exception('ID do card é obrigatório');
        }
        
        $pat = $config['azure']['personal_access_token'];
        $cardId = $data['cardId'];
        
        // URL base
        $baseUrl = "https://dev.azure.com/" . ORGANIZATION;
        
        // Construir URL do card
        $itemUrl = "$baseUrl/" . rawurlencode(PROJECT) . "/_apis/wit/workitems/$cardId?api-version=" . API_VERSION;
        
        // Debug info
        if (isset($_GET['debug'])) {
            echo json_encode([
                'success' => false,
                'debug' => [
                    'url' => $itemUrl,
                    'organization' => ORGANIZATION,
                    'project' => PROJECT,
                    'api_version' => API_VERSION
                ]
            ]);
            exit;
        }
        
        try {
            $item = callDevOpsApi($itemUrl, $pat);
        } catch (Exception $e) {
            throw new Exception("Erro ao acessar o card: " . $e->getMessage() . "\nURL: $itemUrl");
        }
        
        // Buscar histórico
        $historyUrl = "$baseUrl/" . rawurlencode(PROJECT) . "/_apis/wit/workitems/$cardId/updates?api-version=" . API_VERSION;
        $historyResult = callDevOpsApi($historyUrl, $pat);
        
        $history = [];
        $durations = [];
        $statusDurations = [];

        if (isset($historyResult['value'])) {
            // Ordenar os eventos por data em ordem crescente
            $updates = $historyResult['value'];
            usort($updates, function($a, $b) {
                $timeA = strtotime($a['fields']['System.ChangedDate']['newValue'] ?? $a['revisedDate']);
                $timeB = strtotime($b['fields']['System.ChangedDate']['newValue'] ?? $b['revisedDate']);
                return $timeA - $timeB;
            });
            
            $statusDurations = [];
            $debugTimestamps = [];
            
            // Processar cada mudança de status
            for ($i = 0; $i < count($updates); $i++) {
                $update = $updates[$i];
                
                if (isset($update['fields']['System.State'])) {
                    $startTime = strtotime($update['fields']['System.ChangedDate']['newValue'] ?? $update['revisedDate']);
                    $currentStatus = $update['fields']['System.State']['newValue'];
                    
                    // Determinar o tempo final deste status
                    $endTime = null;
                    if ($i < count($updates) - 1) {
                        // Procurar a próxima mudança de status
                        for ($j = $i + 1; $j < count($updates); $j++) {
                            if (isset($updates[$j]['fields']['System.State'])) {
                                $endTime = strtotime($updates[$j]['fields']['System.ChangedDate']['newValue'] ?? $updates[$j]['revisedDate']);
                                break;
                            }
                        }
                    }
                    
                    if ($endTime === null) {
                        $endTime = time(); // Para o status atual
                    }
                    
                    // Calcular duração em horas com precisão
                    $duration = ($endTime - $startTime) / 3600;
                    
                    // Registrar a duração para este status
                    if (!isset($statusDurations[$currentStatus])) {
                        $statusDurations[$currentStatus] = 0;
                    }
                    $statusDurations[$currentStatus] += $duration;
                    
                    // Debug info
                    $debugTimestamps[] = [
                        'status' => $currentStatus,
                        'start' => date('d/m/Y H:i:s', $startTime),
                        'end' => date('d/m/Y H:i:s', $endTime),
                        'duration' => $duration,
                        'durationFormatted' => sprintf('%.2f horas', $duration)
                    ];
                    
                    // Adicionar ao histórico
                    $history[] = [
                        'date' => date('d/m/Y H:i', $startTime),
                        'author' => $update['revisedBy']['displayName'] ?? 'Não identificado',
                        'oldState' => $update['fields']['System.State']['oldValue'] ?? '-',
                        'newState' => $currentStatus,
                        'duration' => $duration
                    ];
                }
            }
            
            // Formatar durações para exibição
            $durations = [];
            foreach ($statusDurations as $status => $hours) {
                $hours = round($hours, 2);
                $days = floor($hours / 24);
                $remainingHours = $hours - ($days * 24);
                $minutes = round(($remainingHours - floor($remainingHours)) * 60);
                
                if ($days > 0) {
                    $formattedDuration = sprintf('%dd %dh %dm', $days, floor($remainingHours), $minutes);
                } else {
                    $formattedDuration = sprintf('%dh %dm', floor($remainingHours), $minutes);
                }
                
                $durations[] = [
                    'status' => $status,
                    'duration' => $hours,
                    'formatted' => $formattedDuration
                ];
            }
            
            // Adicionar informações de debug
            $debug = [
                'timestamps' => $debugTimestamps,
                'rawDurations' => $statusDurations,
                'calculatedDurations' => array_map(function($d) {
                    return [
                        'status' => $d['status'],
                        'hours' => $d['duration'],
                        'formatted' => $d['formatted']
                    ];
                }, $durations)
            ];

            // Dentro do try, após obter $item e antes de renderizar o histórico
            $productivityAnalysis = [
                'created_date' => date('d/m/Y H:i', strtotime($item['fields']['System.CreatedDate'])),
                'dev_start' => null,
                'dev_end' => null,
                'total_dev_time' => 0,
                'total_test_time' => 0,
                'total_approval_time' => 'Ainda não Aprovado',
                'is_approved' => false,
                'current_status' => $item['fields']['System.State']['newValue'] ?? 'New', // Status atual
                'reproved_count' => 0 // Contador de reprovações
            ];

            // Processar histórico para análise de produtividade
            if (isset($historyResult['value'])) {
                $lastTestStatus = null; // Para rastrear o último status de teste
                
                foreach ($updates as $update) {
                    if (isset($update['fields']['System.State'])) {
                        $date = strtotime($update['fields']['System.ChangedDate']['newValue'] ?? $update['revisedDate']);
                        $newState = $update['fields']['System.State']['newValue'];
                        $oldState = $update['fields']['System.State']['oldValue'] ?? null;
                        
                        // Detectar início do desenvolvimento
                        if ($newState === 'Em desenvolvimento' && !$productivityAnalysis['dev_start']) {
                            $productivityAnalysis['dev_start'] = date('d/m/Y H:i', $date);
                        }
                        
                        // Detectar conclusão do desenvolvimento (apenas se aprovado ou Done)
                        if (($newState === 'Aprovado (Teste)' || $newState === 'Done') && !$productivityAnalysis['dev_end']) {
                            $productivityAnalysis['dev_end'] = date('d/m/Y H:i', $date);
                        }

                        // Detectar reprovações
                        if ($newState === 'Em desenvolvimento' && 
                            $oldState && 
                            (strpos($oldState, 'Teste') !== false || $oldState === 'Code Review')) {
                            $productivityAnalysis['reproved_count']++;
                        }

                        // Detectar aprovação
                        if ($newState === 'Aprovado (Teste)' || $newState === 'Done') {
                            $productivityAnalysis['is_approved'] = true;
                            $productivityAnalysis['total_approval_time'] = round(($date - strtotime($item['fields']['System.CreatedDate'])) / 3600, 2);
                        }
                        
                        // Atualizar status atual
                        $productivityAnalysis['current_status'] = $newState;
                    }
                }
            }

            // Calcular tempos totais a partir das durações
            foreach ($statusDurations as $status => $hours) {
                if ($status === 'Em desenvolvimento') {
                    $productivityAnalysis['total_dev_time'] = round($hours, 2);
                } elseif (in_array($status, ['Liberado para Teste TI', 'Liberado para Teste Usuário'])) {
                    $productivityAnalysis['total_test_time'] += round($hours, 2);
                }
            }

            // Incluir a análise de produtividade na resposta
            $response = [
                'success' => true,
                'title' => $item['fields']['System.Title'] ?? 'Card não encontrado',
                'description' => $item['fields']['System.Description'] ?? '',
                'status' => $item['fields']['System.State'],
                'fields' => [
                    'Expected Finish' => $item['fields']['Custom.c651c2bf-25c9-4ab4-bc20-0f80b9926661'] ?? null,
                    'Effort' => $item['fields']['Microsoft.VSTS.Scheduling.Effort'] ?? null
                ],
                'productivity' => [
                    'created_date' => $productivityAnalysis['created_date'],
                    'dev_start' => $productivityAnalysis['dev_start'],
                    'dev_end' => $productivityAnalysis['dev_end'],
                    'is_approved' => $productivityAnalysis['is_approved'],
                    'total_dev_time' => $productivityAnalysis['total_dev_time'],
                    'total_test_time' => $productivityAnalysis['total_test_time'],
                    'total_approval_time' => $productivityAnalysis['total_approval_time'],
                    'reproved_count' => $productivityAnalysis['reproved_count']
                ],
                'history' => $history,
                'durations' => $durations,
                'debug' => $debug
            ];

            echo json_encode($response);
        }
    } else {
        throw new Exception('Método HTTP não suportado. Use POST.');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 