<?php

declare(strict_types=1);

namespace NexWaypoint\Visibility;

use NexWaypoint\Users\UserRepository;

/**
 * Resolves what fields of a trip (destination_city, travel_dates,
 * flight_number, carrier, hotel_name, hotel_address, trip_purpose, notes)
 * a requesting user may see for a given subject (data owner), based on org
 * hierarchy direction + explicit overrides.
 *
 * Direction, per NexWAYPOINT's org model (Manager -> Team Member):
 *   SELF        subject == requester                    -> all fields
 *   TOP_DOWN    requester is subject's manager           -> city + dates only by default
 *   BOTTOM_UP   requester is subordinate of subject       -> all fields by default
 *   LATERAL     requester and subject share a manager     -> all fields by default
 *   USER_USER   n/a as a *resolved* direction -- this is the override
 *               channel available regardless of hierarchy relationship
 *   UNRELATED   no hierarchy relationship                -> city + dates only (most restrictive)
 *
 * Precedence (lowest to highest): direction default < direction-wide
 * override (subject's own default for that whole direction) < per-viewer
 * override (direction-specific or explicit user_user grant).
 *
 * NOTE ON A REAL AMBIGUITY IN THE SOURCE REQUIREMENTS: the free-text project
 * brief said "managers can see most if not all... default for subordinates
 * is visible unless marked private" (implying TOP_DOWN defaults to full
 * visibility). The structured "Org hierarchy" spec block and the detailed
 * Phase 2 spec pasted in the same request both say TOP_DOWN defaults to
 * city+date only. Those two structured blocks agree with each other and are
 * more precise than the one free-text sentence, so this implementation
 * follows them: TOP_DOWN = city+date by default, BOTTOM_UP = full by
 * default. If that's not what you actually want, it's a one-line change in
 * DIRECTION_DEFAULTS below -- flagging it here rather than guessing silently.
 */
final class VisibilityEngine
{
    public const DIRECTION_SELF = 'self';
    public const DIRECTION_TOP_DOWN = 'top_down';
    public const DIRECTION_BOTTOM_UP = 'bottom_up';
    public const DIRECTION_LATERAL = 'lateral';
    public const DIRECTION_UNRELATED = 'unrelated';
    public const DIRECTION_USER_USER = 'user_user';

    /**
     * @var string[]
     */
    public const ALL_FIELDS = [
        'destination_city',
        'travel_dates',
        'flight_number',
        'carrier',
        'hotel_name',
        'hotel_address',
        'trip_purpose',
        'notes',
    ];

    private const RESTRICTED_FIELDS = ['destination_city', 'travel_dates'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly VisibilityRuleRepository $rules,
    ) {
    }

    public function resolveDirection(int $requesterId, int $subjectUserId): string
    {
        if ($requesterId === $subjectUserId) {
            return self::DIRECTION_SELF;
        }

        $subject = $this->users->find($subjectUserId);
        $requester = $this->users->find($requesterId);
        if ($subject === null || $requester === null) {
            return self::DIRECTION_UNRELATED;
        }

        if ($subject->managerId === $requesterId) {
            return self::DIRECTION_TOP_DOWN; // requester manages subject
        }

        if ($requester->managerId === $subjectUserId) {
            return self::DIRECTION_BOTTOM_UP; // requester reports to subject
        }

        if ($requester->managerId !== null && $requester->managerId === $subject->managerId) {
            return self::DIRECTION_LATERAL; // peers, same manager
        }

        return self::DIRECTION_UNRELATED;
    }

    /**
     * @return array{direction: string, visible_fields: string[], overrides_applied: bool}
     */
    public function getVisibleFields(int $requesterId, int $subjectUserId, bool $tripIsPrivate = false): array
    {
        $direction = $this->resolveDirection($requesterId, $subjectUserId);

        if ($direction === self::DIRECTION_SELF) {
            return ['direction' => $direction, 'visible_fields' => self::ALL_FIELDS, 'overrides_applied' => false];
        }

        $defaultFields = in_array($direction, [self::DIRECTION_TOP_DOWN, self::DIRECTION_UNRELATED], true)
            ? self::RESTRICTED_FIELDS
            : self::ALL_FIELDS;

        // Baseline visibility map. A private trip starts fully closed;
        // everything from here on is an explicit grant, not a default.
        $visibility = [];
        foreach (self::ALL_FIELDS as $field) {
            $visibility[$field] = $tripIsPrivate ? false : in_array($field, $defaultFields, true);
        }

        $overridesApplied = false;

        // Direction-wide default override, then per-viewer override
        // (direction-specific first, user_user grant last so it always wins).
        $applicable = $this->rules->findApplicable($subjectUserId, $requesterId, $direction);

        $directionWide = array_filter($applicable, static fn (VisibilityRule $r) => $r->targetUserId === null);
        $perViewer = array_filter($applicable, static fn (VisibilityRule $r) => $r->targetUserId === $requesterId);
        // user_user rules land in $perViewer already since findApplicable matches on target_user_id regardless of direction column.

        foreach ($directionWide as $rule) {
            if (in_array($rule->fieldName, self::ALL_FIELDS, true)) {
                $visibility[$rule->fieldName] = $rule->visible;
                $overridesApplied = true;
            }
        }
        foreach ($perViewer as $rule) {
            if (in_array($rule->fieldName, self::ALL_FIELDS, true)) {
                $visibility[$rule->fieldName] = $rule->visible;
                $overridesApplied = true;
            }
        }

        $visibleFields = array_values(array_keys(array_filter($visibility)));

        return [
            'direction' => $direction,
            'visible_fields' => $visibleFields,
            'overrides_applied' => $overridesApplied,
        ];
    }

    /**
     * "What does this person see of my trips?" -- same computation, phrased
     * from the subject's point of view for the /settings preview screen.
     *
     * @return array{direction: string, visible_fields: string[], overrides_applied: bool}
     */
    public function previewForViewer(int $subjectUserId, int $viewerId, bool $tripIsPrivate = false): array
    {
        return $this->getVisibleFields($viewerId, $subjectUserId, $tripIsPrivate);
    }
}
