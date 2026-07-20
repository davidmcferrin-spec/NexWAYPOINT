<?php

declare(strict_types=1);

namespace NexWaypoint\Hotels;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

/**
 * Site-wide hotel brand catalog for property form dropdowns.
 */
final class HotelBrandRepository
{
    /** @var list<string> */
    public const DEFAULT_BRANDS = [
        'Marriott',
        'Hilton',
        'IHG',
        'Hyatt',
        'Choice Hotels',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function tableReady(): bool
    {
        return $this->db->tableExists('hotel_brands');
    }

    public function find(int $id): ?HotelBrand
    {
        $row = $this->db->fetchOne('SELECT * FROM hotel_brands WHERE id = :id', ['id' => $id]);
        return $row === null ? null : HotelBrand::fromRow($row);
    }

    /**
     * @return HotelBrand[]
     */
    public function findActive(): array
    {
        if (!$this->tableReady()) {
            return $this->fallbackDefaults();
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM hotel_brands WHERE is_active = 1 ORDER BY sort_order ASC, name ASC'
        );
        return array_map(static fn (array $r) => HotelBrand::fromRow($r), $rows);
    }

    /**
     * @return HotelBrand[]
     */
    public function findAll(): array
    {
        if (!$this->tableReady()) {
            return $this->fallbackDefaults();
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM hotel_brands ORDER BY sort_order ASC, name ASC'
        );
        return array_map(static fn (array $r) => HotelBrand::fromRow($r), $rows);
    }

    /**
     * Active brand names for dropdowns, plus $extra if not already listed
     * (preserves legacy / mail-imported values on edit).
     *
     * @return list<string>
     */
    public function namesForSelect(?string $extra = null): array
    {
        $names = [];
        foreach ($this->findActive() as $brand) {
            $names[] = $brand->name;
        }
        if ($extra !== null && trim($extra) !== '') {
            $extra = trim($extra);
            $found = false;
            foreach ($names as $name) {
                if (strcasecmp($name, $extra) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $names[] = $extra;
            }
        }
        return $names;
    }

    public function create(string $name, ?int $actorUserId = null): HotelBrand
    {
        if (!$this->tableReady()) {
            throw new \RuntimeException('hotel_brands table missing; run php scripts/migrate.php');
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Brand name is required.');
        }
        if (strlen($name) > 100) {
            throw new \InvalidArgumentException('Brand name must be 100 characters or fewer.');
        }

        $existing = $this->db->fetchOne(
            'SELECT * FROM hotel_brands WHERE LOWER(name) = LOWER(:n) LIMIT 1',
            ['n' => $name]
        );
        if ($existing !== null) {
            if (!empty($existing['is_active'])) {
                throw new \InvalidArgumentException("Brand '{$name}' already exists.");
            }
            $this->db->execute(
                'UPDATE hotel_brands SET is_active = 1, name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['name' => $name, 'id' => $existing['id']]
            );
            $this->db->audit($actorUserId, 'reactivate', 'hotel_brands', (int) $existing['id'], ['name' => $name]);
            $brand = $this->find((int) $existing['id']);
            if ($brand === null) {
                throw new \RuntimeException('Brand reactivate succeeded but row could not be re-read.');
            }
            return $brand;
        }

        $maxSort = $this->db->fetchOne('SELECT COALESCE(MAX(sort_order), 0) AS m FROM hotel_brands');
        $sort = (int) ($maxSort['m'] ?? 0) + 10;

        $this->db->execute(
            'INSERT INTO hotel_brands (name, sort_order, is_active) VALUES (:name, :sort, 1)',
            ['name' => $name, 'sort' => $sort]
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'hotel_brands', $id, ['name' => $name]);
        $this->logger->info('Hotel brand created', ['id' => $id, 'name' => $name]);

        $brand = $this->find($id);
        if ($brand === null) {
            throw new \RuntimeException('Brand insert succeeded but row could not be re-read.');
        }
        return $brand;
    }

    public function delete(int $id, ?int $actorUserId = null): void
    {
        if (!$this->tableReady()) {
            throw new \RuntimeException('hotel_brands table missing; run php scripts/migrate.php');
        }
        $existing = $this->find($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Brand not found.');
        }
        // Soft-delete so existing property.brand text still displays; option drops from dropdown.
        $this->db->execute(
            'UPDATE hotel_brands SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $id]
        );
        $this->db->audit($actorUserId, 'deactivate', 'hotel_brands', $id, ['name' => $existing->name]);
        $this->logger->info('Hotel brand deactivated', ['id' => $id, 'name' => $existing->name]);
    }

    /**
     * @return HotelBrand[]
     */
    private function fallbackDefaults(): array
    {
        $out = [];
        foreach (self::DEFAULT_BRANDS as $i => $name) {
            $out[] = new HotelBrand(null, $name, ($i + 1) * 10, true);
        }
        return $out;
    }
}
