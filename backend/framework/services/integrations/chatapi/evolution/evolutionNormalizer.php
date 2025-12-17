<?php
class evolutionNormalizer {

  // Detectar si es un webhook de Evolution API
  static function detect($rawData) {
    $webhook = is_array($rawData) && isset($rawData[0]) ? $rawData[0] : $rawData;

    // Validación 1: Verificar que server_url contenga 'evo-api'
    $serverUrl = $webhook['body']['server_url'] ?? '';
    $hasEvoApi = !empty($serverUrl) && stripos($serverUrl, 'evo-api') !== false;

    // Validación 2: Verificar estructura body.data.key.id (específica de Evolution)
    $hasKeyStructure = isset($webhook['body']['data']['key']['id']);

    // Validación 3: Evolution API siempre tiene body->event y body->instance
    $hasEventAndInstance = isset($webhook['body']['event']) && isset($webhook['body']['instance']);

    // Debe cumplir al menos 2 de las 3 validaciones
    $validations = [$hasEvoApi, $hasKeyStructure, $hasEventAndInstance];
    $passedValidations = count(array_filter($validations));

    return $passedValidations >= 2;
  }

  // Normalizar webhook de Evolution API
  static function normalize($rawData) {
    // Extraer primer elemento si viene como array
    $webhook = is_array($rawData) && isset($rawData[0]) ? $rawData[0] : $rawData;

    return [
      'provider' => 'evolution',
      'headers' => $webhook['headers'] ?? [],
      'params' => $webhook['params'] ?? [],
      'query' => $webhook['query'] ?? [],
      'body' => $webhook['body'] ?? [],
      'webhookUrl' => $webhook['webhookUrl'] ?? '',
      'executionMode' => $webhook['executionMode'] ?? '',
      'data' => $webhook['body']['data'] ?? [],
      'event' => $webhook['body']['event'] ?? null,
      'instance' => $webhook['body']['instance'] ?? null,
      'serverUrl' => $webhook['body']['server_url'] ?? null,
      'apiKey' => $webhook['body']['apikey'] ?? null,
      'raw' => $webhook
    ];
  }

  // Extraer información del remitente
  static function extractSender($normalizedData) {
    $data = $normalizedData['data'] ?? [];
    $key = $data['key'] ?? [];
    $remoteJid = $key['remoteJid'] ?? null;
    $pushName = $data['pushName'] ?? 'Unknown';

    return [
      'jid' => $remoteJid,
      'number' => $remoteJid ? str_replace('@s.whatsapp.net', '', $remoteJid) : null,
      'name' => $pushName,
      'fromMe' => $key['fromMe'] ?? false
    ];
  }

  // Extraer mensaje
  static function extractMessage($normalizedData) {
    $data = $normalizedData['data'] ?? [];
    $message = $data['message'] ?? [];

    return [
      'type' => $data['messageType'] ?? 'unknown',
      'text' => $message['conversation'] ?? $message['extendedTextMessage']['text'] ?? '',
      'timestamp' => $data['messageTimestamp'] ?? null,
      'status' => $data['status'] ?? null,
      'messageId' => $data['key']['id'] ?? null,
      'raw' => $message
    ];
  }

  // Extraer context info (para FB Ads, etc)
  static function extractContext($normalizedData) {
    $data = $normalizedData['data'] ?? [];
    $contextInfo = $data['contextInfo'] ?? [];

    return [
      'source' => $contextInfo['conversionSource'] ?? null,
      'sourceApp' => $contextInfo['sourceApp'] ?? null,
      'externalAdReply' => $contextInfo['externalAdReply'] ?? null,
      'raw' => $contextInfo
    ];
  }
}