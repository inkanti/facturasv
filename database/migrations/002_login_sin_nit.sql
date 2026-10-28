-- FacturaSV - Migración 002
-- Autenticación únicamente con usuario y contraseña.
-- El usuario pasa a ser único globalmente, incluso entre empresas distintas.

USE facturasv2;

DROP PROCEDURE IF EXISTS migrate_facturasv2_002;

DELIMITER $$

CREATE PROCEDURE migrate_facturasv2_002()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM (
            SELECT usuario FROM users GROUP BY usuario HAVING COUNT(*) > 1
        ) AS usuarios_duplicados
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Hay usuarios duplicados entre empresas. Renómbrelos antes de aplicar la migración 002.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND index_name = 'uq_users_tenant_usuario'
    ) THEN
        ALTER TABLE users DROP INDEX uq_users_tenant_usuario;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND index_name = 'uq_users_usuario'
    ) THEN
        ALTER TABLE users ADD UNIQUE KEY uq_users_usuario (usuario);
    END IF;

    INSERT INTO schema_versions (version, description)
    VALUES (2, 'Autenticación sin NIT y nombre de usuario único global')
    ON DUPLICATE KEY UPDATE description = VALUES(description);
END$$

DELIMITER ;

CALL migrate_facturasv2_002();
DROP PROCEDURE migrate_facturasv2_002;
