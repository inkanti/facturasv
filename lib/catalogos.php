<?php

declare(strict_types=1);

function catalogDefinitions(): array
{
    return [
        'tipos-documento' => [
            'table' => 'mh_tipos_documento', 'label' => 'Tipos de documento',
            'singular' => 'Tipo de documento', 'code_length' => 2,
        ],
        'tipos-establecimiento' => [
            'table' => 'mh_tipos_establecimiento', 'label' => 'Tipos de establecimiento',
            'singular' => 'Tipo de establecimiento', 'code_length' => 2,
        ],
        'tipos-item' => [
            'table' => 'mh_tipos_item', 'label' => 'Tipos de ítem',
            'singular' => 'Tipo de ítem', 'code_length' => 1,
        ],
        'unidades-medida' => [
            'table' => 'mh_unidades_medida', 'label' => 'Unidades de medida',
            'singular' => 'Unidad de medida', 'code_length' => 2,
        ],
        'condiciones-operacion' => [
            'table' => 'mh_condiciones_operacion', 'label' => 'Condiciones de operación',
            'singular' => 'Condición de operación', 'code_length' => 1,
        ],
        'formas-pago' => [
            'table' => 'mh_formas_pago', 'label' => 'Formas de pago',
            'singular' => 'Forma de pago', 'code_length' => 2,
        ],
        'departamentos' => [
            'table' => 'mh_departamentos', 'label' => 'Departamentos',
            'singular' => 'Departamento', 'code_length' => 2,
        ],
        'municipios' => [
            'table' => 'mh_municipios', 'label' => 'Municipios',
            'singular' => 'Municipio', 'code_length' => 2, 'municipality' => true,
        ],
    ];
}

// Valores contrastados con los catálogos oficiales MH entregados para el módulo 5.
function catalogDefaults(): array
{
    return [
        'tipos-documento' => [
            ['01', 'Factura'], ['03', 'Comprobante de Crédito Fiscal'], ['05', 'Nota de Crédito'],
            ['06', 'Nota de Débito'], ['11', 'Factura de Exportación'], ['14', 'Factura de Sujeto Excluido'],
        ],
        'tipos-establecimiento' => [
            ['01', 'Sucursal o agencia'], ['02', 'Casa matriz'], ['03', 'Bodega'], ['04', 'Predio y/o patio'],
        ],
        'tipos-item' => [
            ['1', 'Bienes'], ['2', 'Servicios'], ['3', 'Bienes y servicios'], ['4', 'Otros tributos y cargos por ítem'],
        ],
        'unidades-medida' => [
            ['1', 'Metro'], ['2', 'Yarda'], ['6', 'Milímetro'], ['9', 'Kilómetro cuadrado'],
            ['10', 'Hectárea'], ['13', 'Metro cuadrado'], ['15', 'Vara cuadrada'], ['18', 'Metro cúbico'],
            ['20', 'Barril'], ['22', 'Galón'], ['23', 'Litro'], ['24', 'Botella'], ['26', 'Mililitro'],
            ['30', 'Tonelada'], ['32', 'Quintal'], ['33', 'Arroba'], ['34', 'Kilogramo'], ['36', 'Libra'],
            ['37', 'Onza troy'], ['38', 'Onza'], ['39', 'Gramo'], ['40', 'Miligramo'], ['42', 'Megawatt'],
            ['43', 'Kilowatt'], ['44', 'Watt'], ['45', 'Megavoltio-amperio'], ['46', 'Kilovoltio-amperio'],
            ['47', 'Voltio-amperio'], ['49', 'Gigawatt-hora'], ['50', 'Megawatt-hora'], ['51', 'Kilowatt-hora'],
            ['52', 'Watt-hora'], ['53', 'Kilovoltio'], ['54', 'Voltio'], ['55', 'Millar'], ['56', 'Medio millar'],
            ['57', 'Ciento'], ['58', 'Docena'], ['59', 'Unidad'], ['99', 'Otra'],
        ],
        'condiciones-operacion' => [['1', 'Contado'], ['2', 'A crédito'], ['3', 'Otro']],
        'formas-pago' => [
            ['01', 'Billetes y monedas'], ['02', 'Tarjeta Débito'], ['03', 'Tarjeta Crédito'],
            ['04', 'Cheque'], ['05', 'Transferencia-Depósito Bancario'], ['08', 'Dinero electrónico'],
            ['09', 'Monedero electrónico'], ['11', 'Bitcoin'], ['12', 'Otras Criptomonedas'],
            ['13', 'Cuentas por pagar del receptor'], ['14', 'Giro bancario'], ['99', 'Otros'],
        ],
        'departamentos' => [
            ['01', 'Ahuachapán'], ['02', 'Santa Ana'], ['03', 'Sonsonate'], ['04', 'Chalatenango'],
            ['05', 'La Libertad'], ['06', 'San Salvador'], ['07', 'Cuscatlán'], ['08', 'La Paz'],
            ['09', 'Cabañas'], ['10', 'San Vicente'], ['11', 'Usulután'], ['12', 'San Miguel'],
            ['13', 'Morazán'], ['14', 'La Unión'],
        ],
        'municipios' => [
            ['01', '01', 'Ahuachapán Norte'], ['01', '02', 'Ahuachapán Centro'], ['01', '03', 'Ahuachapán Oeste'],
            ['02', '01', 'Santa Ana Norte'], ['02', '02', 'Santa Ana Centro'], ['02', '03', 'Santa Ana Este'], ['02', '04', 'Santa Ana Oeste'],
            ['03', '01', 'Sonsonate Norte'], ['03', '02', 'Sonsonate Centro'], ['03', '03', 'Sonsonate Este'], ['03', '04', 'Sonsonate Oeste'],
            ['04', '01', 'Chalatenango Norte'], ['04', '02', 'Chalatenango Centro'], ['04', '03', 'Chalatenango Sur'],
            ['05', '01', 'La Libertad Norte'], ['05', '02', 'La Libertad Centro'], ['05', '03', 'La Libertad Oeste'],
            ['05', '04', 'La Libertad Costa'], ['05', '05', 'La Libertad Este'], ['05', '06', 'La Libertad Sur'],
            ['06', '01', 'San Salvador Norte'], ['06', '02', 'San Salvador Oeste'], ['06', '03', 'San Salvador Este'],
            ['06', '04', 'San Salvador Centro'], ['06', '05', 'San Salvador Sur'],
            ['07', '01', 'Cuscatlán Norte'], ['07', '02', 'Cuscatlán Sur'],
            ['08', '01', 'La Paz Oeste'], ['08', '02', 'La Paz Centro'], ['08', '03', 'La Paz Este'],
            ['09', '01', 'Cabañas Oeste'], ['09', '02', 'Cabañas Este'],
            ['10', '01', 'San Vicente Norte'], ['10', '02', 'San Vicente Sur'],
            ['11', '01', 'Usulután Norte'], ['11', '02', 'Usulután Este'], ['11', '03', 'Usulután Oeste'],
            ['12', '01', 'San Miguel Norte'], ['12', '02', 'San Miguel Centro'], ['12', '03', 'San Miguel Oeste'],
            ['13', '01', 'Morazán Norte'], ['13', '02', 'Morazán Sur'],
            ['14', '01', 'La Unión Norte'], ['14', '02', 'La Unión Sur'],
        ],
    ];
}

