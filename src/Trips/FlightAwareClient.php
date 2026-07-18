<?php

declare(strict_types=1);

namespace NexWaypoint\Trips;

use NexWaypoint\Core\Env;
use NexWaypoint\Core\Logger;

/**
 * FlightAware AeroAPI client (cURL-based; the project has no HTTP client
 * dependency by design). Two things keep this from running up a bill:
 *
 *  - A file-backed token bucket (FLIGHTAWARE_RATE_LIMIT_PER_MINUTE) shared
 *    across cron invocations, since each `php cron/poll_mail.php` or
 *    enrichment run is a fresh process with no in-memory state.
 *  - FlightStatusRepository::needsRefresh() gates every call so a segment
 *    already checked within FLIGHTAWARE_CACHE_MINUTES is skipped unless
 *    the caller explicitly forces a refresh.
 */
final class FlightAwareClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $rateLimitPerMinute;
    private string $rateLimitStateFile;

    public function __construct(
        private readonly Logger $logger,
        private readonly FlightStatusRepository $flightStatusRepo,
        ?string $apiKey = null,
        ?string $baseUrl = null,
    ) {
        $this->apiKey = $apiKey ?? Env::getRequired('FLIGHTAWARE_API_KEY');
        $this->baseUrl = rtrim($baseUrl ?? Env::get('FLIGHTAWARE_BASE_URL', 'https://aeroapi.flightaware.com/aeroapi'), '/');
        $this->rateLimitPerMinute = Env::getInt('FLIGHTAWARE_RATE_LIMIT_PER_MINUTE', 10);
        $this->rateLimitStateFile = Env::get(
            'FLIGHTAWARE_RATELIMIT_STATE_FILE',
            sys_get_temp_dir() . '/nexwaypoint_flightaware_ratelimit.json'
        );
    }

    /**
     * GET /flights/{ident}. Returns the raw decoded response for the most
     * relevant (soonest-departing, not-yet-landed where possible) flight
     * matching $ident, or null if none found.
     *
     * @return array<string, mixed>|null
     */
    public function getFlight(string $ident): ?array
    {
        $response = $this->request("/flights/" . rawurlencode($ident));
        if ($response === null || empty($response['flights'])) {
            return null;
        }

        // AeroAPI returns multiple scheduled instances of a flight number;
        // prefer one that hasn't landed yet, otherwise take the first.
        foreach ($response['flights'] as $flight) {
            if (($flight['status'] ?? '') !== 'Landed') {
                return $flight;
            }
        }
        return $response['flights'][0];
    }

    /**
     * GET /flights/{ident}/track. Only meaningful for an in-flight segment.
     *
     * @return array{latitude: float, longitude: float, altitude: int, groundspeed: int, heading: int}|null
     */
    public function getTrack(string $ident): ?array
    {
        $response = $this->request("/flights/" . rawurlencode($ident) . "/track");
        if ($response === null || empty($response['positions'])) {
            return null;
        }

        $positions = $response['positions'];
        $latest = end($positions);

        return [
            'latitude' => (float) $latest['latitude'],
            'longitude' => (float) $latest['longitude'],
            'altitude' => (int) ($latest['altitude'] ?? 0),
            'groundspeed' => (int) ($latest['groundspeed'] ?? 0),
            'heading' => (int) ($latest['heading'] ?? 0),
        ];
    }

    /**
     * GET /airports/{id}/delays.
     *
     * @return array<string, mixed>|null
     */
    public function getAirportDelays(string $airportId): ?array
    {
        return $this->request("/airports/" . rawurlencode($airportId) . "/delays");
    }

    /**
     * Fetch + map a flight into flight_status columns and persist via
     * FlightStatusRepository, respecting the cache window unless $force.
     *
     * @return array<string, mixed>|null the row that was written, or null if skipped/not found
     */
    public function enrichSegment(TripSegment $segment, string $flightIdent, bool $force = false): ?array
    {
        $cacheMinutes = Env::getInt('FLIGHTAWARE_CACHE_MINUTES', 10);
        if (!$force && $segment->id !== null && !$this->flightStatusRepo->needsRefresh($segment->id, $cacheMinutes)) {
            $this->logger->debug('Skipping FlightAware refresh (within cache window)', ['segment_id' => $segment->id]);
            return null;
        }

        $flight = $this->getFlight($flightIdent);
        if ($flight === null) {
            $this->logger->warning('FlightAware: no flight found', ['ident' => $flightIdent]);
            return null;
        }

        $fields = [
            'fa_flight_id' => $flight['fa_flight_id'] ?? null,
            'gate' => $flight['gate_origin'] ?? null,
            'terminal' => $flight['terminal_origin'] ?? null,
            'scheduled_out' => $this->normalizeDt($flight['scheduled_out'] ?? null),
            'estimated_out' => $this->normalizeDt($flight['estimated_out'] ?? null),
            'actual_out' => $this->normalizeDt($flight['actual_out'] ?? null),
            'scheduled_in' => $this->normalizeDt($flight['scheduled_in'] ?? null),
            'estimated_in' => $this->normalizeDt($flight['estimated_in'] ?? null),
            'actual_in' => $this->normalizeDt($flight['actual_in'] ?? null),
            'status' => $flight['status'] ?? null,
            'progress_percent' => isset($flight['progress_percent']) ? (int) $flight['progress_percent'] : null,
            'delay_minutes' => $this->computeDelayMinutes($flight),
        ];

        if (($flight['status'] ?? '') === 'En Route' && $segment->id !== null) {
            $track = $this->getTrack($flightIdent);
            if ($track !== null) {
                $fields['airport_delay_info'] = json_encode(['track' => $track], JSON_UNESCAPED_SLASHES);
            }
        }

        if ($segment->id !== null) {
            $this->flightStatusRepo->upsert($segment->id, $fields);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(string $path): ?array
    {
        $this->waitForRateLimitSlot();

        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'x-apikey: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $endpoint = explode('?', $path)[0];
        $this->flightStatusRepo->recordUsage($endpoint);

        if ($body === false) {
            $this->logger->error('FlightAware request failed', ['path' => $path, 'curl_error' => $curlError]);
            return null;
        }

        if ($httpCode === 404) {
            $this->logger->info('FlightAware: not found', ['path' => $path]);
            return null;
        }

        if ($httpCode === 429) {
            $this->logger->warning('FlightAware: rate limited by server', ['path' => $path]);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('FlightAware: unexpected HTTP status', ['path' => $path, 'status' => $httpCode, 'body' => substr((string) $body, 0, 500)]);
            return null;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $this->logger->error('FlightAware: invalid JSON response', ['path' => $path]);
            return null;
        }

        return $decoded;
    }

    /**
     * File-backed token bucket. Blocks (sleeps) until a token is available
     * rather than dropping the request -- acceptable because callers are
     * cron jobs, not interactive web requests.
     */
    private function waitForRateLimitSlot(): void
    {
        $dir = dirname($this->rateLimitStateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($this->rateLimitStateFile, 'c+');
        if ($handle === false) {
            $this->logger->warning('Could not open rate limit state file; proceeding without throttling', ['file' => $this->rateLimitStateFile]);
            return;
        }

        flock($handle, LOCK_EX);

        $raw = stream_get_contents($handle);
        $state = $raw !== '' && $raw !== false ? json_decode($raw, true) : null;
        $capacity = (float) $this->rateLimitPerMinute;
        $refillPerSecond = $capacity / 60.0;

        $now = microtime(true);
        $tokens = is_array($state) ? (float) ($state['tokens'] ?? $capacity) : $capacity;
        $lastRefill = is_array($state) ? (float) ($state['last_refill'] ?? $now) : $now;

        $elapsed = max(0.0, $now - $lastRefill);
        $tokens = min($capacity, $tokens + $elapsed * $refillPerSecond);

        if ($tokens < 1.0) {
            $waitSeconds = (1.0 - $tokens) / $refillPerSecond;
            usleep((int) min($waitSeconds, 60) * 1_000_000);
            $tokens = 1.0;
            $now = microtime(true);
        }

        $tokens -= 1.0;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['tokens' => $tokens, 'last_refill' => $now]));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function normalizeDt(?string $iso8601): ?string
    {
        if ($iso8601 === null || $iso8601 === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso8601))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $flight
     */
    private function computeDelayMinutes(array $flight): int
    {
        $scheduled = $flight['scheduled_out'] ?? null;
        $estimated = $flight['estimated_out'] ?? $flight['actual_out'] ?? null;
        if ($scheduled === null || $estimated === null) {
            return 0;
        }
        try {
            $diff = (new \DateTimeImmutable($estimated))->getTimestamp() - (new \DateTimeImmutable($scheduled))->getTimestamp();
            return max(0, (int) round($diff / 60));
        } catch (\Exception) {
            return 0;
        }
    }
}
