<?php

namespace App\Support\Grading;

/**
 * The single definition of "the same text" for free-response grading.
 *
 * Short answer and fill-in-the-blank both compare typed text against an
 * accepted value, and they must agree: if one trims and the other does not, a
 * student sees inconsistent behaviour between two questions that look identical
 * on screen.
 */
final class TextComparison
{
    /**
     * Case-insensitive, whitespace-trimmed comparison.
     *
     * Deliberately `strtolower` rather than `mb_strtolower`. That is a real
     * limitation for non-ASCII answers and is a known follow-up, but changing
     * it has to be a deliberate, tested change rather than a side effect of
     * adding a second free-text type.
     */
    public static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function matches(string $given, string $accepted): bool
    {
        return self::normalize($given) === self::normalize($accepted);
    }
}
