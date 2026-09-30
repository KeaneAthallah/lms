<?php

namespace App\Exports;

/**
 * A grid to hand to a spreadsheet, in whichever format the caller asked for.
 *
 * A cell is a scalar, or `['value' => ..., 'style' => 'adjusted']` when the
 * format can express more than the value. CSV flattens it to the value, XLSX
 * honours the style, so a caller describes what a cell *means* once and does not
 * branch on the format.
 */
interface Spreadsheet
{
    /**
     * @param  string  $name  sheet name; XLSX limits these to 31 characters
     * @param  array<int, array<int, scalar|array{value: scalar|null, style: string|null}|null>>  $rows
     */
    public function addSheet(string $name, array $rows): void;

    public function filename(): string;

    public function contentType(): string;

    public function output(): string;
}
