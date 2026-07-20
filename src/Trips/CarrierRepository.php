<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

/**
 * Site-wide airline / rail-operator catalog.
 * user_id on rows is the creating user (audit), not ownership — everyone shares the list.
 */
final class CarrierRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function find(int $id): ?Carrier
    {
        $row = $this->db->fetchOne('SELECT * FROM carriers WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Carrier::fromRow($row);
    }

    /**
     * @return Carrier[]
     */
    public function findByType(string $carrierType = Carrier::TYPE_AIRLINE): array
    {
        if ($this->hasCarrierTypeColumn()) {
            $rows = $this->db->fetchAll(
                'SELECT * FROM carriers WHERE carrier_type = :type ORDER BY name ASC',
                ['type' => $carrierType]
            );
        } else {
            if ($carrierType !== Carrier::TYPE_AIRLINE) {
                return [];
            }
            $rows = $this->db->fetchAll('SELECT * FROM carriers ORDER BY name ASC');
        }
        return array_map(static fn (array $row) => Carrier::fromRow($row), $rows);
    }

    /**
     * @deprecated Use findByType() — carriers are site-wide.
     * @return Carrier[]
     */
    public function findForUser(int $userId, string $carrierType = Carrier::TYPE_AIRLINE): array
    {
        unset($userId);
        return $this->findByType($carrierType);
    }

    public function findByName(string $name, ?string $carrierType = null): ?Carrier
    {
        if ($carrierType !== null && $this->hasCarrierTypeColumn()) {
            $row = $this->db->fetchOne(
                'SELECT * FROM carriers WHERE LOWER(name) = LOWER(:name) AND carrier_type = :type LIMIT 1',
                ['name' => trim($name), 'type' => $carrierType]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT * FROM carriers WHERE LOWER(name) = LOWER(:name) LIMIT 1',
                ['name' => trim($name)]
            );
        }
        return $row === null ? null : Carrier::fromRow($row);
    }

    public function findByIata(string $iata, ?string $carrierType = null): ?Carrier
    {
        if ($carrierType !== null && $this->hasCarrierTypeColumn()) {
            $row = $this->db->fetchOne(
                'SELECT * FROM carriers WHERE UPPER(iata_code) = UPPER(:iata) AND carrier_type = :type LIMIT 1',
                ['iata' => trim($iata), 'type' => $carrierType]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT * FROM carriers WHERE UPPER(iata_code) = UPPER(:iata) LIMIT 1',
                ['iata' => trim($iata)]
            );
        }
        return $row === null ? null : Carrier::fromRow($row);
    }

    /**
     * Find existing carrier by IATA or create one. Used by mail import.
     */
    public function findOrCreateByIata(
        int $createdByUserId,
        string $iata,
        string $name,
        ?int $actorUserId = null,
        string $carrierType = Carrier::TYPE_AIRLINE,
    ): Carrier {
        $iata = strtoupper(trim($iata));
        $existing = $this->findByIata($iata, $carrierType);
        if ($existing !== null) {
            return $existing;
        }
        return $this->create(new Carrier(
            id: null,
            userId: $createdByUserId,
            name: trim($name) !== '' ? trim($name) : $iata,
            iataCode: $iata,
            carrierType: $carrierType,
        ), $actorUserId);
    }

    /**
     * Find or create by name (rail operators often lack a stable IATA).
     */
    public function findOrCreateByName(
        int $createdByUserId,
        string $name,
        ?string $iataCode = null,
        ?int $actorUserId = null,
        string $carrierType = Carrier::TYPE_RAIL,
    ): Carrier {
        $name = trim($name);
        $existing = $this->findByName($name, $carrierType);
        if ($existing !== null) {
            return $existing;
        }
        if ($iataCode !== null && trim($iataCode) !== '') {
            $byIata = $this->findByIata($iataCode, $carrierType);
            if ($byIata !== null) {
                return $byIata;
            }
        }
        return $this->create(new Carrier(
            id: null,
            userId: $createdByUserId,
            name: $name,
            iataCode: $iataCode,
            carrierType: $carrierType,
        ), $actorUserId);
    }

    public function create(Carrier $carrier, ?int $actorUserId = null): Carrier
    {
        $requireIata = $carrier->carrierType === Carrier::TYPE_AIRLINE;
        $this->validate($carrier, requireIata: $requireIata);
        $this->assertUniqueIata($carrier);
        $this->assertUniqueName($carrier);

        if ($this->hasCarrierTypeColumn()) {
            $this->db->execute(
                'INSERT INTO carriers (user_id, name, iata_code, carrier_type)
                 VALUES (:user_id, :name, :iata_code, :carrier_type)',
                [
                    'user_id' => $carrier->userId,
                    'name' => trim($carrier->name),
                    'iata_code' => $carrier->iataCode !== null ? strtoupper($carrier->iataCode) : null,
                    'carrier_type' => $carrier->carrierType,
                ]
            );
        } else {
            $this->db->execute(
                'INSERT INTO carriers (user_id, name, iata_code) VALUES (:user_id, :name, :iata_code)',
                [
                    'user_id' => $carrier->userId,
                    'name' => trim($carrier->name),
                    'iata_code' => $carrier->iataCode !== null ? strtoupper($carrier->iataCode) : null,
                ]
            );
        }
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'carriers', $id, [
            'name' => $carrier->name,
            'carrier_type' => $carrier->carrierType,
        ]);
        $this->logger->info('Carrier created', [
            'id' => $id,
            'created_by' => $carrier->userId,
            'carrier_type' => $carrier->carrierType,
        ]);

        $created = $this->find($id);
        if ($created === null) {
            throw new \RuntimeException('Carrier insert succeeded but row could not be re-read.');
        }
        return $created;
    }

    public function update(Carrier $carrier, ?int $actorUserId = null): Carrier
    {
        if ($carrier->id === null) {
            throw new \InvalidArgumentException('Cannot update a Carrier without an id.');
        }
        $requireIata = $carrier->carrierType === Carrier::TYPE_AIRLINE;
        $this->validate($carrier, requireIata: $requireIata);
        $this->assertUniqueIata($carrier);
        $this->assertUniqueName($carrier);

        if ($this->hasCarrierTypeColumn()) {
            $this->db->execute(
                'UPDATE carriers SET name = :name, iata_code = :iata_code, carrier_type = :carrier_type,
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                [
                    'name' => trim($carrier->name),
                    'iata_code' => $carrier->iataCode !== null ? strtoupper($carrier->iataCode) : null,
                    'carrier_type' => $carrier->carrierType,
                    'id' => $carrier->id,
                ]
            );
        } else {
            $this->db->execute(
                'UPDATE carriers SET name = :name, iata_code = :iata_code, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                [
                    'name' => trim($carrier->name),
                    'iata_code' => $carrier->iataCode !== null ? strtoupper($carrier->iataCode) : null,
                    'id' => $carrier->id,
                ]
            );
        }
        $this->db->audit($actorUserId, 'update', 'carriers', $carrier->id, ['name' => $carrier->name]);

        $updated = $this->find($carrier->id);
        if ($updated === null) {
            throw new \RuntimeException('Carrier update succeeded but row could not be re-read.');
        }
        return $updated;
    }

    private function hasCarrierTypeColumn(): bool
    {
        return $this->db->tableExists('carriers') && $this->db->columnExists('carriers', 'carrier_type');
    }

    private function validate(Carrier $carrier, bool $requireIata): void
    {
        $errors = [];
        if (trim($carrier->name) === '') {
            $errors[] = 'Name is required.';
        }
        if (!in_array($carrier->carrierType, [Carrier::TYPE_AIRLINE, Carrier::TYPE_RAIL], true)) {
            $errors[] = 'Carrier type must be airline or rail.';
        }
        $iata = $carrier->iataCode !== null ? strtoupper(trim($carrier->iataCode)) : '';
        if ($requireIata && $iata === '') {
            $errors[] = 'IATA code is required (2–3 letters).';
        }
        if ($iata !== '' && !preg_match('/^[A-Z0-9]{2,3}$/', $iata)) {
            $errors[] = 'IATA code must be 2–3 letters/digits.';
        }
        if ($errors !== []) {
            throw new \InvalidArgumentException('Carrier validation failed: ' . implode(' ', $errors));
        }
    }

    private function assertUniqueIata(Carrier $carrier): void
    {
        if ($carrier->iataCode === null || trim($carrier->iataCode) === '') {
            return;
        }
        $existing = $this->findByIata($carrier->iataCode, $carrier->carrierType);
        if ($existing !== null && $existing->id !== $carrier->id) {
            throw new \InvalidArgumentException(
                "A {$carrier->carrierType} with IATA {$carrier->iataCode} already exists ({$existing->name})."
            );
        }
    }

    private function assertUniqueName(Carrier $carrier): void
    {
        $existing = $this->findByName($carrier->name, $carrier->carrierType);
        if ($existing !== null && $existing->id !== $carrier->id) {
            throw new \InvalidArgumentException(
                "A {$carrier->carrierType} named '{$carrier->name}' already exists."
            );
        }
    }
}
