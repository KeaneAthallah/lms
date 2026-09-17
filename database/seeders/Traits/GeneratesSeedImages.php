<?php

namespace Database\Seeders\Traits;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

trait GeneratesSeedImages
{
    private function writeSeedSvg(string $directory, string $filename, string $svg): string
    {
        Storage::disk('public')->put("{$directory}/{$filename}", $svg);

        return "{$directory}/{$filename}";
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function wrapTitle(string $title): array
    {
        $words = preg_split('/\s+/', trim($title)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($word) + 1 > 26) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $current === '' ? $word : $current.' '.$word;
            }

            if (count($lines) === 2) {
                break;
            }
        }

        $lines[] = $current;

        return array_slice($lines, 0, 2);
    }

    private function courseCoverSvg(string $title, string $colorFrom, string $colorTo): string
    {
        $lines = $this->wrapTitle($title);
        $fontSize = max(mb_strlen($lines[0]) > 20 ? 52 : 68, 44);
        $text = '';

        foreach ($lines as $index => $line) {
            $y = 340 + ($index * ($fontSize + 14));
            $text .= sprintf('<text x="80" y="%d" font-family="-apple-system, Segoe UI, Roboto, sans-serif" font-size="%d" font-weight="700" fill="#ffffff">%s</text>', $y, $fontSize, $this->escapeXml($line));
        }

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">
              <defs>
                <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="{$colorFrom}"/>
                  <stop offset="1" stop-color="{$colorTo}"/>
                </linearGradient>
                <radialGradient id="glow" cx="0.75" cy="0.2" r="0.9">
                  <stop offset="0" stop-color="#ffffff" stop-opacity="0.28"/>
                  <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
                </radialGradient>
              </defs>
              <rect width="1280" height="720" fill="url(#bg)"/>
              <rect width="1280" height="720" fill="url(#glow)"/>
              <g fill="#ffffff" fill-opacity="0.10">
                <circle cx="60" cy="60" r="6"/><circle cx="180" cy="120" r="4"/><circle cx="340" cy="40" r="7"/>
                <circle cx="1100" cy="90" r="6"/><circle cx="1220" cy="300" r="4"/><circle cx="1180" cy="620" r="7"/>
                <circle cx="1040" cy="660" r="5"/><circle cx="200" cy="640" r="5"/><circle cx="80" cy="420" r="4"/>
                <circle cx="420" cy="700" r="6"/><circle cx="900" cy="120" r="5"/>
              </g>
              <circle cx="1050" cy="540" r="230" fill="#ffffff" fill-opacity="0.08"/>
              <circle cx="1180" cy="620" r="120" fill="#ffffff" fill-opacity="0.10"/>
              <g transform="translate(80 210)" fill="#ffffff" fill-opacity="0.85">
                <rect x="0" y="0" width="8" height="44" rx="4" transform="rotate(45 4 22)"/>
              </g>
              <text x="80" y="250" font-family="-apple-system, Segoe UI, Roboto, sans-serif" font-size="24" font-weight="600" letter-spacing="4" fill="#ffffff" fill-opacity="0.85">LEARNING COURSE</text>
              {$text}
              <text x="80" y="660" font-family="-apple-system, Segoe UI, Roboto, sans-serif" font-size="18" fill="#ffffff" fill-opacity="0.6">Interactive lessons · quizzes · assignments · certificates</text>
            </svg>
            SVG;
    }

    private function avatarSvg(string $name, string $colorFrom, string $colorTo): string
    {
        $initials = collect(explode(' ', trim($name)))
            ->filter()
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">
              <defs>
                <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="{$colorFrom}"/>
                  <stop offset="1" stop-color="{$colorTo}"/>
                </linearGradient>
              </defs>
              <rect width="400" height="400" fill="url(#bg)"/>
              <circle cx="320" cy="80" r="140" fill="#ffffff" fill-opacity="0.08"/>
              <circle cx="60" cy="360" r="90" fill="#ffffff" fill-opacity="0.08"/>
              <text x="200" y="215" text-anchor="middle" font-family="-apple-system, Segoe UI, Roboto, sans-serif" font-size="120" font-weight="700" fill="#ffffff">{$this->escapeXml($initials)}</text>
            </svg>
            SVG;
    }

    private function coursePalette(string $categorySlug): array
    {
        return match ($categorySlug) {
            'web-development' => ['#2f50d8', '#22327e'],
            'ui-ux-design' => ['#7c3aed', '#4c1d95'],
            'data-science' => ['#059669', '#064e3b'],
            'business' => ['#d97706', '#92400e'],
            default => ['#2f50d8', '#22327e'],
        };
    }

    private function paletteFor(string $seed): array
    {
        $palettes = [
            ['#0ea5e9', '#1e3a8a'],
            ['#8b5cf6', '#4c1d95'],
            ['#ec4899', '#831843'],
            ['#f97316', '#7c2d12'],
            ['#10b981', '#064e3b'],
            ['#64748b', '#1e293b'],
            ['#f43f5e', '#881337'],
        ];

        $index = 0;
        foreach (str_split($seed) as $char) {
            $index = ($index + ord($char)) % count($palettes);
        }

        return $palettes[$index];
    }

    private function withCourseCover(Course $course): void
    {
        [$from, $to] = $this->coursePalette($course->category?->slug ?? 'default');

        $svg = $this->courseCoverSvg($course->title, $from, $to);

        $course->forceFill([
            'thumbnail_path' => $this->writeSeedSvg('course-covers', $course->slug.'.svg', $svg),
        ])->save();
    }

    private function withAvatar(User $user): void
    {
        [$from, $to] = $this->paletteFor($user->email);

        $svg = $this->avatarSvg($user->name, $from, $to);

        $user->forceFill([
            'avatar_path' => $this->writeSeedSvg('avatars', $user->id.'-'.strtolower(str_replace(' ', '-', $user->name)).'.svg', $svg),
        ])->save();
    }
}
