-- FacturaSV - Módulo 13: contabilidad
-- Catálogo de cuentas, configuración de cuentas automáticas, asientos,
-- partidas y secuencia de numeración por empresa.
-- Compatible con MySQL 8.0+ y MariaDB 10.4+.

USE facturasv2;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS contabilidad_cuentas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(12) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    tipo ENUM('ACTIVO', 'PASIVO', 'PATRIMONIO', 'INGRESO', 'COSTO', 'GASTO') NOT NULL,
    naturaleza ENUM('DEUDORA', 'ACREEDORA') NOT NULL,
    permite_movimiento TINYINT(1) NOT NULL DEFAULT 1,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contabilidad_cuentas_tenant_codigo (tenant_id, codigo),
    UNIQUE KEY uq_contabilidad_cuentas_tenant_id (tenant_id, id),
    KEY idx_contabilidad_cuentas_tenant_activa (tenant_id, activa),
    CONSTRAINT fk_contabilidad_cuentas_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT chk_contabilidad_cuentas_codigo CHECK (codigo REGEXP '^[0-9]{1,12}$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contabilidad_config (
    tenant_id BIGINT UNSIGNED NOT NULL,
    cuenta_caja_id BIGINT UNSIGNED NOT NULL,
    cuenta_cxc_id BIGINT UNSIGNED NOT NULL,
    cuenta_ventas_gravadas_id BIGINT UNSIGNED NOT NULL,
    cuenta_ventas_exentas_id BIGINT UNSIGNED NOT NULL,
    cuenta_ventas_no_sujetas_id BIGINT UNSIGNED NOT NULL,
    cuenta_exportaciones_id BIGINT UNSIGNED NOT NULL,
    cuenta_devoluciones_id BIGINT UNSIGNED NOT NULL,
    cuenta_iva_debito_id BIGINT UNSIGNED NOT NULL,
    cuenta_iva_retenido_por_cobrar_id BIGINT UNSIGNED NOT NULL,
    cuenta_compras_id BIGINT UNSIGNED NOT NULL,
    cuenta_cxp_id BIGINT UNSIGNED NOT NULL,
    cuenta_retencion_renta_por_pagar_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_contabilidad_config_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_contabilidad_config_caja
        FOREIGN KEY (tenant_id, cuenta_caja_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_cxc
        FOREIGN KEY (tenant_id, cuenta_cxc_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_ventas_gravadas
        FOREIGN KEY (tenant_id, cuenta_ventas_gravadas_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_ventas_exentas
        FOREIGN KEY (tenant_id, cuenta_ventas_exentas_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_ventas_no_sujetas
        FOREIGN KEY (tenant_id, cuenta_ventas_no_sujetas_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_exportaciones
        FOREIGN KEY (tenant_id, cuenta_exportaciones_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_devoluciones
        FOREIGN KEY (tenant_id, cuenta_devoluciones_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_iva_debito
        FOREIGN KEY (tenant_id, cuenta_iva_debito_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_iva_retenido_cobrar
        FOREIGN KEY (tenant_id, cuenta_iva_retenido_por_cobrar_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_compras
        FOREIGN KEY (tenant_id, cuenta_compras_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_cxp
        FOREIGN KEY (tenant_id, cuenta_cxp_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_config_retencion_renta
        FOREIGN KEY (tenant_id, cuenta_retencion_renta_por_pagar_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contabilidad_secuencias (
    tenant_id BIGINT UNSIGNED NOT NULL,
    numero_actual BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_contabilidad_secuencias_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT chk_contabilidad_secuencias_numero CHECK (numero_actual >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contabilidad_asientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    numero BIGINT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    concepto VARCHAR(300) NOT NULL,
    tipo ENUM('MANUAL', 'VENTA', 'NOTA_CREDITO', 'NOTA_DEBITO', 'EXPORTACION',
              'SUJETO_EXCLUIDO', 'INVALIDACION', 'AJUSTE') NOT NULL DEFAULT 'MANUAL',
    estado ENUM('CONTABILIZADO', 'ANULADO') NOT NULL DEFAULT 'CONTABILIZADO',
    documento_id BIGINT UNSIGNED NULL,
    total_debe DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_haber DECIMAL(15,2) NOT NULL DEFAULT 0,
    motivo_anulacion VARCHAR(300) NULL,
    anulado_at TIMESTAMP NULL DEFAULT NULL,
    anulado_por BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contabilidad_asientos_tenant_numero (tenant_id, numero),
    UNIQUE KEY uq_contabilidad_asientos_tenant_id (tenant_id, id),
    UNIQUE KEY uq_contabilidad_asientos_documento (tenant_id, documento_id, tipo),
    KEY idx_contabilidad_asientos_tenant_fecha (tenant_id, fecha, id),
    KEY idx_contabilidad_asientos_tenant_tipo (tenant_id, tipo, estado),
    KEY idx_contabilidad_asientos_tenant_estado (tenant_id, estado, fecha),
    CONSTRAINT fk_contabilidad_asientos_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_asientos_documento
        FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_contabilidad_asientos_usuario
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_asientos_anulado_por
        FOREIGN KEY (anulado_por) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_contabilidad_asientos_totales CHECK (total_debe > 0 AND total_debe = total_haber)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contabilidad_partidas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    asiento_id BIGINT UNSIGNED NOT NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    cuenta_id BIGINT UNSIGNED NOT NULL,
    descripcion VARCHAR(200) NOT NULL,
    debe DECIMAL(15,2) NOT NULL DEFAULT 0,
    haber DECIMAL(15,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contabilidad_partidas_asiento_linea (asiento_id, linea),
    KEY idx_contabilidad_partidas_tenant_cuenta (tenant_id, cuenta_id),
    KEY idx_contabilidad_partidas_tenant_asiento (tenant_id, asiento_id),
    CONSTRAINT fk_contabilidad_partidas_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contabilidad_partidas_asiento
        FOREIGN KEY (tenant_id, asiento_id) REFERENCES contabilidad_asientos (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_contabilidad_partidas_cuenta
        FOREIGN KEY (tenant_id, cuenta_id) REFERENCES contabilidad_cuentas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_contabilidad_partidas_monto CHECK (
        debe >= 0 AND haber >= 0
        AND (debe > 0 OR haber > 0)
        AND (debe = 0 OR haber = 0)
    )
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES (14, 'Módulo 13: contabilidad, catálogo de cuentas, asientos, libros y estados financieros')
ON DUPLICATE KEY UPDATE description = VALUES(description);
