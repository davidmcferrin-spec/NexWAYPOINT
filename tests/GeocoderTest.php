<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\Geocoder;

final class GeocoderTest extends NexWaypointTestCase
{
    public function testParseNominatimRowExtractsAddress(): void
    {
        $geocoder = new Geocoder($this->logger, sys_get_temp_dir());
        $parsed = $geocoder->parseNominatimRow([
            'lat' => '41.897',
            'lon' => '-87.635',
            'display_name' => 'Hotel Zachary, 3636 North Clark Street, Chicago, Illinois, 60613, United States',
            'address' => [
                'hotel' => 'Hotel Zachary',
                'house_number' => '3636',
                'road' => 'North Clark Street',
                'city' => 'Chicago',
                'state' => 'Illinois',
                'ISO3166-2-lvl4' => 'US-IL',
                'postcode' => '60613',
                'country' => 'United States',
            ],
        ]);
        self::assertNotNull($parsed);
        self::assertSame('Hotel Zachary', $parsed['hotel_name']);
        self::assertSame('3636 North Clark Street', $parsed['address_line1']);
        self::assertSame('Chicago', $parsed['city']);
        self::assertSame('IL', $parsed['state_region']);
        self::assertSame('60613', $parsed['postal_code']);
        self::assertSame('USA', $parsed['country']);
        self::assertEqualsWithDelta(41.897, $parsed['lat'], 0.001);
        self::assertNull($parsed['phone']);
        self::assertNull($parsed['website']);
    }

    public function testParseNominatimRowExtractsPhoneAndWebsite(): void
    {
        $geocoder = new Geocoder($this->logger, sys_get_temp_dir());
        $parsed = $geocoder->parseNominatimRow([
            'lat' => '41.897',
            'lon' => '-87.635',
            'display_name' => 'Hotel Zachary, Chicago',
            'address' => [
                'hotel' => 'Hotel Zachary',
                'house_number' => '3636',
                'road' => 'North Clark Street',
                'city' => 'Chicago',
                'state' => 'Illinois',
                'ISO3166-2-lvl4' => 'US-IL',
                'country' => 'United States',
            ],
            'extratags' => [
                'phone' => '+1-312-555-0100; +1-312-555-0199',
                'contact:website' => 'www.example-hotel.com/chicago',
            ],
        ]);
        self::assertNotNull($parsed);
        self::assertSame('+1-312-555-0100', $parsed['phone']);
        self::assertSame('https://www.example-hotel.com/chicago', $parsed['website']);
    }

    public function testCacheRoundTripWithoutNetwork(): void
    {
        $dir = sys_get_temp_dir() . '/nexwaypoint_geocode_' . uniqid('', true);
        mkdir($dir);
        $geocoder = new Geocoder($this->logger, $dir);

        // Mirror Geocoder cache key: v3|address|city|state|postal|normalizedCountry
        $cacheKey = strtolower('v3||Chicago|IL||United States');
        $path = $dir . '/' . hash('sha256', $cacheKey) . '.json';
        file_put_contents($path, json_encode(['lat' => 41.8781, 'lon' => -87.6298]));

        $result = $geocoder->geocodeCity('Chicago', 'IL', 'USA');
        self::assertNotNull($result);
        self::assertEqualsWithDelta(41.8781, $result['lat'], 0.0001);
        self::assertEqualsWithDelta(-87.6298, $result['lon'], 0.0001);

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function testNormalizesUsaCountry(): void
    {
        $geocoder = new Geocoder($this->logger, sys_get_temp_dir());
        self::assertSame('United States', $geocoder->normalizeCountry('USA'));
        self::assertSame('United States', $geocoder->normalizeCountry('us'));
    }

    public function testNormalizesCapitalStreetTypo(): void
    {
        $geocoder = new Geocoder($this->logger, sys_get_temp_dir());
        self::assertSame(
            '400 North Capitol Street NE',
            $geocoder->normalizeStreetAddress('400 N. Capital St NE')
        );
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
