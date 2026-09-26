<?php

namespace App\Support;

/**
 * Builds upload validation rules from an allowlist.
 *
 * Two lessons are encoded here:
 *
 *  1. A missing or empty allowlist must never mean "accept anything". A caller
 *     with no list of its own falls back to a safe default.
 *  2. The globally forbidden list is subtracted from whatever the caller asked
 *     for, so an assignment that lists `php` still cannot receive one.
 */
final class UploadRules
{
    /**
     * @param  array<int, string>  $allowed  Extensions the caller permits, if any.
     * @return array<int, string>
     */
    public static function allowedExtensions(array $allowed, string $defaultConfigKey): array
    {
        $fallback = array_map('strtolower', (array) config('lms.'.$defaultConfigKey, []));

        $requested = array_values(array_filter(array_map('strtolower', $allowed)));

        $effective = $requested === [] ? $fallback : $requested;

        $forbidden = array_map('strtolower', (array) config('lms.forbidden_upload_extensions', []));

        return array_values(array_diff($effective, $forbidden));
    }

    /**
     * The rule set for a single uploaded file.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    public static function fileRules(array $allowed, string $defaultConfigKey, int $maxKb): array
    {
        return [
            'file',
            'mimes:'.implode(',', self::allowedExtensions($allowed, $defaultConfigKey)),
            'max:'.$maxKb,
        ];
    }
}
