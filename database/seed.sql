-- FacturaSV - Datos iniciales del módulo 1
-- Usuario demo: admin | Contraseña: Demo1234!
-- Ejecutar después de schema.sql. El script puede ejecutarse más de una vez.

USE facturasv2;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

INSERT INTO tenants (
    nit, razon_social, nombre_comercial, codigo_actividad,
    direccion, telefono, email, numero_registro_mh, activo
) VALUES (
    '0614-000000-000-0', 'Empresa Demo SA de CV', 'Empresa Demo', '00000',
    'San Salvador, El Salvador', '0000-0000', 'demo@facturasv.local', '0000000', 1
)
ON DUPLICATE KEY UPDATE nit = VALUES(nit);

-- Logo de demostración para la empresa demo; solo se asigna si aún no tiene uno.
UPDATE tenants SET logo_path = 'uploads/logos/demo.jpg'
WHERE nit = '0614-000000-000-0' AND logo_path IS NULL;

INSERT INTO tenant_config (tenant_id, mh_mode, timezone, moneda, distrito_emisor, establecimiento)
SELECT id, 'SIMULACION', 'America/El_Salvador', 'USD', '14', 'M001P001'
FROM tenants
WHERE nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE mh_mode = VALUES(mh_mode);

INSERT INTO users (
    tenant_id, nombre, usuario, email, password_hash, rol, activo
)
SELECT
    t.id,
    'Administrador Demo',
    'admin',
    'admin@facturasv.local',
    '$2y$10$jNYXK68yXE1woACvsVYjbuUTC9R9JNSrvgBj6xND6Ct55dYHkroTe',
    'ADMIN',
    1
FROM tenants t
WHERE t.nit = '0614-000000-000-0'
  AND NOT EXISTS (
      SELECT 1 FROM users u
      WHERE u.tenant_id = t.id AND u.usuario = 'admin'
  );

