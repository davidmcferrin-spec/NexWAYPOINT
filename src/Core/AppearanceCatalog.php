<?php

declare(strict_types=1);

namespace NexWaypoint\Core;

/**
 * Controllable appearance options (map basemap + related colors).
 */
final class AppearanceCatalog
{
    /**
     * @return array<string, array{label: string, description: string, url: string, attribution: string, maxZoom: int}>
     */
    public static function mapBasemaps(): array
    {
        return [
            'carto_voyager' => [
                'label' => 'Carto Voyager',
                'description' => 'Colorful, modern — closest to Google Maps among free layers.',
                'url' => 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',
                'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
                'maxZoom' => 20,
            ],
            'carto_positron' => [
                'label' => 'Carto Positron',
                'description' => 'Light gray basemap; pins stand out.',
                'url' => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
                'maxZoom' => 20,
            ],
            'carto_dark' => [
                'label' => 'Carto Dark Matter',
                'description' => 'Dark basemap for night / dark UI.',
                'url' => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
                'maxZoom' => 20,
            ],
            'osm' => [
                'label' => 'OpenStreetMap Standard',
                'description' => 'Classic OSM look (default Leaflet demo style).',
                'url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                'maxZoom' => 19,
            ],
            'opentopo' => [
                'label' => 'OpenTopoMap',
                'description' => 'Terrain / topo shading.',
                'url' => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
                'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>)',
                'maxZoom' => 17,
            ],
        ];
    }

    public static function defaultMapBasemap(): string
    {
        return 'carto_voyager';
    }

    /**
     * @return array{id: string, label: string, description: string, url: string, attribution: string, maxZoom: int}
     */
    public static function resolveMapBasemap(?string $id): array
    {
        $maps = self::mapBasemaps();
        $id = $id !== null && isset($maps[$id]) ? $id : self::defaultMapBasemap();
        $row = $maps[$id];
        return [
            'id' => $id,
            'label' => $row['label'],
            'description' => $row['description'],
            'url' => $row['url'],
            'attribution' => $row['attribution'],
            'maxZoom' => $row['maxZoom'],
        ];
    }

    public static function defaultHotelColor(): string
    {
        return '#0369a1';
    }

    public static function defaultVenueColor(): string
    {
        return '#047857';
    }

    public static function defaultBlacklistColor(): string
    {
        return '#b91c1c';
    }

    public static function defaultFeeColor(): string
    {
        return '#a16207';
    }

    public static function normalizeHexColor(?string $value, string $fallback): string
    {
        $value = trim((string) $value);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1) {
            return strtolower($value);
        }
        return $fallback;
    }
}
