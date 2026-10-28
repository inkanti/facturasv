-- FacturaSV - Migración 005: Productos y servicios por empresa

USE facturasv2;
SET NAMES utf8mb4;

ALTER TABLE mh_unidades_medida
    ADD UNIQUE KEY uq_mh_unidades_medida_tenant_id (tenant_id, id);

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

INSERT INTO schema_versions (version, description)
VALUES (5, 'Módulo 4: productos, servicios, IVA y existencias por empresa')
ON DUPLICATE KEY UPDATE description = VALUES(description);
