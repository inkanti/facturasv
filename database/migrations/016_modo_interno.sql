-- FacturaSV - Módulo 15: modo de facturación por empresa
-- Permite que una empresa opere en modo INTERNO (comprobantes no fiscales)
-- sin transmitir al MH, manteniendo SIMULACION como valor por defecto.
-- PRODUCCION queda reservado para la futura integración real con Hacienda.
-- Compatible con MySQL 8.0+ y MariaDB 10.4+.

USE facturasv2;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE tenant_config
    ADD COLUMN modo_facturacion ENUM('INTERNO', 'SIMULACION', 'PRODUCCION')
        NOT NULL DEFAULT 'SIMULACION' AFTER mh_mode;

-- Serie propia para el comprobante interno (tipo_dte = 'IN').
INSERT IGNORE INTO document_sequences (tenant_id, tipo_dte, establecimiento, prefijo, numero_inicial, numero_actual)
SELECT tenant_id, 'IN', establecimiento, 'INT', 1, 0 FROM tenant_config;

INSERT INTO schema_versions (version, description)
VALUES (16, 'Módulo 15: modo de facturación por empresa y comprobante interno')
ON DUPLICATE KEY UPDATE description = VALUES(description);
