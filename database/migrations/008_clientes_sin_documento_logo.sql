-- FacturaSV - Migración 008: clientes sin documento de identidad y logo del emisor
-- Permite registrar clientes sin DUI/NIT (consumidor final) para Factura 01
-- y agrega la ruta del logo de la empresa para la representación gráfica PDF.

USE facturasv2;
SET NAMES utf8mb4;

ALTER TABLE clientes
    MODIFY tipo_documento ENUM('NIT', 'DUI', 'NINGUNO') NOT NULL,
    MODIFY numero_documento VARCHAR(17) NULL;

ALTER TABLE tenants
    ADD COLUMN logo_path VARCHAR(255) NULL AFTER numero_registro_mh;

INSERT INTO schema_versions (version, description)
VALUES (8, 'Módulo 6: clientes sin documento para Factura y logo del emisor para el PDF')
ON DUPLICATE KEY UPDATE description = VALUES(description);
