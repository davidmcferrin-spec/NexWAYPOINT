<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Calendar\CalendarFeed;
use NexWaypoint\Calendar\CalendarFeedRepository;
use NexWaypoint\Calendar\IcsBuilder;
use NexWaypoint\Calendar\IcsEvent;
use NexWaypoint\Calendar\PersonalTravelFeedBuilder;
use NexWaypoint\Calendar\TeamTravelFeedBuilder;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\Trip;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripSegment;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

final class CalendarFeedTest extends NexWaypointTestCase
{
    public function testEnsureCreatesUniqueTokensPerKind(): void
    {
        $ownerId = $this->insertUser('cal_owner');
        $repo = new CalendarFeedRepository($this->db, $this->logger);

        $personal = $repo->ensureForOwner($ownerId, CalendarFeed::KIND_PERSONAL, $ownerId);
        $team = $repo->ensureForOwner($ownerId, CalendarFeed::KIND_TEAM, $ownerId);
        $personalAgain = $repo->ensureForOwner($ownerId, CalendarFeed::KIND_PERSONAL, $ownerId);

        self::assertSame($personal->id, $personalAgain->id);
        self::assertSame($personal->token, $personalAgain->token);
        self::assertNotSame($personal->token, $team->token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $personal->token);
        self::assertSame(CalendarFeed::KIND_PERSONAL, $personal->kind);
        self::assertSame(CalendarFeed::KIND_TEAM, $team->kind);
    }

    public function testRotateInvalidatesOldToken(): void
    {
        $ownerId = $this->insertUser('cal_rotate');
        $repo = new CalendarFeedRepository($this->db, $this->logger);
        $feed = $repo->ensureForOwner($ownerId, CalendarFeed::KIND_PERSONAL, $ownerId);
        $old = $feed->token;

        $rotated = $repo->rotateToken((int) $feed->id, $ownerId, $ownerId);

        self::assertNotSame($old, $rotated->token);
        self::assertNull($repo->findByToken($old));
        self::assertNotNull($repo->findByToken($rotated->token));
    }

    public function testFindByTokenRejectsGarbage(): void
    {
        $repo = new CalendarFeedRepository($this->db, $this->logger);
        self::assertNull($repo->findByToken(''));
        self::assertNull($repo->findByToken('not-a-token'));
        self::assertNull($repo->findByToken(str_repeat('x', 64)));
    }

