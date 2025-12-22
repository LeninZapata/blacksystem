<?php

class ActionDispatcher {

  private $registry;

  public function __construct() {
    $this->registry = new ActionRegistry();
  }

  public function dispatch($action, $context) {
    if (empty($action)) {
      return null;
    }

    $handler = $this->registry->getHandler($action);

    if (!$handler) {
      log::warning("Action no registrado: {$action}", [], ['module' => 'action_dispatcher']);
      return null;
    }

    log::info("Despachando action: {$action}", [
      'handler' => get_class($handler)
    ], ['module' => 'action_dispatcher']);

    return $handler->handle($context);
  }

  public function dispatchMultiple($actions, $context) {
    $results = [];

    foreach ($actions as $action) {
      $results[$action] = $this->dispatch($action, $context);
    }

    return $results;
  }

  public function getRegistry() {
    return $this->registry;
  }
}