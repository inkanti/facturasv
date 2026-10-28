-- FacturaSV - Migración 012: módulo 10, tipos DTE 05, 06, 11 y 14
-- Agrega el documento relacionado de las notas, los datos de exportación y
-- el importe de compra del sujeto excluido.

USE facturasv2;
SET NAMES utf8mb4;

ALTER TABLE documentos
    ADD COLUMN documento_relacionado_id BIGINT UNSIGNED NULL AFTER cliente_id,
    ADD COLUMN export_cod_pais CHAR(2) NULL AFTER documento_relacionado_id,
    ADD COLUMN export_nombre_pais VARCHAR(50) NULL AFTER export_cod_pais,
    ADD COLUMN export_tipo_persona TINYINT UNSIGNED NULL AFTER export_nombre_pais,
    ADD COLUMN export_complemento VARCHAR(200) NULL AFTER export_tipo_persona,
    ADD COLUMN export_tipo_item TINYINT UNSIGNED NULL AFTER export_complemento,
    ADD COLUMN export_incoterms VARCHAR(2) NULL AFTER export_tipo_item,
    ADD COLUMN export_desc_incoterms VARCHAR(150) NULL AFTER export_incoterms,
    ADD COLUMN export_flete DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER export_desc_incoterms,
    ADD COLUMN export_seguro DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER export_flete,
    ADD COLUMN export_no_onerosas DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER export_seguro,
    ADD KEY idx_documentos_relacionado (tenant_id, documento_relacionado_id);

ALTER TABLE documentos
    ADD CONSTRAINT fk_documentos_relacionado
        FOREIGN KEY (tenant_id, documento_relacionado_id)
        REFERENCES documentos (tenant_id, id) ON DELETE RESTRICT;

ALTER TABLE documento_items
    ADD COLUMN compra DECIMAL(15,8) NULL AFTER iva_item,
    ADD CONSTRAINT chk_documento_items_compra CHECK (compra IS NULL OR compra >= 0);

INSERT INTO schema_versions (version, description)
VALUES (12, 'Módulo 10: Nota de Crédito, Nota de Débito, Factura de Exportación y Factura de Sujeto Excluido')
ON DUPLICATE KEY UPDATE description = VALUES(description);
