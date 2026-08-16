<?php

declare(strict_types=1);

use NexWaypoint\Core\AppearanceCatalog;
use NexWaypoint\Core\SiteSettingsRepository;
use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\AirportRepository;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;
use NexWaypoint\Users\TeamLocationResolver;
use NexWaypoint\Users\TeamStaySummarizer;
use NexWaypoint\Users\TeamTravelPreviewBuilder;
use NexWaypoint\Users\TeamUpcomingTripFinder;
use NexWaypoint\Users\User;
use NexWaypoint\Users\UserRepository;
use NexWaypoint\Visibility\VisibilityBlockRepository;
use NexWaypoint\Visibility\VisibilityEngine;
use NexWaypoint\Visibility\VisibilityRuleRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$db = $app['db'];
$logger = $app['logger'];

$userRepo = new UserRepository($db, $logger);
$tripRepo = new TripRepository($db, $logger);
$carrierRepo = new CarrierRepository($db, $logger);
$airports = new AirportRepository($db, $logger);
$statusEngine = new TripStatusEngine($tripRepo, $logger, $airports);
$visibilityEngine = new VisibilityEngine($userRepo, new VisibilityRuleRepository($db));
$blockRepo = new VisibilityBlockRepository($db);
$notifications = new NotificationRepository($db);
$propertyRepo = new HotelPropertyRepository($db, $logger);
$stayRepo = new HotelStayRepository($db, $logger, $propertyRepo);
$locationResolver = new TeamLocationResolver(
    $tripRepo,
    $stayRepo,
    $propertyRepo,
    new Geocoder($logger),
    $airports,
);
$upcomingFinder = new TeamUpcomingTripFinder($tripRepo, $visibilityEngine, $blockRepo);
$travelPreview = new TeamTravelPreviewBuilder(
    $tripRepo,
    $visibilityEngine,
    $blockRepo,
    $stayRepo,
    $propertyRepo,
    $airports,
);

$myUpcomingTrips = $tripRepo->findActiveOrUpcoming($user->id, 60);
$unreadCount = $notifications->unreadCount($user->id);

$upcomingMapDays = 21;

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'home', 'office' => 'badge-status-home',
            'remote' => 'badge-status-travel',
            'delayed', 'cancelled' => 'badge-status-delay',
            'pre_flight', 'en_route', 'post_flight', 'layover', 'at_hotel' => 'badge-status-travel',
            default => 'badge-status-travel',
        };
    }
}

if (!function_exists('nexwaypoint_initials')) {
    function nexwaypoint_initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= strtoupper(substr($part, 0, 1));
            if (strlen($letters) >= 2) {
                break;
            }
        }
        return $letters !== '' ? $letters : '?';
    }
}

/**
 * @return array{
 *   user: User,
 *   status: string,
 *   label: string,
 *   location: array{lat: float, lon: float, city_label: string, city_key: string}|null,
 *   upcoming: string|null,
 *   next: array{city_label: string, dates: string, time_of_day: string|null}|null,
 *   week: string|null,
 *   avatar_url: string|null,
 *   photo_focus_x: float,
 *   photo_focus_y: float,
 *   initials: string,
 *   is_self: bool
 * }
 */
