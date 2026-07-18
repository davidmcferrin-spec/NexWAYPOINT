<?php

declare(strict_types=1);

namespace NexWaypont\Visibility;

final class VisibilityRule
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $subjectUserId,
        public readonly ?int $targetUserId,
        public readonly string $direction,
        public readonly string $fieldName,
        public readonly bool $visible,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            subjectUserId: (int) $row['subject_user_id'],
            targetUserId: isset($row['target_user_id']) && $row['target_user_id'] !== null ? (int) $row['target_user_id'] : null,
            direction: (string) $row['direction'],
            fieldName: (string) $row['field_name'],
            visible: (bool) $row['visible'],
        );
    }
}
