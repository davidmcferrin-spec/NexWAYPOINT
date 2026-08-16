<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Users\TeamGanttPalette;
use PHPUnit\Framework\TestCase;

final class TeamGanttPaletteTest extends TestCase
{
    public function testHueIsStablePerUser(): void
    {
        $a = TeamGanttPalette::hue(7);
        $b = TeamGanttPalette::hue(7);
        self::assertSame($a, $b);
        self::assertNotSame(TeamGanttPalette::hueIndex(7), TeamGanttPalette::hueIndex(8));
    }

    public function testShadeMapAssignsInFirstSeenOrder(): void
    {
        $map = TeamGanttPalette::shadeMap(
            ['Huntsville, AL', 'Dallas/Fort Worth, TX', 'Dallas/Fort Worth, TX', 'New York, NY', 'Huntsville, AL'],
            'Huntsville, AL',
        );
        self::assertSame([
            'Dallas/Fort Worth, TX' => 0,
            'New York, NY' => 1,
        ], $map);
        self::assertNotSame(
            TeamGanttPalette::lightnessForShade(0),
            TeamGanttPalette::lightnessForShade(1),
        );
    }
}
