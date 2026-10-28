-- FacturaSV - Módulo 16: código de barras por producto
-- Un código de barras opcional por producto, único por empresa.
-- Compatible con MySQL 8.0+ y MariaDB 10.4+.

USE facturasv2;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE productos
    ADD COLUMN codigo_barras VARCHAR(64) NULL AFTER codigo,
    ADD UNIQUE KEY uq_productos_tenant_codigo_barras (tenant_id, codigo_barras);

-- Si un producto ya usa un código numérico de 8, 12, 13 o 14 dígitos como
-- código interno, se copia como código de barras inicial.
UPDATE productos
SET codigo_barras = codigo
WHERE codigo_barras IS NULL
  AND codigo REGEXP '^[0-9]{8}$|^[0-9]{12,14}$';

INSERT INTO schema_versions (version, description)
VALUES (17, 'Módulo 16: código de barras por producto y búsqueda por escaneo')
ON DUPLICATE KEY UPDATE description = VALUES(description);
