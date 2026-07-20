<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Core\CronRunRepository;

final class CronRunRepositoryTest extends NexWaypointTestCase
{
    public function testRecordsAggregateSummaryWithoutFreeText(): void
    {
        if (!$this->db->tableExists('cron_job_runs')) {
            self::markTestSkipped('cron_job_runs not in test schema');
        }

        $repo = new CronRunRepository($this->db);
        $id = $repo->begin(CronRunRepository::JOB_POLL_MAIL);
        $repo->finish($id, CronRunRepository::STATUS_OK, [
            'fetched' => 3,
            'success' => 2,
            'failed' => 1,
            'hotel_name' => 'Secret Hotel', // rejected — not a short opaque token pattern for free text with spaces
            'evil' => ['nested' => true], // rejected
            'source' => 'dreamhost_imap',
        ]);

        $latest = $repo->latestByJob();
        self::assertArrayHasKey(CronRunRepository::JOB_POLL_MAIL, $latest);
        $row = $latest[CronRunRepository::JOB_POLL_MAIL];
        self::assertSame('ok', $row['status']);
        self::assertSame(3, $row['summary']['fetched']);
        self::assertSame(2, $row['summary']['success']);
        self::assertSame(1, $row['summary']['failed']);
        self::assertSame('dreamhost_imap', $row['summary']['source']);
        self::assertArrayNotHasKey('hotel_name', $row['summary']);
        self::assertArrayNotHasKey('evil', $row['summary']);
    }
}
