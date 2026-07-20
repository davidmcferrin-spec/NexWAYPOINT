<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Core\AppearanceCatalog;
use NexWaypoint\Core\SiteSettingsRepository;

final class SiteSettingsTest extends NexWaypointTestCase
{
    public function testAppearanceDefaultsAndPersistence(): void
    {
        $repo = new SiteSettingsRepository($this->db, $this->logger);
        self::assertTrue($repo->tableReady());

        self::assertSame(
            AppearanceCatalog::defaultMapBasemap(),
            AppearanceCatalog::resolveMapBasemap(null)['id']
        );
        self::assertSame(
            'carto_voyager',
            AppearanceCatalog::resolveMapBasemap('carto_voyager')['id']
        );
        self::assertSame(
            AppearanceCatalog::defaultMapBasemap(),
            AppearanceCatalog::resolveMapBasemap('not-a-real-style')['id']
        );

        $repo->setMany([
            SiteSettingsRepository::KEY_MAP_STYLE => 'carto_positron',
            SiteSettingsRepository::KEY_UI_THEME_DEFAULT => 'dark',
            SiteSettingsRepository::KEY_UI_THEME_LOCK => '1',
            SiteSettingsRepository::KEY_MAP_HOTEL_COLOR => '#112233',
        ], 1);

        self::assertSame('carto_positron', $repo->get(SiteSettingsRepository::KEY_MAP_STYLE));
        self::assertSame('dark', $repo->get(SiteSettingsRepository::KEY_UI_THEME_DEFAULT));
        self::assertTrue($repo->getBool(SiteSettingsRepository::KEY_UI_THEME_LOCK));
        self::assertSame('#112233', $repo->get(SiteSettingsRepository::KEY_MAP_HOTEL_COLOR));
        self::assertSame(
            '#112233',
            AppearanceCatalog::normalizeHexColor('#112233', AppearanceCatalog::defaultHotelColor())
        );
        self::assertSame(
            AppearanceCatalog::defaultHotelColor(),
            AppearanceCatalog::normalizeHexColor('not-hex', AppearanceCatalog::defaultHotelColor())
        );
    }
}
