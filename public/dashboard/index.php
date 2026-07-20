<?php

declare(strict_types=1);

use NexWaypoint\Core\AppearanceCatalog;
use NexWaypoint\Core\SiteSettingsRepository;
use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\HotelStayRepository;
use NexWaypoint\Trips\CarrierRepository;
use NexWaypoint\Trips\NotificationRepository;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;
use NexWaypoint\Users\TeamLocationResolver;
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
$statusEngine = new TripStatusEngine($tripRepo, $logger);
$visibilityEngine = new VisibilityEngine($userRepo, new VisibilityRuleRepository($db));
$blockRepo = new VisibilityBlockRepository($db);
$notifications = new NotificationRepository($db);
$locationResolver = new TeamLocationResolver(
    $tripRepo,
    new HotelStayRepository($db, $logger),
    new HotelPropertyRepository($db, $logger),
    new Geocoder($logger),
);
$upcomingFinder = new TeamUpcomingTripFinder($tripRepo, $visibilityEngine, $blockRepo);
$travelPreview = new TeamTravelPreviewBuilder($tripRepo, $visibilityEngine, $blockRepo);

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
        bool $isSelf,
    ): array {
        $alwaysVisibleStatuses = ['home', 'office', 'remote', 'unavailable'];
        $status = $statusEngine->resolveForUser($subject->id);
        $tripId = $status['detail']['trip_id'] ?? null;

        $displayLabel = $status['label'];
        $destinationVisible = true;
        if (!$isSelf && !in_array($status['status'], $alwaysVisibleStatuses, true) && $tripId !== null) {
            $trip = $tripRepo->find((int) $tripId);
            $hidden = $trip !== null && $blockRepo->isHiddenFromViewer(
                $subject->id,
                $viewerId,
                $trip->isPrivate,
                VisibilityBlockRepository::TYPE_TRIP,
                $trip->id
            );

            if ($hidden) {
                $destinationVisible = false;
                $displayLabel = match ($status['status']) {
                    'en_route', 'layover', 'delayed', 'at_hotel' => 'Traveling',
                    'cancelled' => 'Travel disrupted',
                    default => 'Unavailable',
                };
            } else {
                $tripIsPrivate = $trip !== null && $trip->isPrivate;
                $visibility = $visibilityEngine->getVisibleFields($viewerId, $subject->id, $tripIsPrivate);

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

        $upcomingTrip = null;
        if (TeamLocationResolver::isAtBaseStatus($status['status'], $status['detail'] ?? [])) {
            $upcomingTrip = $upcomingFinder->findVisible($viewerId, $subject->id, 21);
        }
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
            'avatar_url' => $subject->hasPhoto() ? '/media/avatar.php?id=' . $subject->id : null,
            'photo_focus_x' => $subject->photoFocusX,
            'photo_focus_y' => $subject->photoFocusY,
            'initials' => nexwaypoint_initials($subject->displayName),
            'is_self' => $isSelf,
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

$selfEntry = nexwaypoint_build_board_entry(
    $user,
    $user->id,
    $statusEngine,
    $tripRepo,
    $visibilityEngine,
    $blockRepo,
    $locationResolver,
    $upcomingFinder,
    true,
);

$mapPeople = [];
$selfOnMap = false;
$othersOnMap = 0;

$mapCandidates = array_merge([$selfEntry], $team);
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

$teamProfiles = [];
foreach (array_merge([$selfEntry], $team) as $entry) {
    $uid = (string) $entry['user']->id;
    $teamProfiles[$uid] = [
        'id' => $entry['user']->id,
        'name' => $entry['is_self']
            ? $entry['user']->displayName . ' (you)'
            : $entry['user']->displayName,
        'status_label' => $entry['label'],
        'location' => $entry['location']['city_label'] ?? null,
        'window_days' => $upcomingMapDays,
        'trips' => $travelPreview->build($user->id, $entry['user']->id, $upcomingMapDays),
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
            <button type="button" class="view-toggle-btn" data-team-view="map" role="tab" aria-selected="false">Map</button>
        </div>

        <div class="team-view" data-team-panel="table">
            <?php if ($team === []): ?>
                <p class="empty-state">No other active teammates yet.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Teammate</th><th>Status</th><th>Location</th></tr></thead>
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
                                        <?= htmlspecialchars($entry['user']->displayName, ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= statusBadgeClass($entry['status']) ?>"><?= htmlspecialchars($entry['label'], ENT_QUOTES) ?></span>
                                    <?php if ($entry['upcoming'] !== null): ?>
                                        <div class="hint">Upcoming: <?= htmlspecialchars($entry['upcoming'], ENT_QUOTES) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($entry['location'] !== null): ?>
                                        <?= htmlspecialchars($entry['location']['city_label'], ENT_QUOTES) ?>
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
                            <h3 class="team-card-name"><?= htmlspecialchars($entry['user']->displayName, ENT_QUOTES) ?></h3>
                            <span class="badge <?= statusBadgeClass($entry['status']) ?>"><?= htmlspecialchars($entry['label'], ENT_QUOTES) ?></span>
                            <?php if ($entry['location'] !== null): ?>
                                <p class="team-card-location"><?= htmlspecialchars($entry['location']['city_label'], ENT_QUOTES) ?></p>
                            <?php else: ?>
                                <p class="team-card-location hint">—</p>
                            <?php endif; ?>
                            <?php if ($entry['upcoming'] !== null): ?>
                                <p class="hint">Upcoming: <?= htmlspecialchars($entry['upcoming'], ENT_QUOTES) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
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
                <p class="hint">Zoom in to see faces. City clusters show how many people are in that city. Upcoming trips (next <?= (int) $upcomingMapDays ?> days) pin at the destination when not already traveling.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2>Your upcoming trips <a class="hint" href="/trips/list.php" style="font-weight: 400; font-size: 0.85rem;">View all</a></h2>
    <?php if ($myUpcomingTrips === []): ?>
        <p class="empty-state">Nothing on the books. <a href="/trips/builder.php">Add a trip</a>, <a href="/trains/add.php">add a train</a>, <a href="/trips/list.php">review past trips</a>, or <a href="/hotels/add.php">log a hotel stay</a>.</p>
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
                        · <?= htmlspecialchars(($segment->origin ?? '?') . ' → ' . ($segment->destination ?? '?'), ENT_QUOTES) ?>
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
