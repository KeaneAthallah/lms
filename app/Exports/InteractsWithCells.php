<?php

namespace App\Exports;

/**
 * Cell handling shared by the formats.
 */
trait InteractsWithCells
{
    /**
     * Unwrap the optional `['value' =>, 'style' =>]` cell form.
     *
     * @param  scalar|array{value: scalar|null, style: string|null}|null  $cell
     * @return array{value: scalar|null, style: string|null}
     */
    private function normalize(mixed $cell): array
    {
        if (! is_array($cell)) {
            return ['value' => $cell, 'style' => null];
        }

        return ['value' => $cell['value'] ?? null, 'style' => $cell['style'] ?? null];
    }

    /**
     * Make a string safe to open in a spreadsheet.
     *
     * A cell beginning `=`, `+`, `-` or `@` is a formula to Excel and Sheets, so a
     * student who renamed themselves `=cmd|' /C calc'!A0` would have their name
     * execute in the instructor's spreadsheet when the export is opened. Prefixing
     * with an apostrophe forces the cell to text, which is how the application
     * itself would have shown the name anyway.
     */
    private function safeText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
