-- FacturaSV - Migración 004: Clientes por empresa

USE facturasv2;
SET NAMES utf8mb4;

ALTER TABLE mh_municipios
    ADD UNIQUE KEY uq_mh_municipios_tenant_id_departamento (tenant_id, id, departamento_id);

CREATE TABLE IF NOT EXISTS clientes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tipo_documento ENUM('NIT', 'DUI') NOT NULL,
    numero_documento VARCHAR(17) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    nombre_comercial VARCHAR(150) NULL,
    actividad_economica VARCHAR(150) NULL,
    direccion VARCHAR(255) NOT NULL,
    departamento_id BIGINT UNSIGNED NOT NULL,
    municipio_id BIGINT UNSIGNED NOT NULL,
    telefono VARCHAR(12) NULL,
    email VARCHAR(150) NULL,
    tipo_contribuyente ENUM('GRAN_CONTRIBUYENTE', 'SUJETO_EXCLUIDO', 'OTRO') NOT NULL DEFAULT 'OTRO',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_tenant_documento (tenant_id, tipo_documento, numero_documento),
    KEY idx_clientes_tenant_nombre (tenant_id, nombre),
    KEY idx_clientes_tenant_estado (tenant_id, activo),
    KEY idx_clientes_tenant_ubicacion (tenant_id, departamento_id, municipio_id),
    CONSTRAINT fk_clientes_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_clientes_departamento
        FOREIGN KEY (tenant_id, departamento_id)
        REFERENCES mh_departamentos (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_clientes_municipio
        FOREIGN KEY (tenant_id, municipio_id, departamento_id)
        REFERENCES mh_municipios (tenant_id, id, departamento_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES (4, 'Módulo 3: clientes, ubicación y documentos únicos por empresa')
ON DUPLICATE KEY UPDATE description = VALUES(description);
