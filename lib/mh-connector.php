<?php

declare(strict_types=1);

interface MHConnectorInterface
{
    public function firmarDocumento(array $documento, array $contexto = []): array;
    public function transmitirDTE(string $firma, array $documento, array $contexto = []): array;
    public function consultarEstado(string $codigoGeneracion, array $contexto = []): array;
    public function invalidarDTE(array $evento, array $contexto = []): array;
    public function transmitirEventoContingencia(array $evento, array $contexto = []): array;
}

final class MHMock implements MHConnectorInterface
{
    public function firmarDocumento(array $documento, array $contexto = []): array
    {
        $json = json_encode($documento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return [
            'estado' => 'FIRMADO',
            'firma' => 'SIMULATED-JWS.' . base64_encode(hash('sha256', $json, true)) . '.FIRMA',
            'fechaFirma' => gmdate('c'),
        ];
    }

    public function transmitirDTE(string $firma, array $documento, array $contexto = []): array
    {
        sleep(2);
        return [
            'estado' => 'PROCESADO',
            'selloRecetion' => strtoupper(bin2hex(random_bytes(20))),
            'codigoGeneracion' => $documento['identificacion']['codigoGeneracion'],
            'fhProcesamiento' => gmdate('Y-m-d H:i:s'),
            'observaciones' => [],
        ];
    }

    public function consultarEstado(string $codigoGeneracion, array $contexto = []): array
    {
        return [
            'estado' => 'PROCESADO',
            'codigoGeneracion' => $codigoGeneracion,
            'selloRecetion' => $contexto['sello_recepcion'] ?? null,
            'fhConsulta' => gmdate('c'),
        ];
    }

    public function invalidarDTE(array $evento, array $contexto = []): array
    {
        sleep(2);
        return [
            'estado' => 'PROCESADO',
            'selloRecetion' => strtoupper(bin2hex(random_bytes(20))),
            'codigoGeneracion' => $evento['identificacion']['codigoGeneracion'],
            'fhProcesamiento' => gmdate('Y-m-d H:i:s'),
            'observaciones' => [],
        ];
    }

    public function transmitirEventoContingencia(array $evento, array $contexto = []): array
    {
        sleep(2);
        return [
            'estado' => 'PROCESADO',
            'selloRecetion' => strtoupper(bin2hex(random_bytes(20))),
            'codigoGeneracion' => $evento['identificacion']['codigoGeneracion'],
            'fhProcesamiento' => gmdate('Y-m-d H:i:s'),
            'observaciones' => [],
        ];
    }
}

// TODO-MH: implementar MHClient con la misma interfaz cuando se autoricen endpoints y credenciales reales.
final class MHConnectorFactory
{
    public static function make(string $mode): MHConnectorInterface
    {
        if ($mode === 'SIMULACION') {
            return new MHMock();
        }
        throw new RuntimeException('El conector MH de producción aún no está configurado.');
    }
}
