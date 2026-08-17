<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use NexWaypoint\Hotels\Geocoder;
use NexWaypoint\Hotels\OfficeVenueRepository;

$options = getopt('', ['dry-run', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Fill lat/lon for office_venues that have an address (or city) but no pin.

Usage:
  php scripts/geocode_missing_venues.php [options]

Options:
  --dry-run   Report what would be geocoded without writing
  --help      Show this help

Does not change name, address, notes, or active. Rows that already have
coordinates are skipped. Nominatim is rate-limited (~1 request/sec).

HELP);
    exit(0);
}

$dryRun = isset($options['dry-run']);
$root = dirname(__DIR__);

try {
    /** @var array{db: \NexWaypoint\Core\Database, logger: \NexWaypoint\Core\Logger} $app */
    $app = require $root . '/config/bootstrap.php';
    $repo = new OfficeVenueRepository($app['db'], $app['logger']);
    if (!$repo->tableReady()) {
        throw new RuntimeException('office_venues table missing; run php scripts/migrate.php');
    }
    $geocoder = new Geocoder($app['logger']);
} catch (Throwable $exception) {
    fwrite(STDERR, "Geocode failed: {$exception->getMessage()}\n");
    exit(1);
}

$filled = 0;
$missed = 0;
$skipped = 0;

foreach ($repo->findAll() as $venue) {
    if ($venue->id === null) {
        continue;
    }
    if ($venue->latitude !== null && $venue->longitude !== null) {
        $skipped++;
        continue;
    }
    $hasStreet = $venue->addressLine1 !== null && trim($venue->addressLine1) !== '';
    $hasCity = $venue->city !== null && trim($venue->city) !== '';
    if (!$hasStreet && !$hasCity) {
        $skipped++;
        continue;
    }

    $coords = $geocoder->geocode(
        $venue->addressLine1,
        $venue->city,
        $venue->stateRegion,
        $venue->postalCode,
        $venue->country,
        true
    );
    if ($coords === null && !$hasStreet && $hasCity) {
        $coords = $geocoder->geocodeCity($venue->city, $venue->stateRegion, $venue->country, true);
    }

    if ($coords === null) {
        $missed++;
        fwrite(STDOUT, "  miss  {$venue->name} — {$venue->placeLabel()}\n");
        continue;
    }

    fwrite(STDOUT, sprintf(
        "  pin   %s — %.5f, %.5f\n",
        $venue->name,
        $coords['lat'],
        $coords['lon']
    ));
    if (!$dryRun) {
        $repo->updateCoordinates((int) $venue->id, $coords['lat'], $coords['lon']);
    }
    $filled++;
}

$verb = $dryRun ? 'Would fill' : 'Filled';
fwrite(STDOUT, "{$verb} {$filled}, still missing {$missed}, already pinned/skipped {$skipped}.\n");
