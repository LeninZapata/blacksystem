<?php
trait ogValidatesUnique {

  // Validar campo único (para create)
  public function validateUnique($table, $field, $value, $errorKey = null) {
    if (ogDb::table($table)->where($field, $value)->exists()) {
      $error = $errorKey ?? "validation.field_exists";
      ogResponse::error(__($error, ['field' => $field]), 400);
    }
  }

  // Validar campo único excepto ID actual (para update)
  public function validateUniqueExcept($table, $field, $value, $excludeId, $errorKey = null) {
    $exists = ogDb::table($table)
      ->where($field, $value)
      ->where('id', '!=', $excludeId)
      ->exists();

    if ($exists) {
      $error = $errorKey ?? "validation.field_exists";
      ogResponse::error(__($error, ['field' => $field]), 400);
    }
  }

  // Validar email (formato + unicidad)
  public function validateEmail($email, $table = null, $excludeId = null) {
    if (empty($email)) return;

    // Cargar ogValidation bajo demanda
    $validation = ogApp()->helper('validation');
    // Validar formato
    if (!$validation->email($email)) {
      ogResponse::error(__('validation.invalid_email'), 400);
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