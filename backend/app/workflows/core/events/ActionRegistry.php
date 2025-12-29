<?php

class ActionRegistry {
  
  private $handlers = [];
  private $handlerClasses = [];
  private $logMeta = ['module' => 'ActionRegistry', 'layer' => 'app/workflows'];

  public function register($actionName, $handlerClass) {
    $this->handlerClasses[$actionName] = $handlerClass;
    
    // ogLog::debug("Action registrado (lazy): {$actionName}", [ 'class' => $handlerClass ], $this->logMeta);
  }

  public function getHandler($actionName) {
    if (isset($this->handlers[$actionName])) {
      return $this->handlers[$actionName];
    }

    if (!isset($this->handlerClasses[$actionName])) {
      return null;
    }

    $handlerClass = $this->handlerClasses[$actionName];

    if (!class_exists($handlerClass)) {
      ogLog::error("Clase de handler no encontrada: {$handlerClass}", [], ['module' => 'action_registry']);
      return null;
    }

    $handler = new $handlerClass();
    $this->handlers[$actionName] = $handler;

    ogLog::info("Handler instanciado (lazy): {$actionName}", [
      'class' => $handlerClass
    ], ['module' => 'action_registry']);

    return $handler;
  }

  public function hasHandler($actionName) {
    return isset($this->handlerClasses[$actionName]);
  }

  public function listActions() {
    return array_keys($this->handlerClasses);
  }
}