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
 *
 * Lookups are pinned to the segment's travel day: GET /flights/{ident}
 * with start/end around origin-TZ depart, then pick the closest
 * origin/destination match. Once fa_flight_id is known, refreshes use that.
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
        private readonly ?AirportRepository $airports = null,
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
     * GET /flights/{ident} with optional scheduled_out window.
     *
     * @return list<array<string, mixed>>
     */
    public function getFlights(
        string $ident,
        ?\DateTimeImmutable $startUtc = null,
        ?\DateTimeImmutable $endUtc = null,
        string $identType = 'designator',
    ): array {
        $query = ['ident_type' => $identType];
        if ($startUtc !== null) {
            $query['start'] = $startUtc->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        if ($endUtc !== null) {
            $query['end'] = $endUtc->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        $path = '/flights/' . rawurlencode($ident) . '?' . http_build_query($query);
        $response = $this->request($path);
        if ($response === null || empty($response['flights']) || !is_array($response['flights'])) {
            return [];
        }

        /** @var list<array<string, mixed>> $flights */
        $flights = [];
        foreach ($response['flights'] as $flight) {
            if (is_array($flight)) {
                $flights[] = $flight;
            }
        }
        return $flights;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFlightById(string $faFlightId): ?array
    {
        $faFlightId = trim($faFlightId);
        if ($faFlightId === '') {
            return null;
        }
        $flights = $this->getFlights($faFlightId, null, null, 'fa_flight_id');
        return $flights[0] ?? null;
    }

    /**
     * Build UTC start/end window around the segment's planned depart
     * (origin airport wall-clock). Returns null when depart_dt is missing.
     *
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, expected: \DateTimeImmutable}|null
     */
    public function lookupWindowForSegment(TripSegment $segment, ?\DateTimeImmutable $nowUtc = null): ?array
    {
        if ($segment->departDt === null || trim($segment->departDt) === '') {
            return null;
        }

        $airports = $this->airports ?? new AirportRepository(null, $this->logger);
        $expected = $airports->instant($segment->origin, (string) $segment->departDt)
            ->setTimezone(new \DateTimeZone('UTC'));

        $start = $expected->modify('-6 hours');
        $end = $expected->modify('+18 hours');

        // AeroAPI: start ≥ now-10d, end ≤ now+2d (approx). Clamp the window.
        $nowUtc ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $minStart = $nowUtc->modify('-10 days');
        $maxEnd = $nowUtc->modify('+2 days');
        if ($start < $minStart) {
            $start = $minStart;
        }
        if ($end > $maxEnd) {
            $end = $maxEnd;
        }
        if ($end <= $start) {
            // Travel day outside AeroAPI's live window — still try a 1-day slice at the clamp edge.
            $end = $start->modify('+1 day');
            if ($end > $maxEnd) {
                $end = $maxEnd;
            }
            if ($end <= $start) {
                return null;
            }
        }

        return ['start' => $start, 'end' => $end, 'expected' => $expected];
    }

    /**
     * Prefer route match + closest scheduled_out to expected depart.
     *
     * @param list<array<string, mixed>> $flights
     * @return array<string, mixed>|null
     */
    public function selectBestFlight(
        array $flights,
        TripSegment $segment,
        \DateTimeImmutable $expectedDepartUtc,
    ): ?array {
        if ($flights === []) {
            return null;
        }

        $wantOrigin = strtoupper(trim((string) ($segment->origin ?? '')));
        $wantDest = strtoupper(trim((string) ($segment->destination ?? '')));
        $best = null;
        $bestScore = null;

        foreach ($flights as $flight) {
            if (!is_array($flight)) {
                continue;
            }
            $scheduledRaw = $flight['scheduled_out'] ?? $flight['scheduled_off'] ?? null;
            if (!is_string($scheduledRaw) || $scheduledRaw === '') {
                continue;
            }
            try {
                $scheduled = (new \DateTimeImmutable($scheduledRaw))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                continue;
            }

            $absMinutes = (int) round(abs($scheduled->getTimestamp() - $expectedDepartUtc->getTimestamp()) / 60);
            // Hard reject gross mismatches (e.g. wrong day still in a wide page).
            if ($absMinutes > 18 * 60) {
                continue;
            }

            $score = -$absMinutes;
            $origin = $this->airportIataFromFlight($flight['origin'] ?? null);
            $dest = $this->airportIataFromFlight($flight['destination'] ?? null);
            if ($wantOrigin !== '' && $origin === $wantOrigin) {
                $score += 10_000;
            }
            if ($wantDest !== '' && $dest === $wantDest) {
                $score += 10_000;
            }
            if (!empty($flight['cancelled'])) {
                $score -= 5_000;
            }
            if (!empty($flight['diverted'])) {
                $score -= 500;
            }

            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $best = $flight;
            }
        }

        return $best;
    }

    /**
     * GET /flights/{ident}/track. Prefer fa_flight_id when available.
     *
     * @return array{latitude: float, longitude: float, altitude: int, groundspeed: int, heading: int}|null
     */
    public function getTrack(string $ident): ?array
    {
        $response = $this->request('/flights/' . rawurlencode($ident) . '/track');
        if ($response === null || empty($response['positions'])) {
            return null;
        }

        $positions = $response['positions'];
        $latest = end($positions);
        if (!is_array($latest)) {
            return null;
        }

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
        return $this->request('/airports/' . rawurlencode($airportId) . '/delays');
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

        $existing = $segment->id !== null ? $this->flightStatusRepo->findBySegment($segment->id) : null;
        $existingFaId = is_array($existing) && !empty($existing['fa_flight_id'])
            ? trim((string) $existing['fa_flight_id'])
            : '';

        $flight = null;
        if ($existingFaId !== '') {
            $flight = $this->getFlightById($existingFaId);
            if ($flight === null) {
                $this->logger->info('FlightAware: sticky fa_flight_id miss; falling back to dated ident lookup', [
                    'segment_id' => $segment->id,
                    'fa_flight_id' => $existingFaId,
                ]);
            }
        }

        if ($flight === null) {
            $window = $this->lookupWindowForSegment($segment);
            if ($window === null) {
                $this->logger->warning('FlightAware: cannot build date window (missing depart_dt or outside API range)', [
                    'segment_id' => $segment->id,
                    'ident' => $flightIdent,
                    'depart_dt' => $segment->departDt,
                ]);
                return null;
            }

            $flights = $this->getFlights($flightIdent, $window['start'], $window['end'], 'designator');
            $flight = $this->selectBestFlight($flights, $segment, $window['expected']);

            // Narrow window can miss; one unscoped page + local filter as fallback.
            if ($flight === null) {
                $flights = $this->getFlights($flightIdent, null, null, 'designator');
                $flight = $this->selectBestFlight($flights, $segment, $window['expected']);
            }
        }

        if ($flight === null) {
            $this->logger->warning('FlightAware: no matching flight for travel date', [
                'ident' => $flightIdent,
                'segment_id' => $segment->id,
                'depart_dt' => $segment->departDt,
                'origin' => $segment->origin,
                'destination' => $segment->destination,
            ]);
            return null;
        }

        $fields = [
            'fa_flight_id' => $flight['fa_flight_id'] ?? null,
            'gate' => $flight['gate_origin'] ?? null,
            'terminal' => $flight['terminal_origin'] ?? null,
            'scheduled_out' => $this->normalizeDt(isset($flight['scheduled_out']) ? (string) $flight['scheduled_out'] : null),
            'estimated_out' => $this->normalizeDt(isset($flight['estimated_out']) ? (string) $flight['estimated_out'] : null),
            'actual_out' => $this->normalizeDt(isset($flight['actual_out']) ? (string) $flight['actual_out'] : null),
            'scheduled_in' => $this->normalizeDt(isset($flight['scheduled_in']) ? (string) $flight['scheduled_in'] : null),
            'estimated_in' => $this->normalizeDt(isset($flight['estimated_in']) ? (string) $flight['estimated_in'] : null),
            'actual_in' => $this->normalizeDt(isset($flight['actual_in']) ? (string) $flight['actual_in'] : null),
            'status' => $flight['status'] ?? null,
            'progress_percent' => isset($flight['progress_percent']) ? (int) $flight['progress_percent'] : null,
            'delay_minutes' => $this->computeDelayMinutes($flight),
        ];

        if (($flight['status'] ?? '') === 'En Route' && $segment->id !== null) {
            $trackIdent = is_string($fields['fa_flight_id']) && $fields['fa_flight_id'] !== ''
                ? (string) $fields['fa_flight_id']
                : $flightIdent;
            $track = $this->getTrack($trackIdent);
            if ($track !== null) {
                $fields['airport_delay_info'] = json_encode(['track' => $track], JSON_UNESCAPED_SLASHES);
            }
        }

        if ($segment->id !== null) {
            $this->flightStatusRepo->upsert($segment->id, $fields);
        }

        $this->logger->info('FlightAware enrichment matched travel-date instance', [
            'segment_id' => $segment->id,
            'ident' => $flightIdent,
            'fa_flight_id' => $fields['fa_flight_id'],
            'scheduled_out' => $fields['scheduled_out'],
            'status' => $fields['status'],
        ]);

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
            $this->logger->error('FlightAware: unexpected HTTP status', [
                'path' => $path,
                'status' => $httpCode,
                'body' => substr((string) $body, 0, 500),
            ]);
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
            $this->logger->warning('Could not open rate limit state file; proceeding without throttling', [
                'file' => $this->rateLimitStateFile,
            ]);
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
            $diff = (new \DateTimeImmutable((string) $estimated))->getTimestamp()
                - (new \DateTimeImmutable((string) $scheduled))->getTimestamp();
            return max(0, (int) round($diff / 60));
        } catch (\Exception) {
            return 0;
        }
    }

    private function airportIataFromFlight(mixed $airportRef): string
    {
        if (!is_array($airportRef)) {
            return '';
        }
        if (!empty($airportRef['code_iata']) && is_string($airportRef['code_iata'])) {
            return strtoupper(trim($airportRef['code_iata']));
        }
        foreach (['code', 'code_icao', 'code_lid'] as $key) {
            if (empty($airportRef[$key]) || !is_string($airportRef[$key])) {
                continue;
            }
            $code = strtoupper(trim($airportRef[$key]));
            if (strlen($code) === 3) {
                return $code;
            }
            // US ICAO → IATA (KHSV → HSV).
            if (strlen($code) === 4 && str_starts_with($code, 'K')) {
                return substr($code, 1);
            }
        }
        return '';
    }
}
