<?php

declare(strict_types=1);

namespace NexWaypoint\Core;

/**
 * Records scheduled job runs for the admin Settings → Jobs page.
 * Summaries must stay aggregate-only (counts/status) — never store travel
 * details, emails, flight numbers, hotel names, or user identifiers.
 */
final class CronRunRepository
{
    public const JOB_POLL_MAIL = 'poll_mail';
    public const JOB_ENRICH_FLIGHTS = 'enrich_flights';

    public const STATUS_RUNNING = 'running';
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAILED = 'failed';

    /** @var array<string, string> */
    public const JOB_LABELS = [
        self::JOB_POLL_MAIL => 'Mail poll',
        self::JOB_ENRICH_FLIGHTS => 'Flight enrichment',
    ];

    public function __construct(
        private readonly Database $db,
    ) {
    }

    public function begin(string $jobName): int
    {
        $this->db->execute(
            'INSERT INTO cron_job_runs (job_name, started_at, status) VALUES (:job, CURRENT_TIMESTAMP, :status)',
            ['job' => $jobName, 'status' => self::STATUS_RUNNING]
        );
        return $this->db->lastInsertId();
    }

    /**
     * @param array<string, int|float|string|bool|null> $summary Aggregate counters only
     */
    public function finish(int $runId, string $status, array $summary = [], ?string $errorClass = null): void
    {
        $allowed = [self::STATUS_OK, self::STATUS_WARNING, self::STATUS_FAILED];
        if (!in_array($status, $allowed, true)) {
            $status = self::STATUS_FAILED;
        }

        $safe = [];
        foreach ($summary as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key)) {
                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_string($value) && strlen($value) <= 40 && preg_match('/^[A-Za-z0-9._-]{1,40}$/', $value)) {
                // Short opaque tokens only (e.g. source name), never free text.
                $safe[$key] = $value;
            }
        }

        $this->db->execute(
            'UPDATE cron_job_runs
             SET finished_at = CURRENT_TIMESTAMP,
                 status = :status,
                 summary_json = :summary,
                 error_class = :error_class
             WHERE id = :id',
            [
                'status' => $status,
                'summary' => $safe === [] ? null : json_encode($safe, JSON_UNESCAPED_SLASHES),
                'error_class' => $errorClass !== null ? substr($errorClass, 0, 120) : null,
                'id' => $runId,
            ]
        );
    }

    /**
     * Latest finished (or still-running) row per known job.
     *
     * @return array<string, array<string, mixed>>
     */
    public function latestByJob(): array
    {
        $out = [];
        foreach (array_keys(self::JOB_LABELS) as $job) {
            $row = $this->db->fetchOne(
                'SELECT * FROM cron_job_runs WHERE job_name = :job ORDER BY started_at DESC, id DESC LIMIT 1',
                ['job' => $job]
            );
            if ($row !== null) {
                $out[$job] = $this->hydrate($row);
            }
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->fetchAll(
            "SELECT * FROM cron_job_runs ORDER BY started_at DESC, id DESC LIMIT {$limit}"
        );
        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $summary = [];
        if (!empty($row['summary_json'])) {
            $decoded = json_decode((string) $row['summary_json'], true);
            if (is_array($decoded)) {
                $summary = $decoded;
            }
        }
        return [
            'id' => (int) $row['id'],
            'job_name' => (string) $row['job_name'],
            'label' => self::JOB_LABELS[$row['job_name']] ?? (string) $row['job_name'],
            'started_at' => (string) $row['started_at'],
            'finished_at' => $row['finished_at'] ?? null,
            'status' => (string) $row['status'],
            'summary' => $summary,
            'error_class' => $row['error_class'] ?? null,
        ];
    }
}
