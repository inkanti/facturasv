-- FacturaSV - Migración 006: Emisión y transmisión simulada de DTE
USE facturasv2;
SET NAMES utf8mb4;

ALTER TABLE tenant_config
    ADD COLUMN distrito_emisor CHAR(2) NOT NULL DEFAULT '14' AFTER moneda,
    ADD COLUMN establecimiento VARCHAR(8) NOT NULL DEFAULT 'M001P001' AFTER distrito_emisor;

ALTER TABLE mh_unidades_medida
    MODIFY codigo VARCHAR(3) NOT NULL;
ALTER TABLE clientes
    ADD UNIQUE KEY uq_clientes_tenant_id (tenant_id, id);
ALTER TABLE productos
    ADD UNIQUE KEY uq_productos_tenant_id (tenant_id, id);

CREATE TABLE IF NOT EXISTS documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tipo_dte CHAR(2) NOT NULL,
    numero_control CHAR(31) NULL,
    codigo_generacion CHAR(36) NULL,
    cliente_id BIGINT UNSIGNED NOT NULL,
    fecha_emision DATE NOT NULL,
    hora_emision TIME NULL,
    condicion_operacion_id BIGINT UNSIGNED NOT NULL,
    forma_pago_id BIGINT UNSIGNED NOT NULL,
    dias_credito SMALLINT UNSIGNED NULL,
    emisor_distrito CHAR(2) NOT NULL,
    receptor_distrito CHAR(2) NOT NULL,
    aplicar_iva_retenido TINYINT(1) NOT NULL DEFAULT 0,
    observaciones VARCHAR(3000) NULL,
    estado ENUM('BORRADOR','EMITIDA','PROCESADA','RECHAZADA','INVALIDADA') NOT NULL DEFAULT 'BORRADOR',
    total_gravada DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_exenta DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_no_suj DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_descuento DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_iva DECIMAL(15,2) NOT NULL DEFAULT 0,
    iva_rete1 DECIMAL(15,2) NOT NULL DEFAULT 0,
    rete_renta DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_pagar DECIMAL(15,2) NOT NULL DEFAULT 0,
    dte_json MEDIUMTEXT NULL,
    firma_digital MEDIUMTEXT NULL,
    sello_recepcion CHAR(40) NULL,
    procesado_at TIMESTAMP NULL DEFAULT NULL,
    invalidacion_json MEDIUMTEXT NULL,
    motivo_invalidacion VARCHAR(200) NULL,
    invalidado_at TIMESTAMP NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documentos_tenant_id (tenant_id, id),
    UNIQUE KEY uq_documentos_tenant_control (tenant_id, tipo_dte, numero_control),
    UNIQUE KEY uq_documentos_tenant_generacion (tenant_id, codigo_generacion),
    KEY idx_documentos_tenant_fecha (tenant_id, fecha_emision),
    KEY idx_documentos_tenant_estado (tenant_id, estado),
    KEY idx_documentos_tenant_cliente (tenant_id, cliente_id),
    CONSTRAINT fk_documentos_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_cliente FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_condicion FOREIGN KEY (condicion_operacion_id) REFERENCES mh_condiciones_operacion(id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_pago FOREIGN KEY (forma_pago_id) REFERENCES mh_formas_pago(id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_usuario FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_documentos_credito CHECK (dias_credito IS NULL OR dias_credito > 0),
    CONSTRAINT chk_documentos_distritos CHECK (emisor_distrito REGEXP '^[0-9]{2}$' AND receptor_distrito REGEXP '^[0-9]{2}$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documento_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NULL,
    num_item SMALLINT UNSIGNED NOT NULL,
    codigo VARCHAR(30) NULL,
    descripcion VARCHAR(255) NOT NULL,
    tipo ENUM('BIEN','SERVICIO') NOT NULL,
    unidad_medida_id BIGINT UNSIGNED NOT NULL,
    unidad_codigo VARCHAR(3) NOT NULL,
    cantidad DECIMAL(15,8) NOT NULL,
    precio_unitario DECIMAL(15,8) NOT NULL,
    descuento DECIMAL(15,8) NOT NULL DEFAULT 0,
    tratamiento_iva ENUM('GRAVADO','EXENTO','NO_SUJETO') NOT NULL,
    venta_gravada DECIMAL(15,8) NOT NULL DEFAULT 0,
    venta_exenta DECIMAL(15,8) NOT NULL DEFAULT 0,
    venta_no_suj DECIMAL(15,8) NOT NULL DEFAULT 0,
    iva_item DECIMAL(15,8) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documento_items_numero (documento_id, num_item),
    KEY idx_documento_items_tenant_documento (tenant_id, documento_id),
    KEY idx_documento_items_producto (tenant_id, producto_id),
    CONSTRAINT fk_documento_items_documento FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos(tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_documento_items_producto FOREIGN KEY (tenant_id, producto_id) REFERENCES productos(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_documento_items_unidad FOREIGN KEY (tenant_id, unidad_medida_id) REFERENCES mh_unidades_medida(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_documento_items_cantidad CHECK (cantidad > 0),
    CONSTRAINT chk_documento_items_precio CHECK (precio_unitario >= 0),
    CONSTRAINT chk_documento_items_descuento CHECK (descuento >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_transmissions_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    accion ENUM('FIRMA','TRANSMISION','CONSULTA','INVALIDACION') NOT NULL,
    request_json MEDIUMTEXT NULL,
    response_json MEDIUMTEXT NULL,
    estado VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mh_log_tenant_documento (tenant_id, documento_id, id),
    CONSTRAINT fk_mh_log_documento FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_mh_log_usuario FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO document_sequences (tenant_id, tipo_dte, establecimiento, prefijo, numero_inicial, numero_actual)
SELECT t.id, x.tipo_dte, c.establecimiento, 'DTE', 1, 0
FROM tenants t
INNER JOIN tenant_config c ON c.tenant_id = t.id
JOIN (
    SELECT '01' AS tipo_dte UNION ALL SELECT '03' UNION ALL SELECT '05'
    UNION ALL SELECT '06' UNION ALL SELECT '11' UNION ALL SELECT '14'
) x
WHERE 1 = 1
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT INTO schema_versions (version, description)
VALUES (6, 'Módulo 5: emisión DTE, correlativos, firma y transmisión simulada')
ON DUPLICATE KEY UPDATE description = VALUES(description);
