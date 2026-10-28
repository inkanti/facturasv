-- FacturaSV - Migración 010: configuración de empresa, emisor y auditoría
-- Agrega departamento y municipio configurables del emisor (antes fijos por
-- TODO-MH) y una bitácora de cambios de configuración.

USE facturasv2;
SET NAMES utf8mb4;

ALTER TABLE tenant_config
    ADD COLUMN departamento_emisor CHAR(2) NOT NULL DEFAULT '06' AFTER distrito_emisor,
    ADD COLUMN municipio_emisor CHAR(2) NOT NULL DEFAULT '04' AFTER departamento_emisor;

CREATE TABLE IF NOT EXISTS configuracion_cambios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    seccion VARCHAR(30) NOT NULL,
    detalle MEDIUMTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_config_cambios_tenant (tenant_id, id),
    CONSTRAINT fk_config_cambios_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_config_cambios_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES (10, 'Módulo 8: configuración de empresa, emisor, numeración, usuarios y auditoría')
ON DUPLICATE KEY UPDATE description = VALUES(description);