if (!function_exists('nexwaypoint_build_board_entry')) {
    function nexwaypoint_build_board_entry(
        User $subject,
        int $viewerId,
        TripStatusEngine $statusEngine,
        TripRepository $tripRepo,
        VisibilityEngine $visibilityEngine,
        VisibilityBlockRepository $blockRepo,
        TeamLocationResolver $locationResolver,
        TeamUpcomingTripFinder $upcomingFinder,
        bool $markAsSelf,
        ?string $forceDirection = null,
    ): array {
        $alwaysVisibleStatuses = ['home', 'office', 'remote', 'unavailable'];
        $status = $statusEngine->resolveForUser($subject->id);
        $tripId = $status['detail']['trip_id'] ?? null;

        // SELF bypass only when viewing yourself with no forced manager-preview direction.
        $bypassVisibility = $markAsSelf
            && $forceDirection === null
            && $viewerId === $subject->id;

        $displayLabel = $status['label'];
        $destinationVisible = true;
        if (!$bypassVisibility && !in_array($status['status'], $alwaysVisibleStatuses, true) && $tripId !== null) {
            $trip = $tripRepo->find((int) $tripId);
            $tripIsPrivate = $trip !== null && $trip->isPrivate;
            $hidden = false;
            if ($trip !== null && $forceDirection === null) {
                $hidden = $blockRepo->isHiddenFromViewer(
                    $subject->id,
                    $viewerId,
                    $trip->isPrivate,
                    VisibilityBlockRepository::TYPE_TRIP,
                    $trip->id
                );
            } elseif ($tripIsPrivate) {
                // No-manager See Self: private trips stay hidden.
                $hidden = true;
            }

            if ($hidden) {
                $destinationVisible = false;
                $displayLabel = match ($status['status']) {
                    'en_route', 'layover', 'delayed', 'at_hotel' => 'Traveling',
                    'cancelled' => 'Travel disrupted',
                    default => 'Unavailable',
                };
            } else {
                if ($forceDirection !== null) {
                    $visibility = $visibilityEngine->getVisibleFieldsForDirection(
                        $subject->id,
                        $forceDirection,
                        $tripIsPrivate,
                    );
                } else {
                    $visibility = $visibilityEngine->getVisibleFields($viewerId, $subject->id, $tripIsPrivate);
                }

                if (!in_array('destination_city', $visibility['visible_fields'], true)) {
                    $destinationVisible = false;
                    $displayLabel = match ($status['status']) {
                        'en_route' => 'Traveling',
                        'layover' => 'Traveling (layover)',
                        'delayed' => 'Traveling (delayed)',
                        'at_hotel' => 'Traveling',
                        'cancelled' => 'Travel disrupted',
                        default => $status['label'],
                    };
                }
            }
        }

        // Next = soonest visible trip that is not the one they are already on.
        $excludeTripId = $tripId !== null ? (int) $tripId : null;
        $upcomingTrip = $upcomingFinder->findVisible(
            $viewerId,
            $subject->id,
            21,
            $excludeTripId,
            $forceDirection,
        );
        $resolved = $locationResolver->resolveWithUpcoming(
            $subject,
            $status,
            $destinationVisible,
            $upcomingTrip,
        );

        return [
            'user' => $subject,
            'status' => $status['status'],
            'label' => $displayLabel,
            'location' => $resolved['location'],
            'upcoming' => $resolved['upcoming'],
            'next' => $resolved['next'],
            'week' => null,
            'avatar_url' => $subject->hasPhoto() ? '/media/avatar.php?id=' . $subject->id : null,
            'photo_focus_x' => $subject->photoFocusX,
            'photo_focus_y' => $subject->photoFocusY,
            'initials' => nexwaypoint_initials($subject->displayName),
            'is_self' => $markAsSelf,
        ];
    }
}

$team = [];
foreach ($userRepo->findAllActive() as $teammate) {
    if ($teammate->id === $user->id) {
        continue;
    }
    $team[] = nexwaypoint_build_board_entry(
        $teammate,
        $user->id,
        $statusEngine,
        $tripRepo,
        $visibilityEngine,
        $blockRepo,
        $locationResolver,
        $upcomingFinder,
        false,
    );
}

$previewViewerId = $user->managerId;
$selfForceDirection = null;
if ($previewViewerId === null) {
    $dottedIds = $userRepo->dottedManagerIds($user->id);
    $previewViewerId = $dottedIds[0] ?? null;
}

$selfEntry = null;
if ($user->seeSelf) {
    if ($previewViewerId === null) {
        $selfForceDirection = VisibilityEngine::DIRECTION_TOP_DOWN;
        $previewViewerId = $user->id; // placeholder; forceDirection drives field rules
    }
    $selfEntry = nexwaypoint_build_board_entry(
        $user,
        $previewViewerId,
        $statusEngine,
        $tripRepo,
        $visibilityEngine,
        $blockRepo,
        $locationResolver,
        $upcomingFinder,
        true,
        $selfForceDirection,
    );
    $team = array_merge([$selfEntry], $team);
}

