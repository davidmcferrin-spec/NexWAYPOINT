<?php

declare(strict_types=1);

namespace NexWaypoint\Core;

/**
 * Site-wide key/value settings (appearance, etc.). Not for secrets — use Env.
 */
final class SiteSettingsRepository
{
    public const KEY_MAP_STYLE = 'map_basemap';
    public const KEY_UI_THEME_DEFAULT = 'ui_theme_default';
    public const KEY_UI_THEME_LOCK = 'ui_theme_lock';
    public const KEY_MAP_HOTEL_COLOR = 'map_hotel_color';
    public const KEY_MAP_VENUE_COLOR = 'map_venue_color';
    public const KEY_MAP_BLACKLIST_COLOR = 'map_blacklist_color';
    public const KEY_MAP_FEE_COLOR = 'map_fee_color';

    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function tableReady(): bool
    {
        return $this->db->tableExists('site_settings');
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (!$this->tableReady()) {
            return $default;
        }
        $row = $this->db->fetchOne(
            'SELECT setting_value FROM site_settings WHERE setting_key = :k LIMIT 1',
            ['k' => $key]
        );
        if ($row === null) {
            return $default;
        }
        $value = $row['setting_value'] ?? null;
        return $value === null || $value === '' ? $default : (string) $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $raw = $this->get($key);
        if ($raw === null) {
            return $default;
        }
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, string|null> $pairs
     * @return list<string> keys written
     */
    public function setMany(array $pairs, ?int $actorUserId = null): array
    {
        if (!$this->tableReady()) {
            throw new \RuntimeException('site_settings missing; run php scripts/migrate.php');
        }
        $changed = [];
        foreach ($pairs as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $value = $value === null ? '' : (string) $value;
            $existing = $this->db->fetchOne(
                'SELECT setting_key FROM site_settings WHERE setting_key = :k LIMIT 1',
                ['k' => $key]
            );
            if ($existing === null) {
                $this->db->execute(
                    'INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v)',
                    ['k' => $key, 'v' => $value]
                );
            } else {
                $this->db->execute(
                    'UPDATE site_settings SET setting_value = :v, updated_at = CURRENT_TIMESTAMP
                     WHERE setting_key = :k',
                    ['k' => $key, 'v' => $value]
                );
            }
            $changed[] = $key;
        }
        if ($changed !== []) {
            $this->db->audit($actorUserId, 'update', 'site_settings', null, ['keys' => $changed]);
            $this->logger->info('Site settings updated', ['keys' => $changed, 'actor' => $actorUserId]);
        }
        return $changed;
    }
}
