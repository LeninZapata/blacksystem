<?php
trait ValidatesUnique {

  // Validar campo único (para create)
  protected function validateUnique($table, $field, $value, $errorKey = null) {
    if (db::table($table)->where($field, $value)->exists()) {
      $error = $errorKey ?? "validation.field_exists";
      response::error(__($error, ['field' => $field]), 400);
    }
  }

  // Validar campo único excepto ID actual (para update)
  protected function validateUniqueExcept($table, $field, $value, $excludeId, $errorKey = null) {
    $exists = db::table($table)
      ->where($field, $value)
      ->where('id', '!=', $excludeId)
      ->exists();

    if ($exists) {
      $error = $errorKey ?? "validation.field_exists";
      response::error(__($error, ['field' => $field]), 400);
    }
  }

  // Validar email (formato + unicidad)
  protected function validateEmail($email, $table = null, $excludeId = null) {
    if (empty($email)) return;

    // Validar formato
    if (!validation::email($email)) {
      response::error(__('validation.invalid_email'), 400);
    }

    // Validar unicidad si se especifica tabla
    if ($table) {
      if ($excludeId) {
        $this->validateUniqueExcept($table, 'email', $email, $excludeId, 'user.email_exists');
      } else {
        $this->validateUnique($table, 'email', $email, 'user.email_exists');
      }
    }
  }
}