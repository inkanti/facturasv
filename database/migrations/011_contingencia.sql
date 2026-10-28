-- FacturaSV - Migración 011: contingencia de DTE
-- Crea los eventos de contingencia del esquema MH v4, su detalle de documentos
-- y agrega a los DTE la marca de transmisión por contingencia.

USE facturasv2;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS eventos_contingencia (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo_generacion CHAR(36) NOT NULL,
    estado ENUM('BORRADOR','PROCESADO','RECHAZADO') NOT NULL DEFAULT 'BORRADOR',
    f_transmision DATE NOT NULL,
    h_transmision TIME NOT NULL,
    f_inicio DATE NOT NULL,
    f_fin DATE NOT NULL,
    h_inicio TIME NOT NULL,
    h_fin TIME NOT NULL,
    tipo_contingencia TINYINT UNSIGNED NOT NULL,
    motivo_contingencia VARCHAR(500) NULL,
    nombre_responsable VARCHAR(100) NOT NULL,
    tipo_doc_responsable CHAR(2) NOT NULL,
    numero_doc_responsable VARCHAR(25) NOT NULL,
    tipo_establecimiento CHAR(2) NOT NULL,
    evento_json MEDIUMTEXT NULL,
    response_json MEDIUMTEXT NULL,
    sello_recepcion CHAR(40) NULL,
    error VARCHAR(500) NULL,
    procesado_at TIMESTAMP NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eventos_contingencia_tenant_id (tenant_id, id),
    UNIQUE KEY uq_eventos_contingencia_generacion (tenant_id, codigo_generacion),
    KEY idx_eventos_contingencia_tenant_estado (tenant_id, estado, id),
    CONSTRAINT fk_eventos_contingencia_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_eventos_contingencia_user
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_eventos_contingencia_tipo CHECK (tipo_contingencia BETWEEN 1 AND 5),
    CONSTRAINT chk_eventos_contingencia_fechas CHECK (f_inicio <= f_fin)
) ENGINE=InnoDB;

ALTER TABLE documentos
    ADD COLUMN tipo_operacion TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER tipo_dte,
    ADD COLUMN tipo_contingencia TINYINT UNSIGNED NULL AFTER tipo_operacion,
    ADD COLUMN motivo_contingencia VARCHAR(500) NULL AFTER tipo_contingencia,
    ADD COLUMN contingencia_evento_id BIGINT UNSIGNED NULL AFTER estado,
    ADD KEY idx_documentos_tenant_contingencia (tenant_id, tipo_operacion, estado, contingencia_evento_id),
    ADD CONSTRAINT fk_documentos_contingencia_evento
        FOREIGN KEY (tenant_id, contingencia_evento_id)
        REFERENCES eventos_contingencia (tenant_id, id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_documentos_contingencia CHECK (
        (tipo_operacion = 1 AND tipo_contingencia IS NULL
            AND motivo_contingencia IS NULL AND contingencia_evento_id IS NULL)
        OR (tipo_operacion = 2 AND tipo_contingencia IS NOT NULL
            AND tipo_contingencia BETWEEN 1 AND 5)
    );

CREATE TABLE IF NOT EXISTS evento_contingencia_documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    evento_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NOT NULL,
    no_item SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_evento_contingencia_documento (evento_id, documento_id),
    UNIQUE KEY uq_evento_contingencia_no_item (evento_id, no_item),
    UNIQUE KEY uq_evento_contingencia_documento_unico (tenant_id, documento_id),
    KEY idx_evento_contingencia_documentos_tenant (tenant_id, evento_id),
    CONSTRAINT fk_evento_contingencia_docs_evento
        FOREIGN KEY (tenant_id, evento_id) REFERENCES eventos_contingencia (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_evento_contingencia_docs_documento
        FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_evento_contingencia_no_item CHECK (no_item BETWEEN 1 AND 1000)
) ENGINE=InnoDB;

ALTER TABLE mh_transmissions_log
    DROP FOREIGN KEY fk_mh_log_documento;

ALTER TABLE mh_transmissions_log
    MODIFY documento_id BIGINT UNSIGNED NULL,
    ADD COLUMN evento_contingencia_id BIGINT UNSIGNED NULL AFTER documento_id,
    MODIFY accion ENUM('FIRMA','TRANSMISION','CONSULTA','INVALIDACION','CONTINGENCIA') NOT NULL,
    ADD KEY idx_mh_log_tenant_evento (tenant_id, evento_contingencia_id, id),
    ADD CONSTRAINT fk_mh_log_documento
        FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos (tenant_id, id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_mh_log_evento
        FOREIGN KEY (tenant_id, evento_contingencia_id) REFERENCES eventos_contingencia (tenant_id, id) ON DELETE RESTRICT;

INSERT INTO schema_versions (version, description)
VALUES (11, 'Módulo 9: eventos de contingencia, emisión en contingencia y transmisión diferida')
ON DUPLICATE KEY UPDATE description = VALUES(description);
