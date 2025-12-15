-- Agregar columna bot_id a la tabla products
ALTER TABLE `products` 
ADD COLUMN `bot_id` int NOT NULL COMMENT 'ID del bot asociado' AFTER `context`,
ADD KEY `idx_bot_id` (`bot_id`);

-- Actualizar registros existentes (asignar un bot por defecto o NULL temporalmente)
-- UPDATE `products` SET `bot_id` = 1 WHERE `bot_id` IS NULL;