-- Superadministrador global: no pertenece a ninguna empresa.
INSERT INTO users (
    tenant_id, nombre, usuario, email, password_hash, rol, activo
) VALUES (
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
    activo = VALUES(activo);

-- Catálogos oficiales MH. Los registros existentes no se sobrescriben al reejecutar este archivo.

INSERT INTO mh_tipos_documento (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden
FROM tenants t
JOIN (
    SELECT '01' AS codigo, 'Factura' AS nombre, 10 AS orden UNION ALL
    SELECT '03', 'Comprobante de Crédito Fiscal', 20 UNION ALL
    SELECT '05', 'Nota de Crédito', 30 UNION ALL
    SELECT '06', 'Nota de Débito', 40 UNION ALL
    SELECT '11', 'Factura de Exportación', 50 UNION ALL
    SELECT '14', 'Factura de Sujeto Excluido', 60
) x
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT INTO mh_tipos_establecimiento (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden
FROM tenants t
JOIN (
    SELECT '01' AS codigo, 'Sucursal o agencia' AS nombre, 10 AS orden UNION ALL
    SELECT '02', 'Casa matriz', 20 UNION ALL
    SELECT '03', 'Bodega', 30 UNION ALL
    SELECT '04', 'Predio y/o patio', 40
) x
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT INTO mh_tipos_item (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden
FROM tenants t
JOIN (
    SELECT '1' AS codigo, 'Bienes' AS nombre, 10 AS orden UNION ALL
    SELECT '2', 'Servicios', 20 UNION ALL
    SELECT '3', 'Bienes y servicios', 30 UNION ALL
    SELECT '4', 'Otros tributos y cargos por ítem', 40
) x
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT INTO mh_unidades_medida (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden
FROM tenants t
JOIN (
    SELECT '1' AS codigo, 'Metro' AS nombre, 10 AS orden UNION ALL
    SELECT '2', 'Yarda', 20 UNION ALL SELECT '6', 'Milímetro', 30 UNION ALL
    SELECT '9', 'Kilómetro cuadrado', 40 UNION ALL SELECT '10', 'Hectárea', 50 UNION ALL
    SELECT '13', 'Metro cuadrado', 60 UNION ALL SELECT '15', 'Vara cuadrada', 70 UNION ALL
    SELECT '18', 'Metro cúbico', 80 UNION ALL SELECT '20', 'Barril', 90 UNION ALL
    SELECT '22', 'Galón', 100 UNION ALL SELECT '23', 'Litro', 110 UNION ALL
    SELECT '24', 'Botella', 120 UNION ALL SELECT '26', 'Mililitro', 130 UNION ALL
    SELECT '30', 'Tonelada', 140 UNION ALL SELECT '32', 'Quintal', 150 UNION ALL
    SELECT '33', 'Arroba', 160 UNION ALL SELECT '34', 'Kilogramo', 170 UNION ALL
    SELECT '36', 'Libra', 180 UNION ALL SELECT '37', 'Onza troy', 190 UNION ALL
    SELECT '38', 'Onza', 200 UNION ALL SELECT '39', 'Gramo', 210 UNION ALL
    SELECT '40', 'Miligramo', 220 UNION ALL SELECT '42', 'Megawatt', 230 UNION ALL
    SELECT '43', 'Kilowatt', 240 UNION ALL SELECT '44', 'Watt', 250 UNION ALL
    SELECT '45', 'Megavoltio-amperio', 260 UNION ALL SELECT '46', 'Kilovoltio-amperio', 270 UNION ALL
    SELECT '47', 'Voltio-amperio', 280 UNION ALL SELECT '49', 'Gigawatt-hora', 290 UNION ALL
    SELECT '50', 'Megawatt-hora', 300 UNION ALL SELECT '51', 'Kilowatt-hora', 310 UNION ALL
    SELECT '52', 'Watt-hora', 320 UNION ALL SELECT '53', 'Kilovoltio', 330 UNION ALL
    SELECT '54', 'Voltio', 340 UNION ALL SELECT '55', 'Millar', 350 UNION ALL
    SELECT '56', 'Medio millar', 360 UNION ALL SELECT '57', 'Ciento', 370 UNION ALL
    SELECT '58', 'Docena', 380 UNION ALL SELECT '59', 'Unidad', 390 UNION ALL SELECT '99', 'Otra', 400
) x
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT INTO mh_condiciones_operacion (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden
FROM tenants t
JOIN (
    SELECT '1' AS codigo, 'Contado' AS nombre, 10 AS orden UNION ALL
    SELECT '2', 'A crédito', 20 UNION ALL
    SELECT '3', 'Otro', 30
) x
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT INTO mh_formas_pago (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden
FROM tenants t
JOIN (
    SELECT '01' AS codigo, 'Billetes y monedas' AS nombre, 10 AS orden UNION ALL
    SELECT '02', 'Tarjeta Débito', 20 UNION ALL SELECT '03', 'Tarjeta Crédito', 30 UNION ALL
    SELECT '04', 'Cheque', 40 UNION ALL SELECT '05', 'Transferencia-Depósito Bancario', 50 UNION ALL
    SELECT '08', 'Dinero electrónico', 60 UNION ALL SELECT '09', 'Monedero electrónico', 70 UNION ALL
    SELECT '11', 'Bitcoin', 80 UNION ALL SELECT '12', 'Otras Criptomonedas', 90 UNION ALL
    SELECT '13', 'Cuentas por pagar del receptor', 100 UNION ALL SELECT '14', 'Giro bancario', 110 UNION ALL
    SELECT '99', 'Otros', 120
) x
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT INTO mh_departamentos (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden
FROM tenants t
JOIN (
    SELECT '01' AS codigo, 'Ahuachapán' AS nombre, 10 AS orden UNION ALL
    SELECT '02', 'Santa Ana', 20 UNION ALL
    SELECT '03', 'Sonsonate', 30 UNION ALL
    SELECT '04', 'Chalatenango', 40 UNION ALL
    SELECT '05', 'La Libertad', 50 UNION ALL
    SELECT '06', 'San Salvador', 60 UNION ALL
    SELECT '07', 'Cuscatlán', 70 UNION ALL
    SELECT '08', 'La Paz', 80 UNION ALL
    SELECT '09', 'Cabañas', 90 UNION ALL
    SELECT '10', 'San Vicente', 100 UNION ALL
    SELECT '11', 'Usulután', 110 UNION ALL
    SELECT '12', 'San Miguel', 120 UNION ALL
    SELECT '13', 'Morazán', 130 UNION ALL
    SELECT '14', 'La Unión', 140
) x
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT INTO mh_municipios (tenant_id, departamento_id, codigo, nombre, orden)
SELECT t.id, d.id, x.codigo, x.nombre, x.orden
FROM tenants t
JOIN (
    SELECT '01' AS departamento, '01' AS codigo, 'Ahuachapán Norte' AS nombre, 10 AS orden UNION ALL
    SELECT '01', '02', 'Ahuachapán Centro', 20 UNION ALL
    SELECT '01', '03', 'Ahuachapán Oeste', 30 UNION ALL
    SELECT '02', '01', 'Santa Ana Norte', 10 UNION ALL
    SELECT '02', '02', 'Santa Ana Centro', 20 UNION ALL
    SELECT '02', '03', 'Santa Ana Este', 30 UNION ALL
    SELECT '02', '04', 'Santa Ana Oeste', 40 UNION ALL
    SELECT '03', '01', 'Sonsonate Norte', 10 UNION ALL
    SELECT '03', '02', 'Sonsonate Centro', 20 UNION ALL
    SELECT '03', '03', 'Sonsonate Este', 30 UNION ALL
    SELECT '03', '04', 'Sonsonate Oeste', 40 UNION ALL
    SELECT '04', '01', 'Chalatenango Norte', 10 UNION ALL
    SELECT '04', '02', 'Chalatenango Centro', 20 UNION ALL
    SELECT '04', '03', 'Chalatenango Sur', 30 UNION ALL
    SELECT '05', '01', 'La Libertad Norte', 10 UNION ALL
    SELECT '05', '02', 'La Libertad Centro', 20 UNION ALL
    SELECT '05', '03', 'La Libertad Oeste', 30 UNION ALL
    SELECT '05', '04', 'La Libertad Costa', 40 UNION ALL
    SELECT '05', '05', 'La Libertad Este', 50 UNION ALL
    SELECT '05', '06', 'La Libertad Sur', 60 UNION ALL
    SELECT '06', '01', 'San Salvador Norte', 10 UNION ALL
    SELECT '06', '02', 'San Salvador Oeste', 20 UNION ALL
    SELECT '06', '03', 'San Salvador Este', 30 UNION ALL
    SELECT '06', '04', 'San Salvador Centro', 40 UNION ALL
    SELECT '06', '05', 'San Salvador Sur', 50 UNION ALL
    SELECT '07', '01', 'Cuscatlán Norte', 10 UNION ALL
    SELECT '07', '02', 'Cuscatlán Sur', 20 UNION ALL
    SELECT '08', '01', 'La Paz Oeste', 10 UNION ALL
    SELECT '08', '02', 'La Paz Centro', 20 UNION ALL
    SELECT '08', '03', 'La Paz Este', 30 UNION ALL
    SELECT '09', '01', 'Cabañas Oeste', 10 UNION ALL
    SELECT '09', '02', 'Cabañas Este', 20 UNION ALL
    SELECT '10', '01', 'San Vicente Norte', 10 UNION ALL
    SELECT '10', '02', 'San Vicente Sur', 20 UNION ALL
    SELECT '11', '01', 'Usulután Norte', 10 UNION ALL
    SELECT '11', '02', 'Usulután Este', 20 UNION ALL
    SELECT '11', '03', 'Usulután Oeste', 30 UNION ALL
    SELECT '12', '01', 'San Miguel Norte', 10 UNION ALL
    SELECT '12', '02', 'San Miguel Centro', 20 UNION ALL
    SELECT '12', '03', 'San Miguel Oeste', 30 UNION ALL
    SELECT '13', '01', 'Morazán Norte', 10 UNION ALL
    SELECT '13', '02', 'Morazán Sur', 20 UNION ALL
    SELECT '14', '01', 'La Unión Norte', 10 UNION ALL
    SELECT '14', '02', 'La Unión Sur', 20
) x
JOIN mh_departamentos d
    ON d.tenant_id = t.id AND d.codigo = x.departamento
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

-- Módulo 3: clientes demo
INSERT INTO clientes (
    tenant_id, tipo_documento, numero_documento, nombre, nombre_comercial,
    actividad_economica, direccion, departamento_id, municipio_id,
    telefono, email, tipo_contribuyente, activo
)
SELECT
    t.id, x.tipo_documento, x.numero_documento, x.nombre, x.nombre_comercial,
    x.actividad_economica, x.direccion, d.id, m.id,
    x.telefono, x.email, x.tipo_contribuyente, 1
FROM tenants t
JOIN (
    SELECT
        'NIT' AS tipo_documento, '0614-111111-111-1' AS numero_documento,
        'Comercial Ejemplo SA de CV' AS nombre, 'Comercial Ejemplo' AS nombre_comercial,
        'Comercio al por menor' AS actividad_economica,
        'Colonia Centro, San Salvador' AS direccion,
        '06' AS departamento, '04' AS municipio, '2222-3333' AS telefono,
        'contacto@comercial-ejemplo.local' AS email,
        'GRAN_CONTRIBUYENTE' AS tipo_contribuyente
    UNION ALL
    SELECT
        'DUI', '11111111-1', 'María López', NULL,
        'Servicios profesionales', 'Residencial Las Flores, La Libertad',
        '05', '02', '7777-8888', 'maria.lopez@example.local', 'OTRO'
    UNION ALL
    SELECT
        'NIT', '0614-222222-222-2', 'Servicios Profesionales SA de CV',
        'Servicios Profesionales', 'Consultoría y servicios empresariales',
        'Avenida Principal, Santa Ana', '02', '02', '2444-5555',
        'contacto@servicios-profesionales.local', 'OTRO'
) x
JOIN mh_departamentos d ON d.tenant_id = t.id AND d.codigo = x.departamento
JOIN mh_municipios m
    ON m.tenant_id = t.id AND m.departamento_id = d.id AND m.codigo = x.municipio
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

-- Módulo 4: productos y servicios demo
INSERT INTO productos (
    tenant_id, codigo, descripcion, tipo, unidad_medida_id,
    precio_unitario, tratamiento_iva, control_stock, stock, activo
)
SELECT
    t.id, x.codigo, x.descripcion, x.tipo, u.id,
    x.precio_unitario, x.tratamiento_iva, x.control_stock, x.stock, 1
FROM tenants t
JOIN (
    SELECT 'PROD-001' AS codigo, 'Laptop empresarial' AS descripcion,
           'BIEN' AS tipo, '59' AS unidad, 850.000000 AS precio_unitario,
           'GRAVADO' AS tratamiento_iva, 1 AS control_stock, 12.0000 AS stock
    UNION ALL
    SELECT 'PROD-002', 'Mouse inalámbrico', 'BIEN', '59', 18.500000,
           'GRAVADO', 1, 50.0000
    UNION ALL
    SELECT 'PROD-003', 'Producto no sujeto demo', 'BIEN', '59', 25.000000,
           'NO_SUJETO', 0, NULL
    UNION ALL
    SELECT 'SERV-001', 'Instalación y configuración', 'SERVICIO', '59', 75.000000,
           'GRAVADO', 0, NULL
    UNION ALL
    SELECT 'SERV-002', 'Capacitación empresarial', 'SERVICIO', '59', 120.000000,
           'EXENTO', 0, NULL
) x
JOIN mh_unidades_medida u ON u.tenant_id = t.id AND u.codigo = x.unidad
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

-- Módulo 5: correlativos independientes por tipo DTE
INSERT INTO document_sequences (tenant_id, tipo_dte, establecimiento, prefijo, numero_inicial, numero_actual)
SELECT t.id, x.tipo_dte, c.establecimiento, 'DTE', 1, 0
FROM tenants t
INNER JOIN tenant_config c ON c.tenant_id = t.id
JOIN (
    SELECT '01' AS tipo_dte UNION ALL SELECT '03' UNION ALL SELECT '05'
    UNION ALL SELECT '06' UNION ALL SELECT '11' UNION ALL SELECT '14'
) x
WHERE t.nit = '0614-000000-000-0'
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

COMMIT;
