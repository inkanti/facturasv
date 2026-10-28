-- FacturaSV - Migración 013: superadministración global
-- Convierte a users en una tabla global (tenant_id nullable), agrega el rol
-- SUPERADMIN y crea la cuenta inicial del superadministrador.

USE facturasv2;
SET NAMES utf8mb4;

ALTER TABLE users
    DROP FOREIGN KEY fk_users_tenant;

ALTER TABLE users
    MODIFY tenant_id BIGINT UNSIGNED NULL,
    MODIFY rol ENUM('SUPERADMIN','ADMIN','VENDEDOR','CONTADOR') NOT NULL;

ALTER TABLE users
    ADD CONSTRAINT fk_users_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE users
    ADD CONSTRAINT chk_users_superadmin CHECK (
        (rol = 'SUPERADMIN' AND tenant_id IS NULL)
        OR (rol <> 'SUPERADMIN' AND tenant_id IS NOT NULL)
    );

INSERT INTO users (tenant_id, nombre, usuario, email, password_hash, rol, activo)
VALUES (
    NULL,
    'Superadministrador',
    'superadmin',
    'superadmin@facturasv.local',
    '$2y$10$1yxnTYSUUKrQH0SGQIooBOQe9uN.maZhBewkmMnxwfOtoFFOJMB8C',
    'SUPERADMIN',
    1
)
ON DUPLICATE KEY UPDATE
    rol = VALUES(rol),
    tenant_id = NULL,
    activo = VALUES(activo),
    password_hash = VALUES(password_hash);

INSERT INTO schema_versions (version, description)
VALUES (13, 'Módulo 11: superadministración, empresas y usuarios centralizados')
ON DUPLICATE KEY UPDATE description = VALUES(description);
