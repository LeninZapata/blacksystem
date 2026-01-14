-- Tabla: bots
-- Descripción: Almacena los bots de WhatsApp del sistema

CREATE TABLE IF NOT EXISTS `bots` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL COMMENT 'Nombre del bot',
  `personality` VARCHAR(250) NULL DEFAULT NULL COMMENT 'Personalidad del bot',
  `config` JSON NULL DEFAULT NULL COMMENT 'Configuración adicional del bot',
  `dc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
  `da` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de actualización',
  `ta` INT(11) NOT NULL COMMENT 'Timestamp de creación',
  `tu` INT(11) NULL DEFAULT NULL COMMENT 'Timestamp de actualización',
  PRIMARY KEY (`id`),
  INDEX `idx_name` (`name`),
  INDEX `idx_dc` (`dc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bots de WhatsApp';

-- Trigger para actualizar timestamp de creación
DELIMITER $$
CREATE TRIGGER `bots_before_insert` 
BEFORE INSERT ON `bots`
FOR EACH ROW 
BEGIN
  SET NEW.ta = UNIX_TIMESTAMP();
END$$
DELIMITER ;

-- Trigger para actualizar timestamp de actualización
DELIMITER $$
CREATE TRIGGER `bots_before_update` 
BEFORE UPDATE ON `bots`
FOR EACH ROW 
BEGIN
  SET NEW.tu = UNIX_TIMESTAMP();
END$$
DELIMITER ;
