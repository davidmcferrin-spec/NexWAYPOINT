<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\Geocoder;

final class GeocoderTest extends NexWaypointTestCase
{
    public function testCacheRoundTripWithoutNetwork(): void
    {
        $dir = sys_get_temp_dir() . '/nexwaypoint_geocode_' . uniqid('', true);
        mkdir($dir);
        $geocoder = new Geocoder($this->logger, $dir);

        $keyQuery = 'Chicago, IL, USA';
        $path = $dir . '/' . hash('sha256', strtolower($keyQuery)) . '.json';
        file_put_contents($path, json_encode(['lat' => 41.8781, 'lon' => -87.6298]));

        $result = $geocoder->geocodeCity('Chicago', 'IL', 'USA');
        self::assertNotNull($result);
        self::assertEqualsWithDelta(41.8781, $result['lat'], 0.0001);
        self::assertEqualsWithDelta(-87.6298, $result['lon'], 0.0001);

        // Cleanup
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function testRequiresCity(): void
    {
        $dir = sys_get_temp_dir() . '/nexwaypoint_geocode_' . uniqid('', true);
        mkdir($dir);
        $geocoder = new Geocoder($this->logger, $dir);
        self::assertNull($geocoder->geocodeCity(null, 'IL', 'USA'));
        @rmdir($dir);
    }
}
