<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'NexWaypoint\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\NexstarStationCsv;
use NexWaypoint\Hotels\OfficeVenueRepository;

$options = getopt('', [
    'csv:',
    'update',
    'skip-geocode',
    'dry-run',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Seed office_venues from the Nexstar stations export (hotel map squares).

Usage:
  php scripts/seed_nexstar_venues.php [options]

Options:
  --csv=PATH       CSV path (default: data/nexstar_stations.csv)
  --skip-geocode   Do not call Nominatim (map page can fill pins later)
  --dry-run        Parse and report without writing
  --help           Show this help

Insert-only: a matching venue name is left untouched (address, coords,
notes, and active flag stay as they are). Draft rows (status != publish)
are created inactive. Dual-address rows become two venues.

HELP);
    exit(0);
}

if (isset($options['update'])) {
    fwrite(STDERR, "--update is not supported; existing venues are never overwritten.\n");
    exit(2);
}

$csvPath = isset($options['csv']) ? (string) $options['csv'] : $root . '/data/nexstar_stations.csv';
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV not found: {$csvPath}\n");
    exit(1);
}

$skipGeocode = isset($options['skip-geocode']);
$dryRun = isset($options['dry-run']);

try {
    $venues = NexstarStationCsv::parseFile($csvPath);
} catch (Throwable $exception) {
    fwrite(STDERR, "Parse failed: {$exception->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, 'Parsed ' . count($venues) . " venue(s) from {$csvPath}\n");

if ($dryRun) {
    foreach ($venues as $row) {
        $flag = $row['is_active'] ? '' : ' [inactive]';
        $place = implode(', ', array_filter([
            $row['address_line1'],
            $row['city'],
            $row['state_region'],
            $row['postal_code'],
        ], static fn ($v) => $v !== null && $v !== ''));
        fwrite(STDOUT, "  {$row['name']}{$flag} — {$place}\n");
    }
    fwrite(STDOUT, "Dry run; nothing written.\n");
    exit(0);
}

try {
    /** @var array{db: \NexWaypoint\Core\Database, logger: \NexWaypoint\Core\Logger} $app */
    $app = require $root . '/config/bootstrap.php';
    $repo = new OfficeVenueRepository($app['db'], $app['logger']);
    if (!$repo->tableReady()) {
        throw new RuntimeException('office_venues table missing; run php scripts/migrate.php');
    }
    $geocoder = new Geocoder($app['logger']);
} catch (Throwable $exception) {
    fwrite(STDERR, "Seed failed: {$exception->getMessage()}\n");
    exit(1);
}

$created = 0;
$skipped = 0;
$geocoded = 0;
$geoMiss = 0;

foreach ($venues as $row) {
    if ($repo->findByName($row['name']) !== null) {
        $skipped++;
        continue;
    }

    $lat = null;
    $lon = null;
    $street = $row['address_line1'];
    if (!$skipGeocode) {
        $normalized = $geocoder->normalizeStreetAddress($street);
        $street = $normalized ?? $street;
        $coords = $geocoder->geocode(
            $street,
            $row['city'],
            $row['state_region'],
            $row['postal_code'],
            $row['country'],
            false
        );
        if ($coords === null && $street === null && $row['city'] !== null) {
            $coords = $geocoder->geocodeCity($row['city'], $row['state_region'], $row['country'], false);
        }
        if ($coords !== null) {
            $lat = $coords['lat'];
            $lon = $coords['lon'];
            $geocoded++;
        } else {
            $geoMiss++;
        }
    }

    try {
        $createdVenue = $repo->create(
            $row['name'],
            $street,
            $row['city'],
            $row['state_region'],
            $row['postal_code'],
            $row['country'],
            null,
            $lat,
            $lon,
        );
        if (!$row['is_active'] && $createdVenue->id !== null) {
            $repo->deactivate((int) $createdVenue->id);
        }
        $created++;
    } catch (Throwable $exception) {
        fwrite(STDERR, "Failed on {$row['name']}: {$exception->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Created {$created}, skipped existing {$skipped}");
if (!$skipGeocode) {
    fwrite(STDOUT, ", geocoded {$geocoded}, no pin {$geoMiss}");
}
fwrite(STDOUT, ".\n");
if ($created > 0) {
    fwrite(STDOUT, "Hotel map: /hotels/map.php — Settings → Site catalogs to edit.\n");
}
