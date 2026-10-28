-- FacturaSV - Módulo 14: inventario básico
-- Libro de movimientos por producto, existencias calculadas y configuración
-- de la fecha de inicio del control. No incluye compras ni costeo.
-- Compatible con MySQL 8.0+ y MariaDB 10.4+.

USE facturasv2;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

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
VALUES (15, 'Módulo 14: inventario básico, movimientos, existencias y kardex')
ON DUPLICATE KEY UPDATE description = VALUES(description);
