-- FacturaSV - Módulo 1: proyecto base, autenticación y esquema multiempresa
-- Compatible con MySQL 8.0+ y MariaDB 10.4+

CREATE DATABASE IF NOT EXISTS facturasv2
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE facturasv2;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_versions (
    version INT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nit CHAR(17) NOT NULL,
    razon_social VARCHAR(150) NOT NULL,
    nombre_comercial VARCHAR(150) NOT NULL,
    codigo_actividad VARCHAR(10) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    telefono VARCHAR(30) NOT NULL,
    email VARCHAR(150) NOT NULL,
    numero_registro_mh VARCHAR(30) NOT NULL,
    logo_path VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_nit (nit),
    KEY idx_tenants_activo (activo),
    CONSTRAINT chk_tenants_nit CHECK (nit REGEXP '^[0-9]{4}-[0-9]{6}-[0-9]{3}-[0-9]$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NULL,
    nombre VARCHAR(120) NOT NULL,
    usuario VARCHAR(50) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('SUPERADMIN', 'ADMIN', 'VENDEDOR', 'CONTADOR') NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_usuario (usuario),
    UNIQUE KEY uq_users_tenant_email (tenant_id, email),
    KEY idx_users_tenant_activo (tenant_id, activo),
    CONSTRAINT fk_users_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_users_superadmin CHECK (
        (rol = 'SUPERADMIN' AND tenant_id IS NULL)
        OR (rol <> 'SUPERADMIN' AND tenant_id IS NOT NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_config (
    tenant_id BIGINT UNSIGNED NOT NULL,
    mh_mode ENUM('SIMULACION', 'PRODUCCION') NOT NULL DEFAULT 'SIMULACION',
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/El_Salvador',
    moneda CHAR(3) NOT NULL DEFAULT 'USD',
    distrito_emisor CHAR(2) NOT NULL DEFAULT '14',
    departamento_emisor CHAR(2) NOT NULL DEFAULT '06',
    municipio_emisor CHAR(2) NOT NULL DEFAULT '04',
    establecimiento VARCHAR(8) NOT NULL DEFAULT 'M001P001',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tenant_config_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_sequences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tipo_dte CHAR(2) NOT NULL,
    establecimiento VARCHAR(10) NOT NULL,
    prefijo VARCHAR(20) NOT NULL DEFAULT '',
    numero_inicial BIGINT UNSIGNED NOT NULL DEFAULT 1,
    numero_actual BIGINT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sequences_tenant_documento (tenant_id, tipo_dte, establecimiento),
    KEY idx_sequences_tenant_activo (tenant_id, activo),
    CONSTRAINT fk_sequences_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_sequences_inicial CHECK (numero_inicial > 0),
    CONSTRAINT chk_sequences_actual CHECK (numero_actual >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    exitoso TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auth_attempts_identifier_date (identifier_hash, attempted_at),
    KEY idx_auth_attempts_ip_date (ip_hash, attempted_at)
) ENGINE=InnoDB;

-- Módulo 2: catálogos MH por empresa
-- TODO-MH: (pendiente documento MH) Confirmar códigos y nombres con los catálogos oficiales vigentes.

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
    UNIQUE KEY uq_mh_unidades_medida_tenant_id (tenant_id, id),
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
    UNIQUE KEY uq_mh_municipios_tenant_id_departamento (tenant_id, id, departamento_id),
    KEY idx_mh_municipios_departamento (tenant_id, departamento_id, activo),
    CONSTRAINT fk_mh_municipios_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mh_municipios_departamento FOREIGN KEY (tenant_id, departamento_id)
        REFERENCES mh_departamentos (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Módulo 3: clientes
CREATE TABLE IF NOT EXISTS clientes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tipo_documento ENUM('NIT', 'DUI', 'NINGUNO') NOT NULL,
    numero_documento VARCHAR(17) NULL,
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
    UNIQUE KEY uq_clientes_tenant_id (tenant_id, id),
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

-- Módulo 4: productos y servicios
CREATE TABLE IF NOT EXISTS productos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(30) NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    tipo ENUM('BIEN', 'SERVICIO') NOT NULL,
    unidad_medida_id BIGINT UNSIGNED NOT NULL,
    precio_unitario DECIMAL(15,6) NOT NULL,
    tratamiento_iva ENUM('GRAVADO', 'EXENTO', 'NO_SUJETO') NOT NULL DEFAULT 'GRAVADO',
    control_stock TINYINT(1) NOT NULL DEFAULT 0,
    stock DECIMAL(15,4) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_tenant_codigo (tenant_id, codigo),
    UNIQUE KEY uq_productos_tenant_id (tenant_id, id),
    KEY idx_productos_tenant_descripcion (tenant_id, descripcion),
    KEY idx_productos_tenant_tipo_estado (tenant_id, tipo, activo),
    KEY idx_productos_tenant_unidad (tenant_id, unidad_medida_id),
    CONSTRAINT fk_productos_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_productos_unidad_medida
        FOREIGN KEY (tenant_id, unidad_medida_id)
        REFERENCES mh_unidades_medida (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_productos_precio CHECK (precio_unitario >= 0),
    CONSTRAINT chk_productos_stock CHECK (stock IS NULL OR stock >= 0),
    CONSTRAINT chk_productos_control_stock CHECK (
        (control_stock = 0 AND stock IS NULL)
        OR (control_stock = 1 AND stock IS NOT NULL)
    )
) ENGINE=InnoDB;

-- Módulo 9: eventos de contingencia (esquema MH v4)
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

-- Módulo 5: emisión, transmisión e invalidación de DTE
CREATE TABLE IF NOT EXISTS documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tipo_dte CHAR(2) NOT NULL,
    tipo_operacion TINYINT UNSIGNED NOT NULL DEFAULT 1,
    tipo_contingencia TINYINT UNSIGNED NULL,
    motivo_contingencia VARCHAR(500) NULL,
    numero_control CHAR(31) NULL,
    codigo_generacion CHAR(36) NULL,
    cliente_id BIGINT UNSIGNED NOT NULL,
    documento_relacionado_id BIGINT UNSIGNED NULL,
    export_cod_pais CHAR(2) NULL,
    export_nombre_pais VARCHAR(50) NULL,
    export_tipo_persona TINYINT UNSIGNED NULL,
    export_complemento VARCHAR(200) NULL,
    export_tipo_item TINYINT UNSIGNED NULL,
    export_incoterms VARCHAR(2) NULL,
    export_desc_incoterms VARCHAR(150) NULL,
    export_flete DECIMAL(15,2) NOT NULL DEFAULT 0,
    export_seguro DECIMAL(15,2) NOT NULL DEFAULT 0,
    export_no_onerosas DECIMAL(15,2) NOT NULL DEFAULT 0,
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
    contingencia_evento_id BIGINT UNSIGNED NULL,
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
    KEY idx_documentos_relacionado (tenant_id, documento_relacionado_id),
    KEY idx_documentos_tenant_contingencia (tenant_id, tipo_operacion, estado, contingencia_evento_id),
    CONSTRAINT fk_documentos_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_cliente FOREIGN KEY (tenant_id, cliente_id) REFERENCES clientes(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_relacionado
        FOREIGN KEY (tenant_id, documento_relacionado_id)
        REFERENCES documentos (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_condicion FOREIGN KEY (condicion_operacion_id) REFERENCES mh_condiciones_operacion(id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_pago FOREIGN KEY (forma_pago_id) REFERENCES mh_formas_pago(id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_usuario FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_documentos_contingencia_evento
        FOREIGN KEY (tenant_id, contingencia_evento_id)
        REFERENCES eventos_contingencia (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_documentos_credito CHECK (dias_credito IS NULL OR dias_credito > 0),
    CONSTRAINT chk_documentos_distritos CHECK (emisor_distrito REGEXP '^[0-9]{2}$' AND receptor_distrito REGEXP '^[0-9]{2}$'),
    CONSTRAINT chk_documentos_contingencia CHECK (
        (tipo_operacion = 1 AND tipo_contingencia IS NULL
            AND motivo_contingencia IS NULL AND contingencia_evento_id IS NULL)
        OR (tipo_operacion = 2 AND tipo_contingencia IS NOT NULL
            AND tipo_contingencia BETWEEN 1 AND 5)
    )
) ENGINE=InnoDB;

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
    compra DECIMAL(15,8) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documento_items_numero (documento_id, num_item),
    KEY idx_documento_items_tenant_documento (tenant_id, documento_id),
    KEY idx_documento_items_producto (tenant_id, producto_id),
    CONSTRAINT fk_documento_items_documento FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos(tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_documento_items_producto FOREIGN KEY (tenant_id, producto_id) REFERENCES productos(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_documento_items_unidad FOREIGN KEY (tenant_id, unidad_medida_id) REFERENCES mh_unidades_medida(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_documento_items_cantidad CHECK (cantidad > 0),
    CONSTRAINT chk_documento_items_precio CHECK (precio_unitario >= 0),
    CONSTRAINT chk_documento_items_descuento CHECK (descuento >= 0),
    CONSTRAINT chk_documento_items_compra CHECK (compra IS NULL OR compra >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mh_transmissions_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    documento_id BIGINT UNSIGNED NULL,
    evento_contingencia_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    accion ENUM('FIRMA','TRANSMISION','CONSULTA','INVALIDACION','CONTINGENCIA') NOT NULL,
    request_json MEDIUMTEXT NULL,
    response_json MEDIUMTEXT NULL,
    estado VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mh_log_tenant_documento (tenant_id, documento_id, id),
    KEY idx_mh_log_tenant_evento (tenant_id, evento_contingencia_id, id),
    CONSTRAINT fk_mh_log_documento FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_mh_log_evento FOREIGN KEY (tenant_id, evento_contingencia_id) REFERENCES eventos_contingencia(tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_mh_log_usuario FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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

-- Módulo 13: contabilidad
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

-- Módulo 14: inventario básico
CREATE TABLE IF NOT EXISTS inventario_config (
    tenant_id BIGINT UNSIGNED NOT NULL,
    fecha_inicio DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_inventario_config_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventario_movimientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    tipo ENUM('ENTRADA', 'SALIDA', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO') NOT NULL,
    cantidad DECIMAL(15,4) NOT NULL,
    motivo VARCHAR(200) NOT NULL,
    documento_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventario_movimientos_tenant_id (tenant_id, id),
    UNIQUE KEY uq_inventario_documento_producto_tipo (tenant_id, documento_id, producto_id, tipo),
    KEY idx_inventario_tenant_producto_fecha (tenant_id, producto_id, fecha, id),
    KEY idx_inventario_tenant_fecha (tenant_id, fecha, id),
    KEY idx_inventario_tenant_documento (tenant_id, documento_id),
    CONSTRAINT fk_inventario_movimientos_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventario_movimientos_producto
        FOREIGN KEY (tenant_id, producto_id) REFERENCES productos (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventario_movimientos_documento
        FOREIGN KEY (tenant_id, documento_id) REFERENCES documentos (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_inventario_movimientos_usuario
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_inventario_movimientos_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB;

INSERT INTO schema_versions (version, description)
VALUES
    (1, 'Módulo 1: base multiempresa, usuarios, configuración y autenticación'),
    (2, 'Autenticación sin NIT y nombre de usuario único global'),
    (3, 'Módulo 2: catálogos MH independientes por empresa'),
    (4, 'Módulo 3: clientes, ubicación y documentos únicos por empresa'),
    (5, 'Módulo 4: productos, servicios, IVA y existencias por empresa'),
    (6, 'Módulo 5: emisión DTE, correlativos, firma y transmisión simulada'),
    (7, 'Módulo 5: catálogos MH oficiales para unidades y formas de pago'),
    (8, 'Módulo 6: clientes sin documento para Factura y logo del emisor para el PDF'),
    (9, 'Módulo 7: envío de documentos por correo y reportes'),
    (10, 'Módulo 8: configuración de empresa, emisor, numeración, usuarios y auditoría'),
    (11, 'Módulo 9: eventos de contingencia, emisión en contingencia y transmisión diferida'),
    (12, 'Módulo 10: Nota de Crédito, Nota de Débito, Factura de Exportación y Factura de Sujeto Excluido'),
    (13, 'Módulo 11: superadministración, empresas y usuarios centralizados'),
    (14, 'Módulo 13: contabilidad, catálogo de cuentas, asientos, libros y estados financieros'),
    (15, 'Módulo 14: inventario básico, movimientos, existencias y kardex')
ON DUPLICATE KEY UPDATE description = VALUES(description);
