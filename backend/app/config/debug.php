<?php
log::setConfig([
  'format' => 'custom',
  'template' => '{year}/{month}/{day}/{module}.log',
  'level' => IS_DEV ? 'debug' : 'info',  // Nivel mínimo
  'max_size' => 1048576,            // 1MB por archivo
  'enabled' => true                 // Habilitar logs
]);