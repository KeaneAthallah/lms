<?php

namespace App\Exports;

/**
 * Comma-separated output, for anything that will be opened in a spreadsheet or
 * fed to something else.
 *
 * A spreadsheet is the overwhelmingly likely destination even of a CSV, so the
 * values are sanitised and a byte-order mark is written: without one, Excel
 * decodes a UTF-8 file as the local codepage and every accented name in the
 * gradebook comes out as mojibake.
 */
class CsvSpreadsheet implements Spreadsheet
{
    use InteractsWithCells;

    /**
     * @var array<int, array<int, string|null>>
     */
    private array $rows = [];

    public function __construct(private readonly string $name) {}

    public function addSheet(string $name, array $rows): void
    {
        // A CSV holds one table. Sheets are a spreadsheet concept, so a second one
        // is appended as a titled block rather than silently dropped.
        if ($this->rows !== []) {
            $this->rows[] = [null];
            $this->rows[] = [$name];
        }

        foreach ($rows as $row) {
            $this->rows[] = array_map(function (mixed $cell): ?string {
                $value = $this->normalize($cell)['value'];

                if ($value === null) {
                    return null;
                }

                return $this->safeText(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
            }, $row);
        }
    }

    public function filename(): string
    {
        return $this->name.'.csv';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function output(): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($this->rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\', "\n");
        }

        rewind($handle);

        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }
}
