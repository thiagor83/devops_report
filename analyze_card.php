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
        }
        
        echo json_encode([
            'success' => true,
            'history' => $history,
            'durations' => $durations,
            'title' => $item['fields']['System.Title'] ?? 'Card não encontrado',
            'debug' => $debug // Incluir informações de debug na resposta
        ]);
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