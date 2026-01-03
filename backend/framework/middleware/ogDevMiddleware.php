<?php
class ogDevMiddleware {
  private $logMeta = ['module' => 'ogDevMiddleware', 'layer' => 'framework/middleware'];

  function handle() {
    if (!ogSystem::isLocalhost()) {
      ogResponse::error(__('middleware.dev.access_denied'), 403);
      return false;
    }

    return true;
  }
}