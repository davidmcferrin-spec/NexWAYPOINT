<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Hotels\HotelProperty;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\UserHotelBlacklistRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = [];
if (is_string($raw) && $raw !== '' && str_starts_with(ltrim($raw), '{')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if ($data === []) {
    $data = $_POST;
}

if (!Csrf::verify((string) ($data['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = (string) ($data['action'] ?? 'create');
if ($action !== 'create') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$nullable = static function (mixed $value): ?string {
    if ($value === null) {
        return null;
    }
    $trimmed = trim((string) $value);
    return $trimmed === '' ? null : $trimmed;
};

$bool = static fn (string $key) => !empty($data[$key]) && (string) $data[$key] !== '0';

$repo = new HotelPropertyRepository($app['db'], $app['logger']);
$blacklistRepo = new UserHotelBlacklistRepository($app['db'], $app['logger']);

try {
    $hotelName = trim((string) ($data['hotel_name'] ?? ''));
    $city = $nullable($data['city'] ?? null);
    $state = $nullable($data['state_region'] ?? null);
    if ($hotelName === '') {
        throw new InvalidArgumentException('Hotel name is required.');
    }
    if ($city === null) {
        throw new InvalidArgumentException('City is required so the property appears in location search.');
    }

    $match = $blacklistRepo->findMatchingForUser($user->id, $hotelName, $city);
    $blacklistWarning = null;
    if ($match !== null) {
        $blacklistWarning = "You blacklisted \"{$match['hotel_name']}\": " . ($match['reason'] ?? 'no reason recorded.');
    }

    $existing = $repo->findByNameCity($hotelName, $city, $state);
    if ($existing !== null) {
        $created = $existing;
    } else {
        $lat = isset($data['latitude']) && $data['latitude'] !== '' && is_numeric($data['latitude'])
            ? (float) $data['latitude'] : null;
        $lon = isset($data['longitude']) && $data['longitude'] !== '' && is_numeric($data['longitude'])
            ? (float) $data['longitude'] : null;
        $created = $repo->create(new HotelProperty(
            id: null,
            createdByUserId: $user->id,
            hotelName: $hotelName,
            brand: $nullable($data['brand'] ?? null),
            addressLine1: $nullable($data['address_line1'] ?? null),
            addressLine2: $nullable($data['address_line2'] ?? null),
            city: $city,
            stateRegion: $state,
            postalCode: $nullable($data['postal_code'] ?? null),
            country: $nullable($data['country'] ?? null) ?? 'USA',
            phone: $nullable($data['phone'] ?? null),
            website: $nullable($data['website'] ?? null),
            latitude: $lat,
            longitude: $lon,
            hasDesk: $bool('has_desk'),
            deskNotes: $nullable($data['desk_notes'] ?? null),
            hasPool: $bool('has_pool'),
            hasHotTub: $bool('has_hot_tub'),
            hasBreakfast: $bool('has_breakfast'),
            breakfastNotes: $nullable($data['breakfast_notes'] ?? null),
            hasGym: $bool('has_gym'),
            hasFreeParking: $bool('has_free_parking'),
            hasAirportShuttle: $bool('has_airport_shuttle'),
            hasEvCharging: $bool('has_ev_charging'),
            hasOnsiteRestaurant: $bool('has_onsite_restaurant'),
            hasOffsiteGym: $bool('has_offsite_gym'),
            walkToOffice: $bool('walk_to_office') || $nullable($data['walk_to_office_notes'] ?? null) !== null,
            walkToOfficeNotes: $nullable($data['walk_to_office_notes'] ?? null),
            hasDestinationFee: $bool('has_destination_fee'),
            destinationFeeNotes: null,
            wifiQuality: isset($data['wifi_quality']) && $data['wifi_quality'] !== '' ? (int) $data['wifi_quality'] : null,
            noiseLevel: isset($data['noise_level']) && $data['noise_level'] !== '' ? (int) $data['noise_level'] : null,
            uniqueFeatures: $nullable($data['unique_features'] ?? null),
        ), $user->id);
    }

    if ($bool('is_blacklisted') && $created->id !== null) {
        $blacklistRepo->set(
            $user->id,
            (int) $created->id,
            true,
            $nullable($data['blacklist_reason'] ?? null) ?? 'Blacklisted',
            $user->id,
        );
    }

    echo json_encode([
        'ok' => true,
        'warning' => $blacklistWarning,
        'property' => [
            'id' => $created->id,
            'hotel_name' => $created->hotelName,
            'city' => $created->city,
            'state_region' => $created->stateRegion,
            'location_key' => $created->locationKey(),
            'location_label' => $created->locationLabel(),
            'label' => $created->label(),
            'overall_rating' => $created->overallRating,
            'phone' => $created->phone,
            'website' => $created->website,
        ],
        'locations' => $repo->locations(),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
