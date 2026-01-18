<?php
class AdAutoScaleHandler {
  private static $logMeta = ['module' => 'AdAutoScaleHandler', 'layer' => 'app/resources'];

  // Ejecutar todas las reglas activas (CRON)
  static function executeRules($params = []) {
    try {
      ogLog::info('executeRules - Iniciando ejecución de reglas', [], self::$logMeta);

      $userId = $params['user_id'] ?? ($GLOBALS['auth_user_id'] ?? null);

      $service = ogApp()->service('AdAutoScale');
      $result = $service->processRules($userId);

      if ($result['success']) {
        ogLog::success('executeRules - Ejecución completada', [
          'rules_processed' => $result['rules_processed'],
          'actions_executed' => $result['actions_executed'],
          'execution_time' => $result['execution_time']
        ], self::$logMeta);
      }

      return $result;

    } catch (Exception $e) {
      ogLog::error('executeRules - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Ejecutar una regla específica (testing)
  static function executeRule($params) {
    $ruleId = $params['rule_id'] ?? ogRequest::query('rule_id');

    if (!$ruleId) {
      return [
        'success' => false,
        'error' => 'rule_id requerido'
      ];
    }

    try {
      $rule = ogDb::t('ad_auto_scale')->find($ruleId);
      if (!$rule) {
        return [
          'success' => false,
          'error' => 'Regla no encontrada'
        ];
      }

      $service = ogApp()->service('AdAutoScale');
      
      // Usar reflexión para acceder al método privado (solo para testing)
      $reflection = new ReflectionClass($service);
      $method = $reflection->getMethod('processRule');
      $method->setAccessible(true);
      
      $result = $method->invoke($service, $rule);

      ogLog::info('executeRule - Regla ejecutada', [
        'rule_id' => $ruleId,
        'result' => $result
      ], self::$logMeta);

      return $result;

    } catch (Exception $e) {
      ogLog::error('executeRule - Error', [
        'rule_id' => $ruleId,
        'error' => $e->getMessage()
      ], self::$logMeta);

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }
}