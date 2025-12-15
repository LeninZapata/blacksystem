-- Agregar columna price a la tabla products
ALTER TABLE `products` 
ADD COLUMN `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Precio del producto' AFTER `bot_id`;
