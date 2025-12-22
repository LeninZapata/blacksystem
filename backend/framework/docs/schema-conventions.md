# Convenciones de esquema de base de datos

## Campos de timestamp (automáticos)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `dc` | datetime | Date Created - Fecha de creación (Y-m-d H:i:s) |
| `du` | datetime | Date Updated - Fecha de actualización (Y-m-d H:i:s) |
| `tc` | int | Timestamp Created - Unix timestamp de creación |
| `tu` | int | Timestamp Updated - Unix timestamp de actualización |

**Uso en controllers:**

```php
$data['dc'] = date('Y-m-d H:i:s');
$data['tc'] = time();
```

## Campos estándar comunes

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | int | Primary key auto-increment |
| `config` | json | Configuración JSON del registro |
| `status` | int/string | Estado activo/inactivo |
| `created_at` | datetime | Alternativa a `dc` (Laravel style) |
| `updated_at` | datetime | Alternativa a `du` (Laravel style) |

## Naming conventions

- **Singular** para nombres de tablas: `user`, `client`, `product`
- **Snake_case** para campos: `user_id`, `total_purchases`, `amount_spent`
- **Abreviaturas** solo para timestamps: `dc`, `du`, `tc`, `tu`