$mapPeople = [];
$selfOnMap = false;
$othersOnMap = 0;

$mapCandidates = $team;
foreach ($mapCandidates as $entry) {
    if ($entry['location'] === null) {
        continue;
    }
    if ($entry['is_self']) {
        $selfOnMap = true;
    } else {
        $othersOnMap++;
    }
    $mapPeople[] = [
        'id' => $entry['user']->id,
        'name' => $entry['is_self'] ? $entry['user']->displayName . ' (you)' : $entry['user']->displayName,
        'status' => $entry['status'],
        'label' => $entry['label'],
        'lat' => $entry['location']['lat'],
        'lon' => $entry['location']['lon'],
        'city_label' => $entry['location']['city_label'],
        'city_key' => $entry['location']['city_key'],
        'avatar_url' => $entry['avatar_url'],
        'photo_focus_x' => $entry['photo_focus_x'],
        'photo_focus_y' => $entry['photo_focus_y'],
        'initials' => $entry['initials'],
        'upcoming' => $entry['upcoming'],
        'is_self' => $entry['is_self'],
    ];
}

$mapLonelyNote = $selfOnMap && $othersOnMap === 0;

$staySummarizer = new TeamStaySummarizer($airports);
$ganttDays = $upcomingMapDays;
$ganttFrom = new DateTimeImmutable('today');
$ganttHeaders = [];
for ($g = 0; $g < $ganttDays; $g++) {
    $gDay = $ganttFrom->modify('+' . $g . ' days');
    $dow = (int) $gDay->format('N');
    $ganttHeaders[] = [
        'key' => $gDay->format('Y-m-d'),
        'dow' => $gDay->format('D'),
        'day' => $gDay->format('j'),
        'month' => $gDay->format('M'),
        'show_month' => $g === 0 || $gDay->format('j') === '1',
        'weekend' => $dow >= 6,
        'today' => $g === 0,
    ];
}

$teamProfiles = [];
foreach ($team as $idx => $entry) {
    $uid = (string) $entry['user']->id;
    $profileViewerId = $entry['is_self'] && $previewViewerId !== null
        ? $previewViewerId
        : $user->id;
    $profileAsDirection = $entry['is_self'] ? $selfForceDirection : null;
    $trips = $travelPreview->build(
        $profileViewerId,
        $entry['user']->id,
        $upcomingMapDays,
        $profileAsDirection,
    );
    $flatStays = [];
    foreach ($trips as $tripRow) {
        foreach ($tripRow['stays'] ?? [] as $stayRow) {
            $flatStays[] = $stayRow;
        }
    }
    $weekLabel = $staySummarizer->weekCities($flatStays);
    $homeLabel = $entry['user']->homeLabel() ?? 'Home';
    $ganttCells = [];
    foreach ($staySummarizer->ganttCells($flatStays, $ganttFrom, $ganttDays) as $cellCity) {
        $ganttCells[] = $cellCity ?? $homeLabel;
    }
    $team[$idx]['week'] = $weekLabel;
    $team[$idx]['gantt'] = $ganttCells;

    $teamProfiles[$uid] = [
        'id' => $entry['user']->id,
        'name' => $entry['is_self']
            ? $entry['user']->displayName . ' (you)'
            : $entry['user']->displayName,
        'status_label' => $entry['label'],
        'location' => $entry['location']['city_label'] ?? null,
        'next' => $entry['next'],
        'week' => $weekLabel,
        'window_days' => $upcomingMapDays,
        'trips' => $trips,
    ];
}

$basemap = AppearanceCatalog::resolveMapBasemap(null);
try {
    $settings = new SiteSettingsRepository($db, $logger);
    if ($settings->tableReady()) {
        $basemap = AppearanceCatalog::resolveMapBasemap(
            $settings->get(SiteSettingsRepository::KEY_MAP_STYLE, AppearanceCatalog::defaultMapBasemap())
        );
    }
} catch (Throwable) {
    // Keep default basemap.
}

