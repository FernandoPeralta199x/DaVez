<?php

declare(strict_types=1);

namespace DaVez\Infrastructure\Pdf;

use InvalidArgumentException;

/**
 * Gerador PDF mínimo e autocontido para relatórios operacionais.
 *
 * Não executa binários externos e não depende de fontes ou bibliotecas no
 * servidor. Usa as fontes padrão PDF (Helvetica/Helvetica-Bold) e mantém a
 * saída adequada a relatórios textuais simples.
 */
final class SimplePdfDocument
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN_X = 42.0;
    private const MARGIN_TOP = 48.0;
    private const MARGIN_BOTTOM = 46.0;

    /** @var array<int, string> */
    private $pages = [];

    /** @var string */
    private $commands = '';

    /** @var float */
    private $cursorY;

    /** @var string */
    private $documentTitle;

    public function __construct(string $documentTitle = 'Relatório DaVez')
    {
        $title = trim($documentTitle);
        $this->documentTitle = $title === '' ? 'Relatório DaVez' : $title;
        $this->cursorY = self::PAGE_HEIGHT - self::MARGIN_TOP;
        $this->beginPage();
    }

    public function addTitle(string $text): void
    {
        $this->ensureSpace(34);
        $this->text($text, 18, true, 0, 22);
        $this->line(0.35, 8);
    }

    public function addHeading(string $text): void
    {
        $this->ensureSpace(28);
        $this->spacer(5);
        $this->text($text, 12, true, 0, 17);
    }

    public function addParagraph(string $text): void
    {
        foreach ($this->wrap($text, 92) as $line) {
            $this->ensureSpace(14);
            $this->text($line, 9.5, false, 0, 13);
        }
        $this->spacer(3);
    }

    public function addKeyValue(string $label, string $value): void
    {
        $this->ensureSpace(15);
        $this->text($label . ':', 9.5, true, 0, 0);
        $this->text($value, 9.5, false, 125, 14);
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, scalar|null>> $rows
     * @param array<int, int>|null $columnCharacters
     */
    public function addTable(
        array $headers,
        array $rows,
        ?array $columnCharacters = null
    ): void {
        if ($headers === []) {
            throw new InvalidArgumentException('A tabela precisa de cabeçalhos.');
        }

        $columnCount = count($headers);
        $widths = $columnCharacters ?? array_fill(0, $columnCount, 20);
        if (count($widths) !== $columnCount) {
            throw new InvalidArgumentException('Larguras de tabela inválidas.');
        }

        $renderHeader = function () use ($headers, $widths): void {
            $this->ensureSpace(24);
            $this->line(0.45, 6);
            $x = 0;
            foreach ($headers as $index => $header) {
                $this->text(
                    $this->truncate((string) $header, $widths[$index]),
                    8.5,
                    true,
                    $x,
                    0
                );
                $x += $this->charactersToPoints($widths[$index]);
            }
            $this->cursorY -= 13;
            $this->line(0.25, 7);
        };

        $renderHeader();

        foreach ($rows as $row) {
            if (count($row) !== $columnCount) {
                throw new InvalidArgumentException('Linha de tabela inválida.');
            }

            if ($this->cursorY < self::MARGIN_BOTTOM + 26) {
                $this->finishPage();
                $this->beginPage();
                $renderHeader();
            }

            $x = 0;
            foreach (array_values($row) as $index => $value) {
                $this->text(
                    $this->truncate((string) ($value ?? ''), $widths[$index]),
                    8.3,
                    false,
                    $x,
                    0
                );
                $x += $this->charactersToPoints($widths[$index]);
            }
            $this->cursorY -= 13;
        }

        $this->line(0.25, 8);
    }

    public function addDivider(): void
    {
        $this->line(0.3, 9);
    }

    public function addSpacer(float $points = 8): void
    {
        $this->spacer($points);
    }

    public function render(): string
    {
        $this->finishPage();

        $pageCount = count($this->pages);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];
        for ($index = 0; $index < $pageCount; $index++) {
            $kids[] = (5 + ($index * 2)) . ' 0 R';
        }
        $objects[2] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', $kids),
            $pageCount
        );
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $index => $content) {
            $pageObject = 5 + ($index * 2);
            $contentObject = $pageObject + 1;
            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> '
                . '/Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentObject
            );
            $objects[$contentObject] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maximumObject = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maximumObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($number = 1; $number <= $maximumObject; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maximumObject + 1)
            . " /Root 1 0 R /Info << /Title ("
            . $this->escape($this->encode($this->documentTitle))
            . ") >> >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF\n";

        return $pdf;
    }

    private function beginPage(): void
    {
        $this->commands = '';
        $this->cursorY = self::PAGE_HEIGHT - self::MARGIN_TOP;
        $this->commands .= "q\n0.08 0.18 0.38 RG\n";
        $this->commands .= sprintf(
            "%.2F %.2F m %.2F %.2F l S\nQ\n",
            self::MARGIN_X,
            self::PAGE_HEIGHT - 31,
            self::PAGE_WIDTH - self::MARGIN_X,
            self::PAGE_HEIGHT - 31
        );
    }

    private function finishPage(): void
    {
        if ($this->commands === '') {
            return;
        }

        $pageNumber = count($this->pages) + 1;
        $footer = sprintf('DaVez • página %d', $pageNumber);
        $this->commands .= $this->textCommand(
            $footer,
            8,
            false,
            self::MARGIN_X,
            25
        );
        $this->pages[] = $this->commands;
        $this->commands = '';
    }

    private function ensureSpace(float $required): void
    {
        if ($this->cursorY - $required >= self::MARGIN_BOTTOM) {
            return;
        }
        $this->finishPage();
        $this->beginPage();
    }

    private function text(
        string $value,
        float $fontSize,
        bool $bold,
        float $offsetX,
        float $lineHeight
    ): void {
        $this->commands .= $this->textCommand(
            $value,
            $fontSize,
            $bold,
            self::MARGIN_X + $offsetX,
            $this->cursorY
        );
        if ($lineHeight > 0) {
            $this->cursorY -= $lineHeight;
        }
    }

    private function textCommand(
        string $value,
        float $fontSize,
        bool $bold,
        float $x,
        float $y
    ): string {
        $font = $bold ? 'F2' : 'F1';
        return sprintf(
            "BT /%s %.2F Tf 0.08 0.12 0.20 rg %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $fontSize,
            $x,
            $y,
            $this->escape($this->encode($value))
        );
    }

    private function line(float $thickness, float $after): void
    {
        $this->ensureSpace($after + 3);
        $y = $this->cursorY;
        $this->commands .= sprintf(
            "q %.2F w 0.68 0.75 0.86 RG %.2F %.2F m %.2F %.2F l S Q\n",
            $thickness,
            self::MARGIN_X,
            $y,
            self::PAGE_WIDTH - self::MARGIN_X,
            $y
        );
        $this->cursorY -= $after;
    }

    private function spacer(float $points): void
    {
        $this->ensureSpace($points);
        $this->cursorY -= max(0, $points);
    }

    /** @return array<int, string> */
    private function wrap(string $text, int $maximumCharacters): array
    {
        if ($maximumCharacters < 1) {
            throw new InvalidArgumentException('Largura de texto inválida.');
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($text));
        if (!is_string($normalized) || $normalized === '') {
            return [''];
        }

        $words = preg_split('/\s+/u', $normalized) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $remaining = $word;
            while ($this->stringLength($remaining) > $maximumCharacters) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = $this->stringSlice($remaining, 0, $maximumCharacters);
                $remaining = $this->stringSlice(
                    $remaining,
                    $maximumCharacters,
                    $this->stringLength($remaining)
                );
            }

            $candidate = $current === '' ? $remaining : $current . ' ' . $remaining;
            if ($this->stringLength($candidate) <= $maximumCharacters) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $remaining;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function truncate(string $text, int $maximumCharacters): string
    {
        if ($maximumCharacters < 1) {
            throw new InvalidArgumentException('Largura de coluna inválida.');
        }

        $clean = preg_replace('/\s+/u', ' ', trim($text));
        $clean = is_string($clean) ? $clean : '';
        if ($this->stringLength($clean) <= $maximumCharacters) {
            return $clean;
        }

        $sliceLength = max(1, $maximumCharacters - 3);
        return $this->stringSlice($clean, 0, $sliceLength) . '...';
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($characters) ? count($characters) : strlen($value);
    }

    private function stringSlice(string $value, int $offset, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $offset, $length, 'UTF-8');
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            return substr($value, $offset, $length);
        }
        return implode('', array_slice($characters, $offset, $length));
    }

    private function charactersToPoints(int $characters): float
    {
        return max(32, $characters * 4.55);
    }

    private function encode(string $value): string
    {
        if (function_exists('iconv')) {
            $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
            if (is_string($encoded)) {
                return $encoded;
            }
        }

        return strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N',
            '–' => '-', '—' => '-', '•' => '-', '“' => '"', '”' => '"',
            '’' => "'", '…' => '...',
        ]);
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', '', ' '],
            $value
        );
    }
}
