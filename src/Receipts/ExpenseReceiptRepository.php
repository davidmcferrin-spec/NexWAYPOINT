<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

use NexWaypoint\Core\Database;
use NexWaypoint\Core\Logger;

final class ExpenseReceiptRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function find(int $id): ?ExpenseReceipt
    {
        $row = $this->db->fetchOne('SELECT * FROM expense_receipts WHERE id = :id', ['id' => $id]);
        return $row === null ? null : ExpenseReceipt::fromRow($row);
    }

    public function findForOwner(int $id, int $ownerUserId): ?ExpenseReceipt
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM expense_receipts WHERE id = :id AND owner_user_id = :owner',
            ['id' => $id, 'owner' => $ownerUserId]
        );
        return $row === null ? null : ExpenseReceipt::fromRow($row);
    }

    /**
     * @return ExpenseReceipt[]
     */
    public function listForOwner(int $ownerUserId, bool $includeExpired = false): array
    {
        $sql = 'SELECT * FROM expense_receipts WHERE owner_user_id = :owner';
        if (!$includeExpired) {
            $sql .= ' AND expires_at > :now';
        }
        $sql .= ' ORDER BY travel_date DESC, id DESC';
        $params = ['owner' => $ownerUserId];
        if (!$includeExpired) {
            $params['now'] = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        }
        $rows = $this->db->fetchAll($sql, $params);
        return array_map(static fn (array $r): ExpenseReceipt => ExpenseReceipt::fromRow($r), $rows);
    }

    public function findGeneratedForTrip(int $ownerUserId, int $tripId): ?ExpenseReceipt
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM expense_receipts
             WHERE owner_user_id = :owner AND trip_id = :trip AND source = :src
             ORDER BY id DESC LIMIT 1",
            [
                'owner' => $ownerUserId,
                'trip' => $tripId,
                'src' => ExpenseReceipt::SOURCE_GENERATED,
            ]
        );
        return $row === null ? null : ExpenseReceipt::fromRow($row);
    }

    public function findGeneratedForStay(int $ownerUserId, int $hotelStayId): ?ExpenseReceipt
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM expense_receipts
             WHERE owner_user_id = :owner AND hotel_stay_id = :stay AND source = :src
             ORDER BY id DESC LIMIT 1",
            [
                'owner' => $ownerUserId,
                'stay' => $hotelStayId,
                'src' => ExpenseReceipt::SOURCE_GENERATED,
            ]
        );
        return $row === null ? null : ExpenseReceipt::fromRow($row);
    }

    public function create(ExpenseReceipt $receipt, ?int $actorUserId = null): ExpenseReceipt
    {
        $this->db->execute(
            'INSERT INTO expense_receipts (
                owner_user_id, kind, brand, location_label, travel_date, travel_end_date,
                confirmation_code, amount, currency, trip_id, hotel_stay_id, parse_log_id,
                source, title, original_filename, mime_type, file_path, file_size, expires_at
            ) VALUES (
                :owner, :kind, :brand, :loc, :tdate, :tend,
                :conf, :amount, :currency, :trip, :stay, :plog,
                :source, :title, :orig, :mime, :path, :size, :expires
            )',
            [
                'owner' => $receipt->ownerUserId,
                'kind' => $receipt->kind,
                'brand' => $receipt->brand,
                'loc' => $receipt->locationLabel,
                'tdate' => $receipt->travelDate,
                'tend' => $receipt->travelEndDate,
                'conf' => $receipt->confirmationCode,
                'amount' => $receipt->amount,
                'currency' => $receipt->currency,
                'trip' => $receipt->tripId,
                'stay' => $receipt->hotelStayId,
                'plog' => $receipt->parseLogId,
                'source' => $receipt->source,
                'title' => $receipt->title,
                'orig' => $receipt->originalFilename,
                'mime' => $receipt->mimeType,
                'path' => $receipt->filePath,
                'size' => $receipt->fileSize,
                'expires' => $receipt->expiresAt,
            ]
        );
        $id = (int) $this->db->lastInsertId();
        $this->db->audit($actorUserId, 'create', 'expense_receipts', $id, [
            'kind' => $receipt->kind,
            'source' => $receipt->source,
        ]);
        $created = $this->find($id);
        if ($created === null) {
            throw new \RuntimeException('Failed to load expense receipt after insert');
        }
        return $created;
    }

    public function updateFileMeta(
        int $id,
        string $filePath,
        int $fileSize,
        string $mimeType,
        string $expiresAt,
        ?string $originalFilename,
        ?int $actorUserId = null,
    ): ExpenseReceipt {
        $this->db->execute(
            'UPDATE expense_receipts SET
                file_path = :path,
                file_size = :size,
                mime_type = :mime,
                expires_at = :expires,
                original_filename = :orig,
                updated_at = :updated
             WHERE id = :id',
            [
                'path' => $filePath,
                'size' => $fileSize,
                'mime' => $mimeType,
                'expires' => $expiresAt,
                'orig' => $originalFilename,
                'updated' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'id' => $id,
            ]
        );
        $this->db->audit($actorUserId, 'update', 'expense_receipts', $id, [
            'file_path' => $filePath,
        ]);
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('Receipt missing after update');
        }
        return $row;
    }

    public function delete(int $id, ?int $actorUserId = null): void
    {
        $this->db->execute(
            'DELETE FROM expense_receipts WHERE id = :id',
            ['id' => $id]
        );
        $this->db->audit($actorUserId, 'delete', 'expense_receipts', $id, []);
    }

    /**
     * @return list<array{id: int|string, file_path?: ?string}>
     */
    public function findExpired(?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('now');
        return $this->db->fetchAll(
            'SELECT id, file_path FROM expense_receipts WHERE expires_at <= :now',
            ['now' => $asOf->format('Y-m-d H:i:s')]
        );
    }

    public function deleteByIds(array $ids, ?int $actorUserId = null): void
    {
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $this->delete($id, $actorUserId);
            }
        }
    }
}