$showTeamBoard = $team !== [] || $mapPeople !== [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Dashboard</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <h1>Who's traveling this week</h1>
    <?php if ($unreadCount > 0): ?>
        <p><a href="/alerts/index.php"><?= $unreadCount ?> unread alert<?= $unreadCount === 1 ? '' : 's' ?></a></p>
    <?php endif; ?>

    <?php if (!$showTeamBoard): ?>
        <p class="empty-state">No other active teammates yet. Set a <a href="/settings/profile.php">home city</a> to appear on the map.</p>
    <?php else: ?>
        <div class="view-toggle" role="tablist" aria-label="Team view">
            <button type="button" class="view-toggle-btn is-active" data-team-view="table" role="tab" aria-selected="true">Table</button>
            <button type="button" class="view-toggle-btn" data-team-view="cards" role="tab" aria-selected="false">Cards</button>
            <button type="button" class="view-toggle-btn" data-team-view="calendar" role="tab" aria-selected="false">Calendar</button>
            <button type="button" class="view-toggle-btn" data-team-view="map" role="tab" aria-selected="false">Map</button>
        </div>

        <div class="team-view" data-team-panel="table">
            <?php if ($team === []): ?>
                <p class="empty-state">No other active teammates yet.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Teammate</th><th>Status</th><th>This week</th><th>Next</th></tr></thead>
                    <tbody>
                        <?php foreach ($team as $entry): ?>
                            <tr class="team-row-clickable"
                                data-open-teammate="<?= (int) $entry['user']->id ?>"
                                tabindex="0"
                                role="button"
                                title="View travel (next <?= (int) $upcomingMapDays ?> days)">
                                <td>
                                    <span class="team-name-cell">
                                        <?php if ($entry['avatar_url']): ?>
                                            <img class="avatar-circle avatar-sm"
                                                src="<?= htmlspecialchars($entry['avatar_url'], ENT_QUOTES) ?>"
                                                alt=""
                                                style="object-position: <?= (float) $entry['photo_focus_x'] ?>% <?= (float) $entry['photo_focus_y'] ?>%;">
                                        <?php else: ?>
                                            <span class="avatar-circle avatar-sm avatar-fallback"><?= htmlspecialchars($entry['initials'], ENT_QUOTES) ?></span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars(
                                            $entry['is_self']
                                                ? $entry['user']->displayName . ' (you)'
                                                : $entry['user']->displayName,
                                            ENT_QUOTES
                                        ) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= statusBadgeClass($entry['status']) ?>"><?= htmlspecialchars($entry['label'], ENT_QUOTES) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($entry['week'])): ?>
                                        <?= htmlspecialchars((string) $entry['week'], ENT_QUOTES) ?>
                                    <?php else: ?>
                                        <span class="hint">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($entry['next'] !== null): ?>
                                        <div><?= htmlspecialchars($entry['next']['city_label'], ENT_QUOTES) ?></div>
                                        <div class="hint"><?= htmlspecialchars(
                                            TeamLocationResolver::formatNextDatesHint($entry['next']),
                                            ENT_QUOTES
                                        ) ?></div>
                                    <?php else: ?>
                                        <span class="hint">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="team-view" data-team-panel="cards" hidden>
            <?php if ($team === []): ?>
                <p class="empty-state">No other active teammates yet.</p>
            <?php else: ?>
                <div class="team-card-grid">
                    <?php foreach ($team as $entry): ?>
                        <article class="team-card team-card-clickable"
                            data-open-teammate="<?= (int) $entry['user']->id ?>"
                            tabindex="0"
                            role="button"
                            title="View travel (next <?= (int) $upcomingMapDays ?> days)">
                            <?php if ($entry['avatar_url']): ?>
                                <img class="avatar-circle avatar-lg"
                                    src="<?= htmlspecialchars($entry['avatar_url'], ENT_QUOTES) ?>"
                                    alt=""
                                    style="object-position: <?= (float) $entry['photo_focus_x'] ?>% <?= (float) $entry['photo_focus_y'] ?>%;">
                            <?php else: ?>
                                <span class="avatar-circle avatar-lg avatar-fallback"><?= htmlspecialchars($entry['initials'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                            <h3 class="team-card-name"><?= htmlspecialchars(
                                $entry['is_self']
                                    ? $entry['user']->displayName . ' (you)'
                                    : $entry['user']->displayName,
                                ENT_QUOTES
                            ) ?></h3>
                            <span class="badge <?= statusBadgeClass($entry['status']) ?>"><?= htmlspecialchars($entry['label'], ENT_QUOTES) ?></span>
                            <div class="team-card-places">
                                <p class="team-place">
                                    <span class="team-place-label">This week</span>
                                    <?php if (!empty($entry['week'])): ?>
                                        <?= htmlspecialchars((string) $entry['week'], ENT_QUOTES) ?>
                                    <?php else: ?>
                                        <span class="hint">—</span>
                                    <?php endif; ?>
                                </p>
                                <?php if ($entry['next'] !== null): ?>
                                    <p class="team-place">
                                        <span class="team-place-label">Next</span>
                                        <?= htmlspecialchars($entry['next']['city_label'], ENT_QUOTES) ?>
                                        <span class="hint"><?= htmlspecialchars(
                                            TeamLocationResolver::formatNextDatesHint($entry['next']),
                                            ENT_QUOTES
                                        ) ?></span>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="team-view" data-team-panel="calendar" hidden>
            <?php if ($team === []): ?>
                <p class="empty-state">No other active teammates yet.</p>
            <?php else: ?>
                <p class="hint">Next <?= (int) $ganttDays ?> days. Travel cities fill the bar; other days are home. Click a name for stay dates.</p>
                <div class="team-gantt-wrap">
                    <table class="team-gantt">
                        <thead>
                            <tr>
                                <th class="team-gantt-name">Teammate</th>
                                <?php foreach ($ganttHeaders as $header): ?>
                                    <th class="team-gantt-day<?= $header['weekend'] ? ' is-weekend' : '' ?><?= $header['today'] ? ' is-today' : '' ?>"
                                        title="<?= htmlspecialchars($header['key'], ENT_QUOTES) ?>">
                                        <span class="team-gantt-dow"><?= htmlspecialchars($header['dow'], ENT_QUOTES) ?></span>
                                        <span class="team-gantt-num"><?= htmlspecialchars($header['day'], ENT_QUOTES) ?></span>
                                        <?php if ($header['show_month']): ?>
                                            <span class="team-gantt-mon"><?= htmlspecialchars($header['month'], ENT_QUOTES) ?></span>
                                        <?php endif; ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team as $entry): ?>
                                <tr class="team-row-clickable"
                                    data-open-teammate="<?= (int) $entry['user']->id ?>"
                                    tabindex="0"
                                    role="button">
                                    <th class="team-gantt-name" scope="row">
                                        <?= htmlspecialchars(
                                            $entry['is_self']
                                                ? $entry['user']->displayName . ' (you)'
                                                : $entry['user']->displayName,
                                            ENT_QUOTES
                                        ) ?>
                                    </th>
                                    <?php
                                    $prevGantt = null;
                                    foreach ($entry['gantt'] ?? [] as $gIdx => $gCity):
                                        $gCity = (string) $gCity;
                                        $homeLabel = $entry['user']->homeLabel() ?? 'Home';
                                        $isTravel = $gCity !== $homeLabel && $gCity !== 'Home';
                                        $short = $gCity;
                                        if (preg_match('/^(.+?),\s*/', $short, $gm) === 1) {
                                            $short = $gm[1];
                                        }
                                        if (str_contains($short, '/')) {
                                            $short = explode('/', $short, 2)[0];
                                        }
                                        $sameAsPrev = $prevGantt === $gCity;
                                        $prevGantt = $gCity;
                                        $header = $ganttHeaders[$gIdx] ?? null;
                                        ?>
                                        <td class="team-gantt-cell<?= $isTravel ? ' is-travel' : ' is-home' ?><?= ($header['weekend'] ?? false) ? ' is-weekend' : '' ?><?= ($header['today'] ?? false) ? ' is-today' : '' ?>"
                                            title="<?= htmlspecialchars($gCity, ENT_QUOTES) ?>">
                                            <?= $sameAsPrev ? '' : htmlspecialchars($short, ENT_QUOTES) ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="team-view" data-team-panel="map" hidden>
            <?php if ($mapPeople === []): ?>
                <p class="empty-state">No map locations yet. Set a home city under <a href="/settings/profile.php">My profile</a>, or travel with a visible destination.</p>
            <?php else: ?>
                <?php if ($mapLonelyNote): ?>
                    <p class="hint">You’re the only one with a location set so far.</p>
                <?php endif; ?>
                <div id="team-map" class="team-map" role="img" aria-label="Teammate locations"></div>
                <p class="hint">Pins show where people are now. Click a face for their <?= (int) $upcomingMapDays ?>-day travel look-ahead.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2>Your upcoming trips <a class="hint" href="/trips/list.php" style="font-weight: 400; font-size: 0.85rem;">View all</a></h2>
    <?php if ($myUpcomingTrips === []): ?>
        <p class="empty-state">Nothing on the books. <a href="/trips/builder.php">Add a trip</a>, <a href="/trips/list.php">review past trips</a>, or <a href="/hotels/add.php">log a hotel stay</a>.</p>
    <?php else: ?>
        <?php foreach ($myUpcomingTrips as $trip): ?>
            <div class="card">
                <h3>
                    <a href="/trips/view.php?id=<?= (int) $trip->id ?>">
                        <?= htmlspecialchars($trip->destinationCity, ENT_QUOTES) ?>
                    </a>
                    <?php if ($trip->isPrivate): ?><span class="badge badge-blacklist">Private</span><?php endif; ?>
                </h3>
                <p><?= htmlspecialchars($trip->startDate, ENT_QUOTES) ?> &rarr; <?= htmlspecialchars($trip->endDate, ENT_QUOTES) ?></p>
                <?php if ($trip->tripPurpose !== null): ?><p><?= htmlspecialchars($trip->tripPurpose, ENT_QUOTES) ?></p><?php endif; ?>
                <?php
                $segments = $tripRepo->segmentsForTrip((int) $trip->id);
                foreach ($segments as $segment):
                    if ($segment->segmentType !== 'flight') {
                        continue;
                    }
                    ?>
                    <p>
                        <?= htmlspecialchars(trim(($segment->carrier ?? '') . ' ' . ($segment->flightNumber ?? '')), ENT_QUOTES) ?>
                        <?php
                        if ($segment->carrierId !== null) {
                            $linked = $carrierRepo->find($segment->carrierId);
                            if ($linked !== null && $linked->iataCode !== null) {
                                $ident = $linked->flightIdent((string) ($segment->flightNumber ?? ''));
                                if ($ident !== null) {
                                    echo ' <span class="hint">(' . htmlspecialchars($ident, ENT_QUOTES) . ')</span>';
                                }
                            }
                        }
                        ?>
                        · <?= htmlspecialchars($airports->routeLabel($segment->origin, $segment->destination), ENT_QUOTES) ?>
                        <?php if ($segment->departDt !== null): ?>
                            · <?= htmlspecialchars($segment->departDt, ENT_QUOTES) ?>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<div id="teammate-travel-modal" class="modal-backdrop" hidden>
    <div class="modal-panel" role="dialog" aria-labelledby="teammate-travel-modal-title">
        <h2 id="teammate-travel-modal-title">Teammate</h2>
        <p id="teammate-travel-modal-meta" class="hint"></p>
        <div id="teammate-travel-modal-body"></div>
        <div class="modal-actions">
            <button type="button" class="secondary" data-close-modal>Close</button>
        </div>
    </div>
</div>

<script>
window.NEXWAYPOINT_TEAM_PROFILES = <?= json_encode(
    $teamProfiles,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
) ?>;
</script>
<?php if ($mapPeople !== []): ?>
<script>
window.NEXWAYPOINT_TEAM_MAP = <?= json_encode([
    'people' => $mapPeople,
    'basemap' => [
        'url' => $basemap['url'],
        'attribution' => $basemap['attribution'],
        'maxZoom' => $basemap['maxZoom'],
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/team-map.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>
<script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/team-travel-modal.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(nexwaypoint_asset('/assets/team-view.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
