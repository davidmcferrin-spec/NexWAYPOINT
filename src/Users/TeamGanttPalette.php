<?php

declare(strict_types=1);

namespace NexWaypoint\Users;

/**
 * Stable per-user hues and per-stay shades for the team calendar Gantt.
 */
final class TeamGanttPalette
{
    /** @var list<array{h: int, s: int}> */
    public const HUES = [
        ['h' => 199, 's' => 62],
        ['h' => 162, 's' => 48],
        ['h' => 258, 's' => 42],
        ['h' => 32, 's' => 72],
        ['h' => 340, 's' => 48],
        ['h' => 82, 's' => 38],
        ['h' => 280, 's' => 36],
        ['h' => 210, 's' => 32],
    ];

    /** Lightness steps for successive stay cities on one row. */
    public const LIGHTNESS = [40, 54, 66, 32, 72];

    public static function hueIndex(int $userId): int
    {
        return abs($userId) % count(self::HUES);
    }

    /**
     * @return array{h: int, s: int}
     */
    public static function hue(int $userId): array
    {
        return self::HUES[self::hueIndex($userId)];
    }

    public static function lightnessForShade(int $shadeIndex): int
    {
        $n = count(self::LIGHTNESS);
        return self::LIGHTNESS[(($shadeIndex % $n) + $n) % $n];
    }

    public static function foregroundForLightness(int $lightness): string
    {
        return $lightness >= 56 ? '#0f172a' : '#f8fafc';
    }

    /**
     * Assign shade 0, 1, 2… in first-seen city order (skipping home).
     *
     * @param list<string> $cells
     * @return array<string, int>
     */
    public static function shadeMap(array $cells, string $homeLabel): array
    {
        $map = [];
        $next = 0;
        foreach ($cells as $city) {
            $city = (string) $city;
            if ($city === '' || $city === $homeLabel || $city === 'Home') {
                continue;
            }
            if (!isset($map[$city])) {
                $map[$city] = $next++;
            }
        }
        return $map;
    }
}
