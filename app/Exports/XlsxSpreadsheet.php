<?php

namespace App\Exports;

use RuntimeException;
use ZipArchive;

/**
 * SpreadsheetML (`.xlsx`) written directly.
 *
 * A real `.xlsx` is a zip of XML parts, so this needs no package and produces a
 * file Excel, Numbers, LibreOffice and Google Sheets all open -- which is the
 * whole requirement. Only the parts a grid needs are written: the workbook, one
 * worksheet per sheet, and one styles part defining the handful of fills the
 * export uses. Strings are written inline rather than through a shared-string
 * table, which is one fewer index to keep in step for a grid that is written once
 * and thrown away.
 */
class XlsxSpreadsheet implements Spreadsheet
{
    use InteractsWithCells;

    /**
     * Cell formats, in the order they are declared in the styles part. The index
     * is what a cell's `s` attribute carries.
     */
    private const STYLE_DEFAULT = 0;

    private const STYLE_HEADER = 1;

    private const STYLE_ADJUSTED = 2;

    private const STYLE_EXCLUDED = 3;

    private const STYLE_NAMES = [
        self::STYLE_HEADER => 'header',
        self::STYLE_ADJUSTED => 'adjusted',
        self::STYLE_EXCLUDED => 'excluded',
    ];

    /**
     * @var array<int, array{name: string, rows: array}>
     */
    private array $sheets = [];

    public function __construct(private readonly string $name) {}

    public function addSheet(string $name, array $rows): void
    {
        $this->sheets[] = [
            'name' => $this->safeSheetName($name),
            'rows' => $rows,
        ];
    }

    public function filename(): string
    {
        return $this->name.'.xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function output(): string
    {
        if ($this->sheets === []) {
            throw new RuntimeException('An xlsx export needs at least one sheet.');
        }

        $path = tempnam(sys_get_temp_dir(), 'gradebook-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }

        try {
            $this->write($path);
            $contents = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if ($contents === false) {
            throw new RuntimeException('Could not read the generated export.');
        }

        return $contents;
    }

    private function write(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the export archive.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->packageRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($sheet['rows']));
        }

        $zip->close();
    }

    private function contentTypes(): string
    {
        $overrides = '';

        foreach (array_keys($this->sheets) as $index) {
            $overrides .= sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index + 1
            );
        }

        return $this->wrap(
            'Types',
            'http://schemas.openxmlformats.org/package/2006/content-types',
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        );
    }

    private function packageRels(): string
    {
        return $this->wrap(
            'Relationships',
            'http://schemas.openxmlformats.org/package/2006/relationships',
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        );
    }

    private function workbook(): string
    {
        $sheets = '';

        foreach ($this->sheets as $index => $sheet) {
            $sheets .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->escape($sheet['name']),
                $index + 1,
                $index + 1
            );
        }

        return $this->wrap(
            'workbook',
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
            $sheets,
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
        );
    }

    private function workbookRels(): string
    {
        $rels = '';

        foreach (array_keys($this->sheets) as $index) {
            $rels .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index + 1,
                $index + 1
            );
        }

        $rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return $this->wrap('Relationships', 'http://schemas.openxmlformats.org/package/2006/relationships', $rels);
    }

    /**
     * Two fills carry the meaning of the export: amber for a grade the instructor
     * adjusted, slate for one excluded from the course grade. Everything else is
     * the file's default look.
     */
    private function styles(): string
    {
        return $this->wrap(
            'styleSheet',
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
            '<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        );
    }

    private function worksheet(array $rows): string
    {
        $body = '';
        $rowNumber = 0;

        foreach ($rows as $row) {
            $rowNumber++;
            $cells = '';
            $column = 0;

            foreach ($row as $cell) {
                $cells .= $this->cell($cell, ++$column, $rowNumber);
            }

            $body .= sprintf('<row r="%d">%s</row>', $rowNumber, $cells);
        }

        return $this->wrap(
            'worksheet',
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
            $this->columns($rows).$body
        );
    }

    /**
     * A column wide enough for what it holds: the first two carry names and
     * addresses, the rest a percentage, the last two free text.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function columns(array $rows): string
    {
        $widths = [];

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $value = $this->normalize($cell)['value'];
                $length = is_string($value) ? mb_strlen($value) : 0;
                $widths[$index] = max($widths[$index] ?? 8, min($length + 2, 46));
            }
        }

        if ($widths === []) {
            return '';
        }

        $cols = '';

        foreach ($widths as $index => $width) {
            $position = $index + 1;
            $cols .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $position, $position, $width);
        }

        return sprintf('<cols>%s</cols>', $cols);
    }

    private function cell(mixed $cell, int $column, int $row): string
    {
        ['value' => $value, 'style' => $style] = $this->normalize($cell);
        $reference = $this->coordinate($column, $row);
        $index = $this->styleIndex($style);

        if ($value === null || $value === '') {
            // A styled but empty cell still has to be written, or an excluded
            // grade loses the fill that says it was excluded.
            return $index === self::STYLE_DEFAULT
                ? ''
                : sprintf('<c r="%s" s="%d"/>', $reference, $index);
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $index, $value);
        }

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $reference,
            $index,
            $this->escape($this->safeText((string) $value) ?? '')
        );
    }

    private function styleIndex(?string $style): int
    {
        $index = array_search($style, self::STYLE_NAMES, true);

        return $index === false ? self::STYLE_DEFAULT : $index;
    }

    private function coordinate(int $column, int $row): string
    {
        $letters = '';

        // A1, B1 ... Z1, AA1: bijective base-26, so column 26 is Z and 27 is AA.
        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $column = intdiv($column - 1, 26);
        }

        return $letters.$row;
    }

    /**
     * Excel's sheet names are limited and forbid a handful of characters.
     */
    private function safeSheetName(string $name): string
    {
        $safe = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name) ?? $name;
        $safe = trim($safe);

        return mb_substr($safe === '' ? 'Sheet1' : $safe, 0, 31);
    }

    private function escape(string $value): string
    {
        // Control characters are not representable in XML at all; drop them
        // rather than emit a file the reader will reject.
        return htmlspecialchars(
            preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );
    }

    private function wrap(string $root, string $namespace, string $body, ?string $relationshipsNamespace = null): string
    {
        $declarations = 'xmlns="'.$namespace.'"';

        if ($relationshipsNamespace !== null) {
            $declarations .= ' xmlns:r="'.$relationshipsNamespace.'"';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<'.$root.' '.$declarations.'>'.$body.'</'.$root.'>';
    }
}
