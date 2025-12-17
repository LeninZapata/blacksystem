<?php
// workflowHandlers - Handlers personalizados para work_flows
class workflowHandlers {

  /**
   * Actualiza los archivos JSON de contexto de todos los bots que usan un workflow específico
   *
   * @param int $workflowId - ID del workflow actualizado
   * @return int - Cantidad de bots actualizados
   */
  static function updateBotsContext($workflowId) {
    if (!$workflowId) {
      log::warning('workflowHandlers - workflow_id no proporcionado', [], ['module' => 'workflow']);
      return 0;
    }
    
    // Futuro: detectar otros proveedores
    // if (WazapiNormalizer::detect($rawData)) return 'wazapi';
    // if (TelegramNormalizer::detect($rawData)) return 'telegram';
    
    return null;
  }
  
  // Extraer información del sender
  static function extractSender($normalizedData) {
    $provider = $normalizedData['provider'] ?? null;
    
    if (!$provider) {
      throw new Exception('webhookHandlers::extractSender - Provider no encontrado en data normalizada');
    }
    
    return service::integration("chatapi/{$provider}", 'extractSender', $normalizedData);
  }
  
  // Extraer mensaje
  static function extractMessage($normalizedData) {
    $provider = $normalizedData['provider'] ?? null;
    
    if (!$provider) {
      throw new Exception('webhookHandlers::extractMessage - Provider no encontrado en data normalizada');
    }
    
    return service::integration("chatapi/{$provider}", 'extractMessage', $normalizedData);
  }
  
  // Extraer contexto (opcional, para FB Ads, etc)
  static function extractContext($normalizedData) {
    $provider = $normalizedData['provider'] ?? null;
    
    if (!$provider) {
      return [];
    }
    
    return service::integration("chatapi/{$provider}", 'extractContext', $normalizedData);
  }
}