    public function testIcsBuilderEscapesAndEmitsUtc(): void
    {
        $ics = (new IcsBuilder())->build('Test Cal', [
            new IcsEvent(
                uid: 'nxwp-test-1@nexwaypoint',
                summary: 'Flight · HSV → DEN',
                description: "Line1\nLine2; special, chars",
                location: 'HSV → DEN',
                dtStart: '2026-08-14T13:00:00Z',
                dtEnd: '2026-08-14T15:30:00Z',
                allDay: false,
                categories: ['NexWAYPOINT', 'Flight'],
            ),
            new IcsEvent(
                uid: 'nxwp-test-2@nexwaypoint',
                summary: 'Trip · Denver',
                dtStart: '2026-08-14',
                dtEnd: '2026-08-17',
                allDay: true,
            ),
        ]);

        self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
        self::assertStringContainsString('METHOD:PUBLISH', $ics);
        self::assertStringContainsString('X-WR-CALNAME:Test Cal', $ics);
        self::assertStringContainsString('UID:nxwp-test-1@nexwaypoint', $ics);
        self::assertStringContainsString('DTSTART:20260814T130000Z', $ics);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20260814', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20260817', $ics);
        self::assertStringContainsString('DESCRIPTION:Line1\\nLine2\\; special\\, chars', $ics);
        // Decorative unicode normalized for Outlook; CATEGORIES commas stay separators.
        self::assertStringContainsString('SUMMARY:Flight - HSV -> DEN', $ics);
        self::assertStringContainsString('LOCATION:HSV -> DEN', $ics);
        self::assertStringContainsString('SUMMARY:Trip - Denver', $ics);
        self::assertStringContainsString('CATEGORIES:NexWAYPOINT,Flight', $ics);
        self::assertStringNotContainsString('NexWAYPOINT\\,Flight', $ics);
        self::assertStringEndsWith("\r\n", $ics);
    }

    public function testIcsBuilderFoldsWithoutSplittingUtf8(): void
    {
        // Long prefix so the fold lands near a multi-byte city name character.
        $summary = str_repeat('A', 70) . 'São Paulo';
        $ics = (new IcsBuilder())->build('Fold Cal', [
            new IcsEvent(
                uid: 'nxwp-fold@nexwaypoint',
                summary: $summary,
                dtStart: '2026-08-14T13:00:00Z',
                dtEnd: '2026-08-14T14:00:00Z',
                allDay: false,
            ),
        ]);

        self::assertSame(1, preg_match('//u', $ics), 'folded ICS must remain valid UTF-8');
        self::assertStringContainsString('São Paulo', str_replace(["\r\n ", "\r\n"], '', $ics));
    }

    public function testPersonalFeedIncludesTripAndFlight(): void
    {
        $userId = $this->insertUser('cal_personal');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $day = new \DateTimeImmutable('today');
        $start = $day->modify('+3 days');
        $end = $start->modify('+2 days');

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver, CO',
            startDate: $start->format('Y-m-d'),
            endDate: $end->format('Y-m-d'),
            status: 'planned',
            tripPurpose: 'Shoot',
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '482',
            confirmationCode: 'ABC123',
            origin: 'HSV',
            destination: 'DEN',
            departDt: $start->setTime(8, 0)->format('Y-m-d H:i:s'),
            arriveDt: $start->setTime(10, 30)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $events = (new PersonalTravelFeedBuilder(
            $tripRepo,
            new AirportRepository(null, $this->logger),
        ))->buildEvents($userId, $day);

        $summaries = array_map(static fn (IcsEvent $e) => $e->summary, $events);
        self::assertNotEmpty($events);
        self::assertTrue(
            (bool) array_filter($summaries, static fn (string $s) => str_contains($s, 'UA') && str_contains($s, '482')),
            'expected flight event'
        );
        self::assertTrue(
            (bool) array_filter($summaries, static fn (string $s) => str_starts_with($s, 'In ') && str_contains($s, 'Denver')),
            'expected In Denver presence after arrival'
        );
        self::assertFalse(
            (bool) array_filter($summaries, static fn (string $s) => str_starts_with($s, 'Trip -')),
            'trip all-day should be omitted when transit legs exist'
        );

        $body = (new IcsBuilder())->build('Personal', $events);
        self::assertStringContainsString('nxwp-presence-', $body);
        self::assertStringContainsString('Confirmation: ABC123', $body);
    }

    public function testPersonalFeedPresenceBetweenLegsAndMidTrip(): void
    {
        $userId = $this->insertUser('cal_mid');
        // Home base so return flight does not create an "In Huntsville" block.
        if ($this->db->columnExists('users', 'home_city')) {
            $this->db->execute(
                'UPDATE users SET home_city = :c, home_state = :s WHERE id = :id',
                ['c' => 'Huntsville', 's' => 'AL', 'id' => $userId]
            );
        }

        $tripRepo = new TripRepository($this->db, $this->logger);
        $today = new \DateTimeImmutable('today');
        $outbound = $today->modify('-1 day');
        $inbound = $today->modify('+2 days');

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver, CO',
            startDate: $outbound->format('Y-m-d'),
            endDate: $inbound->format('Y-m-d'),
            status: 'active',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '100',
            confirmationCode: 'MID1',
            origin: 'HSV',
            destination: 'DEN',
            departDt: $outbound->setTime(8, 0)->format('Y-m-d H:i:s'),
            arriveDt: $outbound->setTime(10, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '200',
            confirmationCode: 'MID1',
            origin: 'DEN',
            destination: 'HSV',
            departDt: $inbound->setTime(16, 0)->format('Y-m-d H:i:s'),
            arriveDt: $inbound->setTime(19, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $owner = (new UserRepository($this->db, $this->logger))->find($userId);
        self::assertNotNull($owner);

        $events = (new PersonalTravelFeedBuilder(
            $tripRepo,
            new AirportRepository(null, $this->logger),
        ))->buildEvents($owner, $today);

        $summaries = array_map(static fn (IcsEvent $e) => $e->summary, $events);
        self::assertTrue(
            (bool) array_filter($summaries, static fn (string $s) => str_contains($s, 'UA 100') || str_contains($s, 'UA') && str_contains($s, '100')),
            'outbound flight'
        );
        self::assertTrue(
            (bool) array_filter($summaries, static fn (string $s) => str_contains($s, '200')),
            'return flight'
        );
        self::assertTrue(
            (bool) array_filter($summaries, static fn (string $s) => str_starts_with($s, 'In ') && str_contains($s, 'Denver')),
            'In Denver between legs'
        );
        self::assertFalse(
            (bool) array_filter($summaries, static fn (string $s) => str_contains($s, 'In Huntsville')),
            'no presence after re-base home'
        );
    }

    public function testFindInDateWindowOverlapsLookbackAndAhead(): void
    {
        $userId = $this->insertUser('cal_window');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $asOf = new \DateTimeImmutable('2026-08-07');

        $inLookback = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver, CO',
            startDate: '2026-07-20',
            endDate: '2026-07-28', // ends inside 14-day lookback
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tooOld = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Chicago, IL',
            startDate: '2026-07-01',
            endDate: '2026-07-10', // entirely before lookback
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $farAhead = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Seattle, WA',
            startDate: '2026-11-20', // after +90d (2026-11-05)
            endDate: '2026-11-25',
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $inAhead = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Atlanta, GA',
            startDate: '2026-10-20',
            endDate: '2026-10-22',
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));

        $ids = array_map(
            static fn (Trip $t) => (int) $t->id,
            $tripRepo->findInDateWindow($userId, 14, 90, $asOf)
        );

        self::assertContains((int) $inLookback->id, $ids);
        self::assertContains((int) $inAhead->id, $ids);
        self::assertNotContains((int) $tooOld->id, $ids);
        self::assertNotContains((int) $farAhead->id, $ids);
    }

    public function testPersonalFeedIncludesWholeTripWhenReturnInLookback(): void
    {
        $userId = $this->insertUser('cal_lookback_full');
        if ($this->db->columnExists('users', 'home_city')) {
            $this->db->execute(
                'UPDATE users SET home_city = :c, home_state = :s WHERE id = :id',
                ['c' => 'Huntsville', 's' => 'AL', 'id' => $userId]
            );
        }

        $tripRepo = new TripRepository($this->db, $this->logger);
        $asOf = new \DateTimeImmutable('2026-08-07');
        // Outbound before the 14-day lookback; return still inside the window.
        $outbound = new \DateTimeImmutable('2026-07-18');
        $inbound = new \DateTimeImmutable('2026-07-28');

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Denver, CO',
            startDate: $outbound->format('Y-m-d'),
            endDate: $inbound->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '301',
            confirmationCode: 'LB1',
            origin: 'HSV',
            destination: 'DEN',
            departDt: $outbound->setTime(8, 0)->format('Y-m-d H:i:s'),
            arriveDt: $outbound->setTime(10, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'UA',
            flightNumber: '302',
            confirmationCode: 'LB1',
            origin: 'DEN',
            destination: 'HSV',
            departDt: $inbound->setTime(16, 0)->format('Y-m-d H:i:s'),
            arriveDt: $inbound->setTime(19, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $owner = (new UserRepository($this->db, $this->logger))->find($userId);
        self::assertNotNull($owner);

        $events = (new PersonalTravelFeedBuilder(
            $tripRepo,
            new AirportRepository(null, $this->logger),
        ))->buildEvents($owner, $asOf);

        $summaries = array_map(static fn (IcsEvent $e) => $e->summary, $events);
        self::assertTrue(
            (bool) array_filter($summaries, static fn (string $s) => str_contains($s, '301')),
            'outbound before lookback still emitted'
        );
        self::assertTrue(
            (bool) array_filter($summaries, static fn (string $s) => str_contains($s, '302')),
            'return in lookback emitted'
        );
        self::assertTrue(
            (bool) array_filter($summaries, static fn (string $s) => str_starts_with($s, 'In ') && str_contains($s, 'Denver')),
            'presence for whole trip'
        );

        // Same trip must not appear under the old forward-only query.
        self::assertSame([], $tripRepo->findActiveOrUpcoming($userId, 90, $asOf));
    }

    public function testPersonalFeedSkipsCancelledSegments(): void
    {
        $userId = $this->insertUser('cal_cancel');
        $tripRepo = new TripRepository($this->db, $this->logger);
        $start = (new \DateTimeImmutable('today'))->modify('+4 days');

        $trip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $userId,
            destinationCity: 'Chicago, IL',
            startDate: $start->format('Y-m-d'),
            endDate: $start->modify('+1 day')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $trip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'AA',
            flightNumber: '100',
            confirmationCode: 'CXL1',
            origin: 'HSV',
            destination: 'ORD',
            departDt: $start->setTime(9, 0)->format('Y-m-d H:i:s'),
            arriveDt: $start->setTime(11, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'cancelled',
            sourceParseLogId: null,
        ));

        $events = (new PersonalTravelFeedBuilder($tripRepo))->buildEvents($userId);
        foreach ($events as $event) {
            self::assertStringNotContainsString('AA', $event->summary);
            self::assertStringNotContainsString('100', $event->summary);
        }
    }

    public function testTeamFeedRespectsPrivacyAndBottomUpFields(): void
    {
        $managerId = $this->insertUser('cal_mgr', null, 'manager');
        $reportId = $this->insertUser('cal_report', $managerId);

        $tripRepo = new TripRepository($this->db, $this->logger);
        $start = (new \DateTimeImmutable('today'))->modify('+5 days');
        $end = $start->modify('+2 days');

        $visible = $tripRepo->create(new Trip(
            id: null,
            ownerId: $reportId,
            destinationCity: 'Atlanta, GA',
            startDate: $start->format('Y-m-d'),
            endDate: $end->format('Y-m-d'),
            status: 'planned',
            tripPurpose: 'Secret purpose',
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $visible->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'DL',
            flightNumber: '999',
            confirmationCode: 'VIS1',
            origin: 'HSV',
            destination: 'ATL',
            departDt: $start->setTime(7, 0)->format('Y-m-d H:i:s'),
            arriveDt: $start->setTime(9, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $tripRepo->create(new Trip(
            id: null,
            ownerId: $reportId,
            destinationCity: 'Hiddenville',
            startDate: $start->modify('+10 days')->format('Y-m-d'),
            endDate: $start->modify('+12 days')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: null,
            notes: null,
            isPrivate: true,
        ));

        $userRepo = new UserRepository($this->db, $this->logger);
        $feedRepo = new CalendarFeedRepository($this->db, $this->logger);
        $visibility = new VisibilityEngine($userRepo, new VisibilityRuleRepository($this->db));
        $blocks = new VisibilityBlockRepository($this->db);
        $airports = new AirportRepository(null, $this->logger);

        // Manager's team feed (TOP_DOWN) sees full fields by default.
        $mgrFeed = $feedRepo->ensureForOwner($managerId, CalendarFeed::KIND_TEAM, $managerId);
        $mgrEvents = (new TeamTravelFeedBuilder(
            $userRepo,
            $tripRepo,
            $visibility,
            $blocks,
            $airports,
        ))->buildEvents($mgrFeed);

        $mgrBody = (new IcsBuilder())->build('Team', $mgrEvents);
        self::assertStringContainsString('Atlanta', $mgrBody);
        self::assertStringContainsString('DL', $mgrBody);
        self::assertStringContainsString('999', $mgrBody);
        self::assertStringNotContainsString('Hiddenville', $mgrBody);

        // Report viewing manager = BOTTOM_UP → city+dates all-day only.
        $mgrTripStart = (new \DateTimeImmutable('today'))->modify('+6 days');
        $mgrTrip = $tripRepo->create(new Trip(
            id: null,
            ownerId: $managerId,
            destinationCity: 'Nashville, TN',
            startDate: $mgrTripStart->format('Y-m-d'),
            endDate: $mgrTripStart->modify('+1 day')->format('Y-m-d'),
            status: 'planned',
            tripPurpose: 'Mgr purpose',
            notes: null,
            isPrivate: false,
        ));
        $tripRepo->addSegment(new TripSegment(
            id: null,
            tripId: (int) $mgrTrip->id,
            segmentType: 'flight',
            segmentSubtype: null,
            carrierId: null,
            carrier: 'WN',
            flightNumber: '55',
            confirmationCode: 'MGR1',
            origin: 'HSV',
            destination: 'BNA',
            departDt: $mgrTripStart->setTime(12, 0)->format('Y-m-d H:i:s'),
            arriveDt: $mgrTripStart->setTime(13, 0)->format('Y-m-d H:i:s'),
            hotelStayId: null,
            status: 'scheduled',
            sourceParseLogId: null,
        ));

        $reportFeed = $feedRepo->ensureForOwner($reportId, CalendarFeed::KIND_TEAM, $reportId);
        $reportFeed = $feedRepo->setMembers((int) $reportFeed->id, $reportId, [$managerId], $reportId);

        $bottomEvents = (new TeamTravelFeedBuilder(
            $userRepo,
            $tripRepo,
            $visibility,
            $blocks,
            $airports,
        ))->buildEvents($reportFeed);

        $bottomBody = (new IcsBuilder())->build('Team', $bottomEvents);
        self::assertStringContainsString('Nashville', $bottomBody);
        self::assertStringContainsString('nxwp-team-presence-', $bottomBody);
        self::assertStringNotContainsString('WN', $bottomBody);
        self::assertStringNotContainsString('55', $bottomBody);
        self::assertStringNotContainsString('Mgr purpose', $bottomBody);
        self::assertStringNotContainsString('nxwp-team-seg-', $bottomBody);
    }

    public function testTeamFeedMemberSelection(): void
    {
        $viewerId = $this->insertUser('cal_sel_viewer');
        $aId = $this->insertUser('cal_sel_a');
        $bId = $this->insertUser('cal_sel_b');

        $tripRepo = new TripRepository($this->db, $this->logger);
        $start = (new \DateTimeImmutable('today'))->modify('+2 days');
        foreach ([$aId => 'Austin, TX', $bId => 'Boston, MA'] as $uid => $city) {
            $tripRepo->create(new Trip(
                id: null,
                ownerId: $uid,
                destinationCity: $city,
                startDate: $start->format('Y-m-d'),
                endDate: $start->modify('+1 day')->format('Y-m-d'),
                status: 'planned',
                tripPurpose: null,
                notes: null,
                isPrivate: false,
            ));
        }

        $userRepo = new UserRepository($this->db, $this->logger);
        $feedRepo = new CalendarFeedRepository($this->db, $this->logger);
        $feed = $feedRepo->ensureForOwner($viewerId, CalendarFeed::KIND_TEAM, $viewerId);
        $feed = $feedRepo->setMembers((int) $feed->id, $viewerId, [$aId], $viewerId);

        $builder = new TeamTravelFeedBuilder(
            $userRepo,
            $tripRepo,
            new VisibilityEngine($userRepo, new VisibilityRuleRepository($this->db)),
            new VisibilityBlockRepository($this->db),
        );
        $body = (new IcsBuilder())->build('Team', $builder->buildEvents($feed));
        self::assertStringContainsString('Austin', $body);
        self::assertStringNotContainsString('Boston', $body);
    }
}
