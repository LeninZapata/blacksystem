<?php
$baseUrl = "https://ep-evo-api.multisitio.xyz";
$apiKey = "D3597F758ACE-4BD8-99A6-E2BBC1685328";
$instance = "GigaPackDigitalStore";

if (!isset($_GET['num']) || empty($_GET['num'])) {
    die("Error: Debes proporcionar un número en la URL. Ejemplo: evo.php?num=593939118016");
}

$num = preg_replace('/[^0-9]/', '', $_GET['num']);
$jid = $num . "@s.whatsapp.net";
$url = "$baseUrl/chat/findMessages/$instance";

$body = [
    "where" => [
        "key" => [
            "remoteJid" => $jid
        ]
    ],
    "limit" => 50
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "apikey: $apiKey"
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');

if ($httpCode !== 200) {
    echo json_encode(["error" => "Error de conexión con Evolution", "status" => $httpCode]);
} else {
    echo $response;
}