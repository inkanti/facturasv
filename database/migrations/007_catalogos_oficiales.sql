-- FacturaSV - Catálogos utilizados por DTE contrastados con documentación oficial MH
USE facturasv2;
SET NAMES utf8mb4;

UPDATE mh_tipos_documento SET nombre = 'Comprobante de Crédito Fiscal' WHERE codigo = '03';

INSERT INTO mh_unidades_medida (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden FROM tenants t
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
) x WHERE 1 = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), orden = VALUES(orden);
UPDATE mh_unidades_medida SET activo = 0 WHERE codigo = '29';

INSERT INTO mh_formas_pago (tenant_id, codigo, nombre, orden)
SELECT t.id, x.codigo, x.nombre, x.orden FROM tenants t
JOIN (
    SELECT '01' AS codigo, 'Billetes y monedas' AS nombre, 10 AS orden UNION ALL
    SELECT '02', 'Tarjeta Débito', 20 UNION ALL SELECT '03', 'Tarjeta Crédito', 30 UNION ALL
    SELECT '04', 'Cheque', 40 UNION ALL SELECT '05', 'Transferencia-Depósito Bancario', 50 UNION ALL
    SELECT '08', 'Dinero electrónico', 60 UNION ALL SELECT '09', 'Monedero electrónico', 70 UNION ALL
    SELECT '11', 'Bitcoin', 80 UNION ALL SELECT '12', 'Otras Criptomonedas', 90 UNION ALL
    SELECT '13', 'Cuentas por pagar del receptor', 100 UNION ALL SELECT '14', 'Giro bancario', 110 UNION ALL
    SELECT '99', 'Otros', 120
) x WHERE 1 = 1
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), orden = VALUES(orden);
UPDATE mh_formas_pago SET activo = 0 WHERE codigo IN ('06', '07');

INSERT INTO schema_versions (version, description)
VALUES (7, 'Módulo 5: catálogos MH oficiales para unidades y formas de pago')
ON DUPLICATE KEY UPDATE description = VALUES(description);
