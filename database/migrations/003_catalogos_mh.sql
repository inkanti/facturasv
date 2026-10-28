-- FacturaSV - Migración 003: Catálogos MH por empresa
-- TODO-MH: (pendiente documento MH) Confirmar códigos y nombres con los catálogos oficiales vigentes.

USE facturasv2;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mh_tipos_documento (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(3) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mh_tipos_documento_tenant_codigo (tenant_id, codigo),
    CONSTRAINT fk_mh_tipos_documento_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_tipos_establecimiento (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(3) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mh_tipos_establecimiento_tenant_codigo (tenant_id, codigo),
    CONSTRAINT fk_mh_tipos_establecimiento_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_tipos_item (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(3) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mh_tipos_item_tenant_codigo (tenant_id, codigo),
    CONSTRAINT fk_mh_tipos_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_unidades_medida (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(3) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mh_unidades_medida_tenant_codigo (tenant_id, codigo),
    CONSTRAINT fk_mh_unidades_medida_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_condiciones_operacion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(3) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mh_condiciones_operacion_tenant_codigo (tenant_id, codigo),
    CONSTRAINT fk_mh_condiciones_operacion_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_formas_pago (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(3) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mh_formas_pago_tenant_codigo (tenant_id, codigo),
    CONSTRAINT fk_mh_formas_pago_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_departamentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(2) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mh_departamentos_tenant_codigo (tenant_id, codigo),
    UNIQUE KEY uq_mh_departamentos_tenant_id (tenant_id, id),
    CONSTRAINT fk_mh_departamentos_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_municipios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    departamento_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(2) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mh_municipios_departamento_codigo (tenant_id, departamento_id, codigo),
    KEY idx_mh_municipios_departamento (tenant_id, departamento_id, activo),
    CONSTRAINT fk_mh_municipios_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mh_municipios_departamento FOREIGN KEY (tenant_id, departamento_id)
        REFERENCES mh_departamentos (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES (3, 'Módulo 2: catálogos MH independientes por empresa')
ON DUPLICATE KEY UPDATE description = VALUES(description);
