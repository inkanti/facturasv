<?php

declare(strict_types=1);

/**
 * Generador de códigos QR en PHP puro (sin librerías externas).
 *
 * Implementa QR modelo 2, modo byte, nivel de corrección M, versiones 1 a 10
 * y selección automática de versión y máscara. Se usa para el código QR de
 * consulta pública de los DTE y para la impresión del ticket.
 */
final class QrEncoder
{
    /**
     * Estructura de bloques para el nivel de corrección M.
     * total: codewords totales; data: codewords de datos; ecc: por bloque.
     */
    private const ECC_M = [
        1 => ['total' => 26, 'data' => 16, 'ecc' => 10, 'blocks' => [16]],
        2 => ['total' => 44, 'data' => 28, 'ecc' => 16, 'blocks' => [28]],
        3 => ['total' => 70, 'data' => 44, 'ecc' => 26, 'blocks' => [44]],
        4 => ['total' => 100, 'data' => 64, 'ecc' => 18, 'blocks' => [32, 32]],
        5 => ['total' => 134, 'data' => 86, 'ecc' => 24, 'blocks' => [43, 43]],
        6 => ['total' => 172, 'data' => 108, 'ecc' => 16, 'blocks' => [27, 27, 27, 27]],
        7 => ['total' => 196, 'data' => 124, 'ecc' => 18, 'blocks' => [31, 31, 31, 31]],
        8 => ['total' => 242, 'data' => 154, 'ecc' => 22, 'blocks' => [38, 38, 39, 39]],
        9 => ['total' => 292, 'data' => 182, 'ecc' => 22, 'blocks' => [36, 36, 36, 37, 37]],
        10 => ['total' => 346, 'data' => 216, 'ecc' => 26, 'blocks' => [43, 43, 43, 43, 44]],
    ];

    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /**
     * Devuelve la matriz del QR sin zona de silencio.
     *
     * @return array{version:int,size:int,modules:array<int,array<int,bool>>}
     */
    public static function encode(string $text): array
    {
        $length = strlen($text);
        if ($length === 0) {
            throw new InvalidArgumentException('El texto del código QR no puede estar vacío.');
        }
        $version = self::chooseVersion($length);
        $dataCapacity = self::ECC_M[$version]['data'];
        $countBits = $version >= 10 ? 16 : 8;
        $bitCapacity = $dataCapacity * 8;

        $bits = [];
        $push = static function (int $value, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $push(0b0100, 4); // modo byte
        $push($length, $countBits);
        for ($i = 0; $i < $length; $i++) {
            $push(ord($text[$i]), 8);
        }
        $terminator = min(4, $bitCapacity - count($bits));
        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $codewords = [];
        for ($i = 0, $count = count($bits); $i < $count; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }
        $pad = [0xEC, 0x11];
        for ($i = 0; count($codewords) < $dataCapacity; $i++) {
            $codewords[] = $pad[$i % 2];
        }

        $allCodewords = self::interleave($codewords, $version);
        $size = $version * 4 + 17;
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::drawFunctionPatterns($modules, $reserved, $version, $size);

        $dataBits = [];
        foreach ($allCodewords as $codeword) {
            for ($i = 7; $i >= 0; $i--) {
                $dataBits[] = ($codeword >> $i) & 1;
            }
        }
        self::placeData($modules, $reserved, $dataBits, $size);

        // Se prueban las ocho máscaras y se elige la de menor penalización.
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $modules;
            for ($row = 0; $row < $size; $row++) {
                for ($col = 0; $col < $size; $col++) {
                    if (!$reserved[$row][$col] && self::maskBit($mask, $row, $col)) {
                        $candidate[$row][$col] = !$candidate[$row][$col];
                    }
                }
            }
            self::placeFormatInfo($candidate, $mask, $size);
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return ['version' => $version, 'size' => $size, 'modules' => $best];
    }

    private static function chooseVersion(int $length): int
    {
        foreach (self::ECC_M as $version => $info) {
            $countBits = $version >= 10 ? 16 : 8;
            if (4 + $countBits + $length * 8 <= $info['data'] * 8) {
                return $version;
            }
        }
        throw new InvalidArgumentException('El texto es demasiado largo para el código QR (máximo 213 bytes).');
    }

    /** @param array<int,int> $data @return array<int,int> */
    private static function interleave(array $data, int $version): array
    {
        $info = self::ECC_M[$version];
        $blocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ($info['blocks'] as $blockLength) {
            $block = array_slice($data, $offset, $blockLength);
            $offset += $blockLength;
            $blocks[] = $block;
            $ecBlocks[] = self::reedSolomon($block, $info['ecc']);
        }
        $out = [];
        $maxData = max(array_map('count', $blocks));
        $maxEc = max(array_map('count', $ecBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $maxEc; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        return $out;
    }

    /** @param array<int,int> $data @return array<int,int> */
    private static function reedSolomon(array $data, int $eccCount): array
    {
        $generator = [1];
        for ($i = 0; $i < $eccCount; $i++) {
            $factor = self::gfPower($i);
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $degree => $coefficient) {
                $next[$degree] ^= $coefficient;
                $next[$degree + 1] ^= self::gfMultiply($coefficient, $factor);
            }
            $generator = $next;
        }
        $remainder = array_merge($data, array_fill(0, $eccCount, 0));
        $dataCount = count($data);
        for ($i = 0; $i < $dataCount; $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($generator as $degree => $coefficient) {
                $remainder[$i + $degree] ^= self::gfMultiply($coefficient, $factor);
            }
        }
        return array_slice($remainder, $dataCount);
    }

    private static function gfMultiply(int $a, int $b): int
    {
        $result = 0;
        while ($b > 0) {
            if (($b & 1) === 1) {
                $result ^= $a;
            }
            $b >>= 1;
            $a <<= 1;
            if (($a & 0x100) !== 0) {
                $a ^= 0x11D;
            }
        }
        return $result & 0xFF;
    }

    private static function gfPower(int $exponent): int
    {
        $value = 1;
        for ($i = 0; $i < $exponent; $i++) {
            $value = self::gfMultiply($value, 2);
        }
        return $value;
    }

    private static function drawFunctionPatterns(array &$modules, array &$reserved, int $version, int $size): void
    {
        self::drawFinder($modules, $reserved, 0, 0, $size);
        self::drawFinder($modules, $reserved, 0, $size - 7, $size);
        self::drawFinder($modules, $reserved, $size - 7, 0, $size);

        for ($i = 8; $i < $size - 8; $i++) {
            $value = $i % 2 === 0;
            $modules[6][$i] = $value;
            $reserved[6][$i] = true;
            $modules[$i][6] = $value;
            $reserved[$i][6] = true;
        }

        $centers = self::ALIGNMENT[$version];
        foreach ($centers as $rowCenter) {
            foreach ($centers as $colCenter) {
                if (($rowCenter === 6 && $colCenter === 6)
                    || ($rowCenter === 6 && $colCenter === $size - 7)
                    || ($rowCenter === $size - 7 && $colCenter === 6)) {
                    continue;
                }
                if (($rowCenter <= 8 && $colCenter <= 8)
                    || ($rowCenter <= 8 && $colCenter >= $size - 9)
                    || ($rowCenter >= $size - 9 && $colCenter <= 8)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $modules[$rowCenter + $dr][$colCenter + $dc] = abs($dr) === 2
                            || abs($dc) === 2
                            || ($dr === 0 && $dc === 0);
                        $reserved[$rowCenter + $dr][$colCenter + $dc] = true;
                    }
                }
            }
        }

        $modules[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;

        // Reserva las zonas de información de formato.
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 7; $i++) {
            $reserved[$size - 1 - $i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
        }

        if ($version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $row = intdiv($i, 3);
                $col = $size - 11 + ($i % 3);
                $reserved[$row][$col] = true;
                $reserved[$col][$row] = true;
            }
            $bits = self::versionBits($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $row = intdiv($i, 3);
                $col = $size - 11 + ($i % 3);
                $modules[$row][$col] = $bit;
                $modules[$col][$row] = $bit;
            }
        }
    }

    private static function drawFinder(array &$modules, array &$reserved, int $row, int $col, int $size): void
    {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $r = $row + $dr;
                $c = $col + $dc;
                if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
                    continue;
                }
                $reserved[$r][$c] = true;
                if ($dr < 0 || $dc < 0 || $dr > 6 || $dc > 6) {
                    $modules[$r][$c] = false;
                    continue;
                }
                $border = $dr === 0 || $dr === 6 || $dc === 0 || $dc === 6;
                $center = $dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4;
                $modules[$r][$c] = $border || $center;
            }
        }
    }

    /** @param array<int,int> $bits */
    private static function placeData(array &$modules, array $reserved, array $bits, int $size): void
    {
        $index = 0;
        $total = count($bits);
        $upward = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            for ($i = 0; $i < $size; $i++) {
                for ($j = 0; $j < 2; $j++) {
                    $c = $col - $j;
                    $r = $upward ? $size - 1 - $i : $i;
                    if ($reserved[$r][$c]) {
                        continue;
                    }
                    $modules[$r][$c] = $index < $total ? $bits[$index] === 1 : false;
                    $index++;
                }
            }
            $upward = !$upward;
        }
    }

    private static function maskBit(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
        };
    }

    private static function placeFormatInfo(array &$modules, int $mask, int $size): void
    {
        $bits = self::formatBits($mask);
        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = (($bits >> $i) & 1) === 1;
        }
        $modules[7][8] = (($bits >> 6) & 1) === 1;
        $modules[8][8] = (($bits >> 7) & 1) === 1;
        $modules[8][7] = (($bits >> 8) & 1) === 1;
        for ($i = 9; $i <= 14; $i++) {
            $modules[8][14 - $i] = (($bits >> $i) & 1) === 1;
        }
        // Segunda copia: bits 0-7 arriba a la derecha y bits 8-14 abajo a la izquierda.
        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = (($bits >> $i) & 1) === 1;
        }
        for ($i = 8; $i <= 14; $i++) {
            $modules[$size - 7 + ($i - 8)][8] = (($bits >> $i) & 1) === 1;
        }
        $modules[$size - 8][8] = true;
    }

    private static function formatBits(int $mask): int
    {
        $data = $mask; // nivel M = 00
        $value = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ((($value >> $i) & 1) === 1) {
                $value ^= 0x537 << ($i - 10);
            }
        }
        return (($data << 10) | ($value & 0x3FF)) ^ 0x5412;
    }

    private static function versionBits(int $version): int
    {
        $value = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if ((($value >> $i) & 1) === 1) {
                $value ^= 0x1F25 << ($i - 12);
            }
        }
        return ($version << 12) | ($value & 0xFFF);
    }

    private static function penalty(array $modules, int $size): int
    {
        $score = 0;
        for ($row = 0; $row < $size; $row++) {
            $run = 1;
            for ($col = 1; $col < $size; $col++) {
                if ($modules[$row][$col] === $modules[$row][$col - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
        for ($col = 0; $col < $size; $col++) {
            $run = 1;
            for ($row = 1; $row < $size; $row++) {
                if ($modules[$row][$col] === $modules[$row - 1][$col]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $value = $modules[$row][$col];
                if ($value === $modules[$row][$col + 1]
                    && $value === $modules[$row + 1][$col]
                    && $value === $modules[$row + 1][$col + 1]) {
                    $score += 3;
                }
            }
        }
        $patternA = [true, false, true, true, true, false, true, false, false, false, false];
        $patternB = [false, false, false, false, true, false, true, true, true, false, true];
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col <= $size - 11; $col++) {
                $matchA = true;
                $matchB = true;
                for ($i = 0; $i < 11; $i++) {
                    if ($modules[$row][$col + $i] !== $patternA[$i]) {
                        $matchA = false;
                    }
                    if ($modules[$row][$col + $i] !== $patternB[$i]) {
                        $matchB = false;
                    }
                }
                if ($matchA) {
                    $score += 40;
                }
                if ($matchB) {
                    $score += 40;
                }
            }
        }
        for ($col = 0; $col < $size; $col++) {
            for ($row = 0; $row <= $size - 11; $row++) {
                $matchA = true;
                $matchB = true;
                for ($i = 0; $i < 11; $i++) {
                    if ($modules[$row + $i][$col] !== $patternA[$i]) {
                        $matchA = false;
                    }
                    if ($modules[$row + $i][$col] !== $patternB[$i]) {
                        $matchB = false;
                    }
                }
                if ($matchA) {
                    $score += 40;
                }
                if ($matchB) {
                    $score += 40;
                }
            }
        }
        $dark = 0;
        foreach ($modules as $line) {
            foreach ($line as $module) {
                if ($module) {
                    $dark++;
                }
            }
        }
        $percent = (int) floor(($dark * 100) / ($size * $size));
        $score += 10 * intdiv(abs($percent - 50), 5);
        return $score;
    }

    /**
     * Matriz en escala de grises (0 negro, 255 blanco) con zona de silencio.
     * Devuelve los píxeles sin filtros PNG, listos para incrustar en el PDF.
     *
     * @return array{width:int,height:int,pixels:string}
     */
    public static function grayscale(string $text, int $scale = 5, int $quiet = 4): array
    {
        $matrix = self::encode($text);
        $size = $matrix['size'];
        $modules = $matrix['modules'];
        $dimension = ($size + $quiet * 2) * $scale;
        $pixels = '';
        for ($y = 0; $y < $dimension; $y++) {
            $moduleY = intdiv($y, $scale) - $quiet;
            for ($x = 0; $x < $dimension; $x++) {
                $moduleX = intdiv($x, $scale) - $quiet;
                $black = $moduleY >= 0 && $moduleY < $size && $moduleX >= 0 && $moduleX < $size
                    && $modules[$moduleY][$moduleX];
                $pixels .= $black ? "\x00" : "\xFF";
            }
        }
        return ['width' => $dimension, 'height' => $dimension, 'pixels' => $pixels];
    }

    /** PNG en escala de grises listo para usar en HTML (data URI) o descargar. */
    public static function png(string $text, int $scale = 5, int $quiet = 4): string
    {
        $image = self::grayscale($text, $scale, $quiet);
        $width = $image['width'];
        $height = $image['height'];
        $raw = $image['pixels'];
        $filtered = '';
        for ($y = 0; $y < $height; $y++) {
            $filtered .= "\x00" . substr($raw, $y * $width, $width);
        }
        $png = "\x89PNG\r\n\x1a\n";
        // IHDR: ancho (4), alto (4), profundidad (1), tipo de color (1),
        // compresión (1), filtro (1) y entrelazado (1) = 13 bytes.
        $png .= self::pngChunk('IHDR', pack('NNC', $width, $height, 8) . "\x00\x00\x00\x00");
        $png .= self::pngChunk('IDAT', gzcompress($filtered, 9));
        $png .= self::pngChunk('IEND', '');
        return $png;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}

/**
 * URL pública de consulta del DTE en el portal de Hacienda.
 * En simulación se usa ambiente 00; en producción, 01.
 */
function dteQrUrl(array $document, string $mhMode = 'SIMULACION'): ?string
{
    $code = trim((string) ($document['codigo_generacion'] ?? ''));
    $date = trim((string) ($document['fecha_emision'] ?? ''));
    if ($code === '' || $date === '') {
        return null;
    }
    $environment = $mhMode === 'PRODUCCION' ? '01' : '00';
    return 'https://admin.factura.gob.sv/consultaPublica?ambiente=' . $environment
        . '&codGen=' . $code . '&fechaEmi=' . $date;
}
