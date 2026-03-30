-- ============================================================
-- Migración: Integración Hotmart
-- ============================================================


-- ------------------------------------------------------------
-- 1. ALTER TABLE sales
--    Agrega columna module: identifica si la venta vino desde
--    el bot de WhatsApp o fue una compra directa sin bot.
-- ------------------------------------------------------------
ALTER TABLE `sales`
  ADD COLUMN `module` varchar(20) DEFAULT 'whatsapp'
  COMMENT 'Canal de venta: whatsapp = via bot WhatsApp, direct = compra directa sin bot'
  AFTER `bot_mode`;


-- ------------------------------------------------------------
-- 2. CREATE TABLE payment
--    Guarda el payload completo del webhook de Hotmart
--    vinculado a la venta en tabla sales.
-- ------------------------------------------------------------
CREATE TABLE `payment` (
  `id`                           int(11)         NOT NULL AUTO_INCREMENT COMMENT 'Identificador único del pago',
  `sale_id`                      int(11)         NOT NULL                COMMENT 'ID de la venta asociada (relación con tabla sales)',

  -- Webhook general
  `webhook_id`                   varchar(100)    DEFAULT NULL            COMMENT 'ID único del webhook de Hotmart',
  `webhook_event`                varchar(50)     DEFAULT NULL            COMMENT 'Evento: PURCHASE_APPROVED, PURCHASE_COMPLETE, PURCHASE_CANCELED, etc.',
  `webhook_version`              varchar(20)     DEFAULT NULL            COMMENT 'Versión del webhook de Hotmart',

  -- Producto (de Hotmart, no el ID interno del sistema)
  `product_id`                   int(11)         DEFAULT NULL            COMMENT 'ID numérico del producto en Hotmart',
  `product_ucode`                varchar(100)    DEFAULT NULL            COMMENT 'UUID del producto en Hotmart',
  `product_name`                 varchar(250)    DEFAULT NULL            COMMENT 'Nombre del producto',
  `product_has_co_production`    tinyint(1)      DEFAULT 0               COMMENT 'Tiene coproducción: 0=No, 1=Sí',

  -- Comisiones
  `commission_marketplace_currency` char(3)      DEFAULT NULL            COMMENT 'Moneda comisión marketplace (USD, BRL, etc.)',
  `commission_marketplace_value`    decimal(10,6) DEFAULT NULL           COMMENT 'Valor comisión marketplace',
  `commission_producer_currency`    char(3)      DEFAULT NULL            COMMENT 'Moneda comisión productor',
  `commission_producer_value`       decimal(10,6) DEFAULT NULL           COMMENT 'Valor comisión productor (lo que realmente recibes)',

  -- Compra
  `purchase_transaction`         varchar(100)    DEFAULT NULL            COMMENT 'Transaction ID de Hotmart (ej: HP0096274894)',
  `purchase_status`              varchar(50)     DEFAULT NULL            COMMENT 'Estado: APPROVED, COMPLETED, CANCELED, REFUNDED, etc.',
  `purchase_currency`            char(3)         DEFAULT NULL            COMMENT 'Moneda de la compra (USD, BRL, etc.)',
  `purchase_price`               decimal(10,2)   DEFAULT NULL            COMMENT 'Precio pagado',
  `purchase_full_price`          decimal(10,2)   DEFAULT NULL            COMMENT 'Precio completo sin descuentos',
  `purchase_original_price`      decimal(10,2)   DEFAULT NULL            COMMENT 'Precio original de la oferta',
  `purchase_country_code`        char(2)         DEFAULT NULL            COMMENT 'Código ISO del país de compra (US, EC, PE, etc.)',
  `purchase_country_name`        varchar(100)    DEFAULT NULL            COMMENT 'Nombre del país de compra',
  `purchase_ip`                  varchar(50)     DEFAULT NULL            COMMENT 'IP del comprador al momento del pago',
  `purchase_order_date`          datetime        DEFAULT NULL            COMMENT 'Fecha de orden de compra',
  `purchase_approved_date`       datetime        DEFAULT NULL            COMMENT 'Fecha de aprobación/completado',
  `purchase_is_funnel`           tinyint(1)      DEFAULT 0               COMMENT 'Es parte de un funnel: 0=No, 1=Sí',
  `purchase_is_order_bump`       tinyint(1)      DEFAULT 0               COMMENT 'Es order bump: 0=No, 1=Sí',

  -- Pago
  `payment_type`                 varchar(50)     DEFAULT NULL            COMMENT 'Tipo: CREDIT_CARD, DEBIT_CARD, PIX, BOLETO, etc.',
  `payment_installments`         tinyint(4)      DEFAULT 1               COMMENT 'Número de cuotas',

  -- Oferta
  `offer_code`                   varchar(50)     DEFAULT NULL            COMMENT 'Código de la oferta en Hotmart',
  `offer_name`                   varchar(250)    DEFAULT NULL            COMMENT 'Nombre de la oferta',

  -- Productor
  `producer_name`                varchar(200)    DEFAULT NULL            COMMENT 'Nombre del productor',
  `producer_document`            varchar(50)     DEFAULT NULL            COMMENT 'Documento del productor (CPF, CI, etc.)',
  `producer_legal_nature`        varchar(100)    DEFAULT NULL            COMMENT 'Naturaleza legal: Pessoa Física, Pessoa Jurídica, etc.',

  -- Comprador
  `buyer_name`                   varchar(200)    DEFAULT NULL            COMMENT 'Nombre completo del comprador',
  `buyer_first_name`             varchar(100)    DEFAULT NULL            COMMENT 'Primer nombre',
  `buyer_last_name`              varchar(100)    DEFAULT NULL            COMMENT 'Apellido',
  `buyer_email`                  varchar(150)    DEFAULT NULL            COMMENT 'Email del comprador (clave para detección de upsell)',
  `buyer_phone`                  varchar(25)     DEFAULT NULL            COMMENT 'Teléfono del comprador en Hotmart',
  `buyer_document`               varchar(50)     DEFAULT NULL            COMMENT 'Documento del comprador',
  `buyer_country_code`           char(2)         DEFAULT NULL            COMMENT 'Código ISO del país del comprador',
  `buyer_country_name`           varchar(100)    DEFAULT NULL            COMMENT 'Nombre del país del comprador',
  `buyer_address`                varchar(250)    DEFAULT NULL            COMMENT 'Dirección',
  `buyer_city`                   varchar(100)    DEFAULT NULL            COMMENT 'Ciudad',
  `buyer_zipcode`                varchar(20)     DEFAULT NULL            COMMENT 'Código postal',

  -- Control
  `status`  tinyint(4)    DEFAULT 1     NOT NULL COMMENT '0=Inactivo, 1=Activo',
  `dc`      datetime      NOT NULL               COMMENT 'Fecha de creación',
  `du`      datetime      DEFAULT NULL           COMMENT 'Fecha de última actualización',
  `tc`      int(11)       NOT NULL               COMMENT 'Timestamp de creación',
  `tu`      int(11)       DEFAULT NULL           COMMENT 'Timestamp de actualización',

  PRIMARY KEY (`id`),
  KEY `idx_sale_id`           (`sale_id`),
  KEY `idx_purchase_transaction` (`purchase_transaction`),
  KEY `idx_buyer_email`       (`buyer_email`),
  KEY `idx_webhook_event`     (`webhook_event`),
  KEY `idx_dc`                (`dc`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
  COMMENT='Detalle completo de pagos/webhooks de Hotmart vinculados a ventas';
