<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Hotels\NexstarStationCsv;
use PHPUnit\Framework\TestCase;

final class NexstarStationCsvTest extends TestCase
{
    public function testParseBundledExport(): void
    {
        $path = NEXWAYPOINT_ROOT . '/data/nexstar_stations.csv';
        self::assertFileExists($path);
        $rows = NexstarStationCsv::parseFile($path);
        self::assertCount(141, $rows);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertSame('Dallas', $byName['KDAF']['city']);
        self::assertSame('TX', $byName['KDAF']['state_region']);
        self::assertSame('8001 John W. Carpenter Freeway', $byName['KDAF']['address_line1']);

        self::assertSame('Salt Lake City', $byName['KTVX KUCW']['city']);
        self::assertSame('UT', $byName['KTVX KUCW']['state_region']);
        self::assertSame('84104', $byName['KTVX KUCW']['postal_code']);

        self::assertSame('VA', $byName['WAVY WVBT']['state_region']);
        self::assertSame('Portsmouth', $byName['WAVY WVBT']['city']);

        self::assertSame('2501 West Bradley Place', $byName['NewsNation Feed Room']['address_line1']);
        self::assertSame('60618', $byName['NewsNation Feed Room']['postal_code']);

        self::assertTrue($byName['KOIN KRCW']['is_active']);
        self::assertSame('Portland', $byName['KOIN KRCW']['city']);
        self::assertSame('222 SW Columbia Street Suite 102', $byName['KOIN KRCW']['address_line1']);
        self::assertArrayHasKey('KOIN KRCW (Beaverton)', $byName);
        self::assertSame('Beaverton', $byName['KOIN KRCW (Beaverton)']['city']);
        self::assertSame('OR', $byName['KOIN KRCW (Beaverton)']['state_region']);
        self::assertSame('97005', $byName['KOIN KRCW (Beaverton)']['postal_code']);

        self::assertFalse($byName['WJMN']['is_active']);
        self::assertNull($byName['Nashville Design Center NDC']['address_line1']);
        self::assertSame('Nashville', $byName['Nashville Design Center NDC']['city']);

        self::assertSame('Irving', $byName['Nexstar Content Desk']['city']);
        self::assertSame('West Monroe', $byName['KARD KTVE']['city']);
        self::assertSame('Henderson', $byName['WEHT WTVW']['city']);
        self::assertSame('KY', $byName['WEHT WTVW']['state_region']);
        self::assertSame('High Point', $byName['WGHP']['city']);

        self::assertSame('1015 S Fillmore St', $byName['KAMR KCIT KCPN']['address_line1']);
        self::assertSame('Amarillo', $byName['KAMR KCIT KCPN']['city']);
        self::assertSame('10000 Perkins Road', $byName['WGMB WVLA WBRL-CD KZUP-CD']['address_line1']);
        self::assertSame('10849 N. US Hwy 41', $byName['WTWO WAWV']['address_line1']);
        self::assertSame('5219 Hwy 49, Suite A', $byName['WHLT']['address_line1']);
        self::assertSame('Hattiesburg', $byName['WHLT']['city']);
        self::assertSame('3165 Wright Street, Suite 101', $byName['WJMN']['address_line1']);
        self::assertSame('Marquette', $byName['WJMN']['city']);
    }

    public function testSplitCityStateNormalizes(): void
    {
        self::assertSame(['Dallas', 'TX'], NexstarStationCsv::splitCityState('Dallas, TX'));
        self::assertSame(['Norfolk', 'VA'], NexstarStationCsv::splitCityState('Norfolk, Va'));
        self::assertSame(['Salt Lake City', 'UT'], NexstarStationCsv::splitCityState('Salt Lake, UT'));
    }

    public function testParseAddressExtractsSuiteAfterCountry(): void
    {
        $parsed = NexstarStationCsv::parseAddress(
            '400 North Capitol St NW, Washington, DC, USA, Suite 790',
            'Washington',
            'DC'
        );
        self::assertSame('400 North Capitol St NW, Suite 790', $parsed['address_line1']);
        self::assertSame('Washington', $parsed['city']);
        self::assertSame('DC', $parsed['state_region']);
    }
}
