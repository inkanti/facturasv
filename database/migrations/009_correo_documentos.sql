-- FacturaSV - Migración 009: envío de documentos por correo electrónico
-- Registra cada intento de envío del DTE (PDF y JSON) al cliente, con el
-- transporte utilizado, el resultado y el usuario que lo solicitó.

USE facturasv2;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS documento_correos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    destinatario VARCHAR(254) NOT NULL,
    copia VARCHAR(254) NULL,
    asunto VARCHAR(255) NOT NULL,
    transporte VARCHAR(20) NOT NULL,
    estado ENUM('ENVIADO', 'SIMULADO', 'ERROR', 'OMITIDO') NOT NULL,
    error VARCHAR(500) NULL,
    adjuntos VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_correos_tenant_documento (tenant_id, documento_id, id),
    CONSTRAINT fk_correos_documento
        FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos (tenant_id, id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_correos_usuario
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES (9, 'Módulo 7: envío de documentos por correo y reportes')
ON DUPLICATE KEY UPDATE description = VALUES(description);
