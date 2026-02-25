<?php
// 1. VALIDACIÓN INICIAL PARA META/YCLOUD
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_challenge'])) {
    header('Content-Type: text/plain');
    echo $_GET['hub_challenge'];
    exit;
}

// 2. CAPTURA DE DATOS EN FORMATO JSONL POR DÍA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ruta exacta solicitada
    $dir = __DIR__ . '/backend/app/storage/webhook/';
    
    // Crear carpeta si no existe
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Archivo por fecha: webhook_fb-23-02-2026.log
    $fileName = 'webhook_fb-' . date('d-m-Y') . '.log';
    $filePath = $dir . $fileName;

    // Leer el cuerpo del Webhook
    $jsonRaw = file_get_contents('php://input');

    if (!empty($jsonRaw)) {
        // Limpiar JSON para que sea una sola línea (JSONL)
        $jsonSingleLine = str_replace(["\r", "\n"], '', $jsonRaw);
        
        // Guardar al final del archivo del día
        file_put_contents($filePath, $jsonSingleLine . PHP_EOL, FILE_APPEND);
    }

    // Responder OK para evitar reintentos de Meta
    http_response_code(200);
    echo "EVENT_RECEIVED";
}
?>
