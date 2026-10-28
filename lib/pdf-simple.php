<?php

declare(strict_types=1);

/**
 * Generador PDF mínimo, sin dependencias externas.
 *
 * Soporta páginas tamaño carta, fuentes base (Helvetica, Helvetica-Bold,
 * Helvetica-Oblique, Courier) con codificación WinAnsi, líneas, rectángulos,
 * rectángulos redondeados e imágenes JPEG o en escala de grises (Flate).
 * Las coordenadas se expresan en puntos (1/72 pulgada) medidos desde la
 * esquina superior izquierda de la página.
 */
final class SimplePdf
{
    public const PAGE_WIDTH = 612.0;
    public const PAGE_HEIGHT = 792.0;

    private const FONTS = [
        'regular' => 'Helvetica',
        'bold' => 'Helvetica-Bold',
        'italic' => 'Helvetica-Oblique',
        'mono' => 'Courier',
    ];

    /** Ancho de los glifos ASCII (32-126) de Helvetica, en milésimas de em. */
    private const WIDTHS_REGULAR = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,556,
    ];

    /** Ancho de los glifos ASCII (32-126) de Helvetica-Bold, en milésimas de em. */
    private const WIDTHS_BOLD = [
        278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
        975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
        333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
        611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,556,
    ];

    private const ACCENT_MAP = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        '¿' => '?', '¡' => '!', '°' => 'o', '€' => 'E', '“' => '"', '”' => '"',
        '‘' => "'", '’' => "'", '–' => '-', '—' => '-', '·' => '.', '•' => '.',
    ];

    private array $streams = [];
    private ?string $current = null;
    private array $images = [];
    private array $pageImages = [];
    private array $fill = [0, 0, 0];
    private array $stroke = [0, 0, 0];

    public function addPage(): void
    {
        if ($this->current !== null) {
            $this->streams[] = $this->current;
        }
        $this->current = '';
        $this->pageImages[count($this->streams)] = [];
    }

    public function setFillColor(int $r, int $g, int $b): void
    {
        $this->fill = [$r / 255, $g / 255, $b / 255];
        $this->current .= $this->color($this->fill) . " rg\n";
    }

    public function setStrokeColor(int $r, int $g, int $b): void
    {
        $this->stroke = [$r / 255, $g / 255, $b / 255];
        $this->current .= $this->color($this->stroke) . " RG\n";
    }

    private function color(array $rgb): string
    {
        return $this->num($rgb[0]) . ' ' . $this->num($rgb[1]) . ' ' . $this->num($rgb[2]);
    }

    private function num(float $value): string
    {
        $formatted = number_format($value, 3, '.', '');
        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    private function y(float $top): float
    {
        return self::PAGE_HEIGHT - $top;
    }

    public function rect(float $x, float $top, float $width, float $height, bool $fill = false, bool $stroke = true, float $lineWidth = 0.8): void
    {
        $this->current .= $this->num($lineWidth) . " w\n";
        $this->current .= $this->num($x) . ' ' . $this->num($this->y($top + $height)) . ' '
            . $this->num($width) . ' ' . $this->num($height) . " re "
            . ($fill && $stroke ? 'B' : ($fill ? 'f' : 'S')) . "\n";
    }

    public function line(float $x1, float $top1, float $x2, float $top2, float $lineWidth = 0.8): void
    {
        $this->current .= $this->num($lineWidth) . " w\n";
        $this->current .= $this->num($x1) . ' ' . $this->num($this->y($top1)) . " m\n";
        $this->current .= $this->num($x2) . ' ' . $this->num($this->y($top2)) . " l S\n";
    }

    /** Rectángulo con esquinas redondeadas, dibujado con curvas Bézier. */
    public function roundedRect(
        float $x,
        float $top,
        float $width,
        float $height,
        float $radius,
        bool $fill = false,
        bool $stroke = true,
        float $lineWidth = 0.8
    ): void {
        $radius = max(0.0, min($radius, $width / 2, $height / 2));
        $topY = $this->y($top);
        $bottomY = $this->y($top + $height);
        $control = $radius * (1 - 0.5522847498);
        $this->current .= $this->num($lineWidth) . " w\n";
        $this->current .= $this->num($x + $radius) . ' ' . $this->num($topY) . " m\n";
        $this->current .= $this->num($x + $width - $radius) . ' ' . $this->num($topY) . " l\n";
        $this->current .= $this->num($x + $width - $radius + $control) . ' ' . $this->num($topY) . ' '
            . $this->num($x + $width) . ' ' . $this->num($topY - $radius + $control) . ' '
            . $this->num($x + $width) . ' ' . $this->num($topY - $radius) . " c\n";
        $this->current .= $this->num($x + $width) . ' ' . $this->num($bottomY + $radius) . " l\n";
        $this->current .= $this->num($x + $width) . ' ' . $this->num($bottomY + $radius - $control) . ' '
            . $this->num($x + $width - $radius + $control) . ' ' . $this->num($bottomY) . ' '
            . $this->num($x + $width - $radius) . ' ' . $this->num($bottomY) . " c\n";
        $this->current .= $this->num($x + $radius) . ' ' . $this->num($bottomY) . " l\n";
        $this->current .= $this->num($x + $radius - $control) . ' ' . $this->num($bottomY) . ' '
            . $this->num($x) . ' ' . $this->num($bottomY + $radius - $control) . ' '
            . $this->num($x) . ' ' . $this->num($bottomY + $radius) . " c\n";
        $this->current .= $this->num($x) . ' ' . $this->num($topY - $radius) . " l\n";
        $this->current .= $this->num($x) . ' ' . $this->num($topY - $radius + $control) . ' '
            . $this->num($x + $radius - $control) . ' ' . $this->num($topY) . ' '
            . $this->num($x + $radius) . ' ' . $this->num($topY) . " c\n";
        $this->current .= 'h ' . ($fill && $stroke ? 'B' : ($fill ? 'f' : 'S')) . "\n";
    }

    public function encodeText(string $text): string
    {
        $win = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        $win = str_replace(["\\", '(', ')'], ["\\\\", "\\(", "\\)"], $win);
        return $win;
    }

    public function textWidth(string $text, float $size, string $font = 'regular'): float
    {
        $table = $font === 'bold' ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR;
        $mono = $font === 'mono';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $width = 0;
        foreach ($chars as $char) {
            if ($mono) {
                $width += 600;
                continue;
            }
            $char = self::ACCENT_MAP[$char] ?? $char;
            $code = strlen($char) === 1 ? ord($char) : 0;
            $width += ($code >= 32 && $code <= 126) ? $table[$code - 32] : 556;
        }
        return $width * $size / 1000;
    }

    /**
     * Dibuja texto; $top es la línea base medida desde arriba.
     * $align: left, right o center (respecto a $x).
     */
    public function text(float $x, float $top, string $text, float $size = 9, string $font = 'regular', string $align = 'left'): void
    {
        $fontKey = array_search($font, array_keys(self::FONTS), true);
        $fontIndex = $fontKey === false ? 1 : ((int) $fontKey + 1);
        if ($align !== 'left') {
            $width = $this->textWidth($text, $size, $font);
            $x = $align === 'right' ? $x - $width : $x - $width / 2;
        }
        $this->current .= "BT /F{$fontIndex} " . $this->num($size) . " Tf "
            . $this->color($this->fill) . " rg 1 0 0 1 "
            . $this->num($x) . ' ' . $this->num($this->y($top)) . " Tm ("
            . $this->encodeText($text) . ") Tj ET\n";
    }

    /** Texto girado (por ejemplo la marca de INVALIDADA). $angle en grados. */
    public function rotatedText(float $x, float $top, string $text, float $size, float $angle, string $font = 'bold'): void
    {
        $fontKey = array_search($font, array_keys(self::FONTS), true);
        $fontIndex = $fontKey === false ? 2 : ((int) $fontKey + 1);
        $radians = deg2rad($angle);
        $cos = cos($radians);
        $sin = sin($radians);
        $this->current .= 'q ' . $this->num($cos) . ' ' . $this->num($sin) . ' '
            . $this->num(-$sin) . ' ' . $this->num($cos) . ' '
            . $this->num($x) . ' ' . $this->num($this->y($top)) . " cm\n";
        $this->current .= "BT /F{$fontIndex} " . $this->num($size) . " Tf "
            . $this->color($this->fill) . " rg 0 0 Td ("
            . $this->encodeText($text) . ") Tj ET\nQ\n";
    }

    public function imageJpeg(string $data, int $pixelWidth, int $pixelHeight, float $x, float $top, float $width, float $height): void
    {
        $hash = md5($data);
        if (!isset($this->images[$hash])) {
            $this->images[$hash] = [
                'name' => 'Im' . (count($this->images) + 1),
                'data' => $data,
                'w' => $pixelWidth,
                'h' => $pixelHeight,
                'filter' => 'DCTDecode',
                'space' => 'DeviceRGB',
                'parms' => '',
            ];
        }
        $this->placeImage($hash, $x, $top, $width, $height);
    }

    /**
     * Imagen en escala de grises de 8 bits, comprimida con FlateDecode.
     * $pixels debe contener width*height bytes (0 = negro, 255 = blanco).
     */
    public function imageGray(string $pixels, int $pixelWidth, int $pixelHeight, float $x, float $top, float $width, float $height): void
    {
        $compressed = gzcompress($pixels, 6);
        $hash = md5($compressed . '|' . $pixelWidth . 'x' . $pixelHeight);
        if (!isset($this->images[$hash])) {
            $this->images[$hash] = [
                'name' => 'Im' . (count($this->images) + 1),
                'data' => $compressed,
                'w' => $pixelWidth,
                'h' => $pixelHeight,
                'filter' => 'FlateDecode',
                'space' => 'DeviceGray',
                'parms' => '',
            ];
        }
        $this->placeImage($hash, $x, $top, $width, $height);
    }

    private function placeImage(string $hash, float $x, float $top, float $width, float $height): void
    {
        $name = $this->images[$hash]['name'];
        $page = count($this->streams);
        $this->pageImages[$page][$name] = true;
        $this->current .= "q " . $this->num($width) . " 0 0 " . $this->num($height) . ' '
            . $this->num($x) . ' ' . $this->num($this->y($top + $height)) . " cm /{$name} Do Q\n";
    }

    /** Divide un texto en líneas que no superen $maxWidth puntos. */
    public function wrapText(string $text, float $size, float $maxWidth, string $font = 'regular', int $maxLines = 4): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $this->textWidth($candidate, $size, $font) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
                if (count($lines) >= $maxLines) {
                    $current = '';
                    break;
                }
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }
        if ($lines === []) {
            $lines[] = '';
        }
        return $lines;
    }

    public function output(): string
    {
        if ($this->current !== null) {
            $this->streams[] = $this->current;
            $this->current = null;
        }
        $pageCount = count($this->streams);
        if ($pageCount === 0) {
            throw new RuntimeException('El documento PDF no contiene páginas.');
        }

        $objects = [];
        // 1: catálogo, 2: páginas; luego fuentes, páginas, contenidos e imágenes.
        $fontObjectIds = [];
        $nextId = 3;
        foreach (self::FONTS as $baseFont) {
            $fontObjectIds[] = $nextId;
            $objects[$nextId++] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $baseFont . ' /Encoding /WinAnsiEncoding >>';
        }
        $imageObjectIds = [];
        foreach ($this->images as $hash => $image) {
            $imageObjectIds[$hash] = $nextId;
            $stream = '<< /Type /XObject /Subtype /Image /Width ' . $image['w'] . ' /Height ' . $image['h']
                . ' /ColorSpace /' . ($image['space'] ?? 'DeviceRGB') . ' /BitsPerComponent 8 /Filter /'
                . ($image['filter'] ?? 'DCTDecode') . ' /Length ' . strlen($image['data']) . " >>\nstream\n"
                . $image['data'] . "\nendstream";
            $objects[$nextId++] = $stream;
        }

        $pageObjectIds = [];
        $fontResources = [];
        foreach ($fontObjectIds as $index => $id) {
            $fontResources[] = '/F' . ($index + 1) . ' ' . $id . ' 0 R';
        }
        for ($page = 0; $page < $pageCount; $page++) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageObjectIds[] = $pageId;

            $xobjects = [];
            foreach (array_keys($this->pageImages[$page] ?? []) as $name) {
                foreach ($this->images as $hash => $image) {
                    if ($image['name'] === $name) {
                        $xobjects[] = '/' . $name . ' ' . $imageObjectIds[$hash] . ' 0 R';
                    }
                }
            }
            $resources = '<< /Font << ' . implode(' ', $fontResources) . ' >>'
                . ($xobjects !== [] ? ' /XObject << ' . implode(' ', $xobjects) . ' >>' : '') . ' >>';

            $stream = str_replace(
                ['{PAGE}', '{PAGES}'],
                [(string) ($page + 1), (string) $pageCount],
                $this->streams[$page]
            );
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
                . $this->num(self::PAGE_WIDTH) . ' ' . $this->num(self::PAGE_HEIGHT) . '] /Resources '
                . $resources . ' /Contents ' . $contentId . ' 0 R >>';
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . $pageCount . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref\n0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= str_pad((string) ($offsets[$id] ?? 0), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }
}
