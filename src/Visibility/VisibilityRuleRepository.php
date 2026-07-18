<?php

declare(strict_types=1);

namespace NexWaypoint\Visibility;

use NexWaypoint\Core\Database;

final class VisibilityRuleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * All rules where the given user is the data owner (subject) -- i.e.
     * "my sharing rules", shown on /settings.
     *
     * @return VisibilityRule[]
     */
    public function findForSubject(int $subjectUserId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM visibility_rules WHERE subject_user_id = :id ORDER BY direction, target_user_id IS NULL DESC, field_name',
            ['id' => $subjectUserId]
        );
        return array_map(static fn (array $r) => VisibilityRule::fromRow($r), $rows);
    }

    /**
     * Rules relevant to resolving what $requesterId may see of $subjectUserId's
     * data: direction-wide defaults (target_user_id IS NULL) for the given
     * direction, plus any rule specific to this requester (direction-specific
     * or 'user_user').
     *
     * @return VisibilityRule[]
     */
    public function findApplicable(int $subjectUserId, int $requesterId, string $direction): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM visibility_rules
             WHERE subject_user_id = :subject
               AND (
                    (target_user_id IS NULL AND direction = :direction)
                 OR (target_user_id = :requester)
               )',
            ['subject' => $subjectUserId, 'direction' => $direction, 'requester' => $requesterId]
        );
        return array_map(static fn (array $r) => VisibilityRule::fromRow($r), $rows);
    }

    public function upsert(int $subjectUserId, ?int $targetUserId, string $direction, string $fieldName, bool $visible, ?int $actorUserId = null): VisibilityRule
    {
        $existing = $this->db->fetchOne(
            'SELECT * FROM visibility_rules WHERE subject_user_id = :subject AND target_user_id ' .
            ($targetUserId === null ? 'IS NULL' : '= :target') .
            ' AND direction = :direction AND field_name = :field',
            array_filter([
                'subject' => $subjectUserId,
                'target' => $targetUserId,
                'direction' => $direction,
                'field' => $fieldName,
            ], static fn ($v) => $v !== null)
        );

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE visibility_rules SET visible = :visible, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['visible' => $visible ? 1 : 0, 'id' => $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            $this->db->execute(
                'INSERT INTO visibility_rules (subject_user_id, target_user_id, direction, field_name, visible)
                 VALUES (:subject, :target, :direction, :field, :visible)',
                [
                    'subject' => $subjectUserId,
                    'target' => $targetUserId,
                    'direction' => $direction,
                    'field' => $fieldName,
                    'visible' => $visible ? 1 : 0,
                ]
            );
            $id = $this->db->lastInsertId();
        }

        $this->db->audit($actorUserId, 'upsert', 'visibility_rules', $id, [
            'subject_user_id' => $subjectUserId,
            'target_user_id' => $targetUserId,
            'direction' => $direction,
            'field_name' => $fieldName,
            'visible' => $visible,
        ]);

        $row = $this->db->fetchOne('SELECT * FROM visibility_rules WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new \RuntimeException('Visibility rule upsert succeeded but row could not be re-read.');
        }
        return VisibilityRule::fromRow($row);
    }

    public function delete(int $id, ?int $actorUserId = null): void
    {
        $this->db->execute('DELETE FROM visibility_rules WHERE id = :id', ['id' => $id]);
        $this->db->audit($actorUserId, 'delete', 'visibility_rules', $id, []);
    }
}