function seedTenantCatalogs(PDO $pdo, int $tenantId): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $defaults = catalogDefaults();
        $definitions = catalogDefinitions();

        foreach (['tipos-documento', 'tipos-establecimiento', 'tipos-item', 'unidades-medida', 'condiciones-operacion', 'formas-pago', 'departamentos'] as $resource) {
            $table = $definitions[$resource]['table'];
            $statement = $pdo->prepare(
                "INSERT INTO {$table} (tenant_id, codigo, nombre, orden)
                 VALUES (:tenant_id, :codigo, :nombre, :orden)
                 ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)"
            );

            foreach ($defaults[$resource] as $index => [$code, $name]) {
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'codigo' => $code,
                    'nombre' => $name,
                    'orden' => ($index + 1) * 10,
                ]);
            }
        }

        $departmentStatement = $pdo->prepare(
            'SELECT id, codigo FROM mh_departamentos WHERE tenant_id = :tenant_id'
        );
        $departmentStatement->execute(['tenant_id' => $tenantId]);
        $departmentIds = [];
        foreach ($departmentStatement->fetchAll() as $department) {
            $departmentIds[$department['codigo']] = (int) $department['id'];
        }

        $statement = $pdo->prepare(
            'INSERT INTO mh_municipios (tenant_id, departamento_id, codigo, nombre, orden)
             VALUES (:tenant_id, :departamento_id, :codigo, :nombre, :orden)
             ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)'
        );
        foreach ($defaults['municipios'] as $index => [$departmentCode, $code, $name]) {
            $statement->execute([
                'tenant_id' => $tenantId,
                'departamento_id' => $departmentIds[$departmentCode],
                'codigo' => $code,
                'nombre' => $name,
                'orden' => ($index + 1) * 10,
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
