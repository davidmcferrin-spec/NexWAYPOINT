<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

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
    public function findForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM carriers WHERE user_id = :user_id ORDER BY name ASC',
            ['user_id' => $userId]
        );
        return array_map(static fn (array $row) => Carrier::fromRow($row), $rows);
    }

    public function findByName(int $userId, string $name): ?Carrier
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM carriers WHERE user_id = :user_id AND LOWER(name) = LOWER(:name) LIMIT 1',
            ['user_id' => $userId, 'name' => trim($name)]
        );
        return $row === null ? null : Carrier::fromRow($row);
    }

    public function findByIata(int $userId, string $iata): ?Carrier
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM carriers WHERE user_id = :user_id AND UPPER(iata_code) = UPPER(:iata) LIMIT 1',
            ['user_id' => $userId, 'iata' => trim($iata)]
        );
        return $row === null ? null : Carrier::fromRow($row);
    }

    /**
     * Find existing carrier by IATA or create one. Used by mail import.
     */
    public function findOrCreateByIata(int $userId, string $iata, string $name, ?int $actorUserId = null): Carrier
    {
        $iata = strtoupper(trim($iata));
        $existing = $this->findByIata($userId, $iata);
        if ($existing !== null) {
            return $existing;
        }
        return $this->create(new Carrier(
            id: null,
            userId: $userId,
            name: trim($name) !== '' ? trim($name) : $iata,
            iataCode: $iata,
        ), $actorUserId);
    }

    public function create(Carrier $carrier, ?int $actorUserId = null): Carrier
    {
        $this->validate($carrier, requireIata: true);
        $this->assertUniqueIata($carrier);

        $this->db->execute(
            'INSERT INTO carriers (user_id, name, iata_code) VALUES (:user_id, :name, :iata_code)',
            [
                'user_id' => $carrier->userId,
                'name' => trim($carrier->name),
                'iata_code' => $carrier->iataCode !== null ? strtoupper($carrier->iataCode) : null,
            ]
        );
        $id = $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'carriers', $id, ['name' => $carrier->name]);
        $this->logger->info('Carrier created', ['id' => $id, 'user_id' => $carrier->userId]);

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
        $this->validate($carrier, requireIata: true);
        $this->assertUniqueIata($carrier);

        $this->db->execute(
            'UPDATE carriers SET name = :name, iata_code = :iata_code, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            [
                'name' => trim($carrier->name),
                'iata_code' => $carrier->iataCode !== null ? strtoupper($carrier->iataCode) : null,
                'id' => $carrier->id,
            ]
        );
        $this->db->audit($actorUserId, 'update', 'carriers', $carrier->id, ['name' => $carrier->name]);

        $updated = $this->find($carrier->id);
        if ($updated === null) {
            throw new \RuntimeException('Carrier update succeeded but row could not be re-read.');
        }
        return $updated;
    }

    private function validate(Carrier $carrier, bool $requireIata): void
    {
        $errors = [];
        if (trim($carrier->name) === '') {
            $errors[] = 'Carrier name is required.';
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
        $existing = $this->findByIata($carrier->userId, $carrier->iataCode);
        if ($existing !== null && $existing->id !== $carrier->id) {
            throw new \InvalidArgumentException(
                "You already have a carrier with IATA {$carrier->iataCode} ({$existing->name})."
            );
        }
    }
}
