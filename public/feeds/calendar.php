<?php

declare(strict_types=1);

/**
 * Public ICS subscription endpoint. Auth is the secret token in ?t=.
 * Calendar clients poll this URL; no session cookie required.
 */

use NexWaypoint\Calendar\CalendarFeed;
use NexWaypoint\Calendar\CalendarFeedRepository;
use NexWaypoint\Calendar\IcsBuilder;
use NexWaypoint\Calendar\PersonalTravelFeedBuilder;
use NexWaypoint\Calendar\TeamTravelFeedBuilder;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$db = $app['db'];
$logger = $app['logger'];

if (!$db->tableExists('calendar_feeds')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Calendar feeds are not available. Run: php scripts/migrate.php';
    exit;
}

$token = trim((string) ($_GET['t'] ?? $_GET['token'] ?? ''));
$feedRepo = new CalendarFeedRepository($db, $logger);
$feed = $feedRepo->findByToken($token);

if ($feed === null || $feed->id === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Calendar feed not found.';
    exit;
}

$userRepo = new UserRepository($db, $logger);
$owner = $userRepo->find($feed->ownerUserId);
if ($owner === null || !$owner->isActive) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Calendar feed not found.';
    exit;
}

$tripRepo = new TripRepository($db, $logger);
$airports = new AirportRepository($db, $logger);
$ics = new IcsBuilder();

if ($feed->kind === CalendarFeed::KIND_PERSONAL) {
    $events = (new PersonalTravelFeedBuilder($tripRepo, $airports))->buildEvents($owner);
    $calName = 'NexWAYPOINT - ' . $owner->displayName;
    $filename = 'nexwaypoint-personal.ics';
} else {
    $ruleRepo = new VisibilityRuleRepository($db);
    $visibility = new VisibilityEngine($userRepo, $ruleRepo);
    $blocks = new VisibilityBlockRepository($db);
    $events = (new TeamTravelFeedBuilder(
        $userRepo,
        $tripRepo,
        $visibility,
        $blocks,
        $airports,
    ))->buildEvents($feed);
    $calName = 'NexWAYPOINT - Team';
    $filename = 'nexwaypoint-team.ics';
}

$body = $ics->build($calName, $events);
$feedRepo->touchAccess($feed->id);

// Outlook subscribe is pickier than file import: method= must match METHOD:PUBLISH.
header('Content-Type: text/calendar; charset=utf-8; method=PUBLISH');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) strlen($body));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
echo $body;
