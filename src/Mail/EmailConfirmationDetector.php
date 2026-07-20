<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * Classifies an inbound email as flight / hotel / train / car / unknown
 * based on sender domain (suffix match), forwarded-body vendor clues, and
 * subject keywords for event type (confirm / change / cancel / ignore /
 * status).
 *
 * Direct vendor mail: From: is aa.com / hilton.com / etc.
 * Forwards: From: is the teammate; classify via body vendor domains and
 * brand phrases so parsers still route correctly.
 */
final class EmailConfirmationDetector
{
    /**
     * @var array<string, string[]>
     */
    private const SENDER_DOMAIN_SUFFIXES = [
        'flight' => [
            'delta.com', 'united.com', 'aa.com', 'southwest.com',
            'alaskaair.com', 'spirit.com', 'flyfrontier.com', 'flybreeze.com',
        ],
        'hotel' => [
            'marriott.com', 'hilton.com', 'ihg.com', 'hyatt.com', 'choicehotels.com',
            'res-marriott.com',
        ],
        'train' => [
            'amtrak.com',
        ],
        'car' => [
            'enterprise.com', 'hertz.com', 'avis.com', 'nationalcar.com',
        ],
    ];

    /**
     * Brand phrases used when From: is the teammate (forwarded copy).
     * Values are canonical domain suffixes for parser routing.
     *
     * @var array<string, array{type: string, domain: string}>
     */
    private const CONTENT_HINTS = [
        'info.email.aa.com' => ['type' => 'flight', 'domain' => 'aa.com'],
        'american airlines' => ['type' => 'flight', 'domain' => 'aa.com'],
        'american eagle' => ['type' => 'flight', 'domain' => 'aa.com'],
        'aadvantage' => ['type' => 'flight', 'domain' => 'aa.com'],
        'aa.com' => ['type' => 'flight', 'domain' => 'aa.com'],
        'your trip confirmation' => ['type' => 'flight', 'domain' => 'aa.com'],
        'delta air lines' => ['type' => 'flight', 'domain' => 'delta.com'],
        'delta.com' => ['type' => 'flight', 'domain' => 'delta.com'],
        'united airlines' => ['type' => 'flight', 'domain' => 'united.com'],
        'united.com' => ['type' => 'flight', 'domain' => 'united.com'],
        'flybreeze.com' => ['type' => 'flight', 'domain' => 'flybreeze.com'],
        'breeze airways' => ['type' => 'flight', 'domain' => 'flybreeze.com'],
        'hilton.com' => ['type' => 'hotel', 'domain' => 'hilton.com'],
        'hilton honors' => ['type' => 'hotel', 'domain' => 'hilton.com'],
        'marriott.com' => ['type' => 'hotel', 'domain' => 'marriott.com'],
        'res-marriott.com' => ['type' => 'hotel', 'domain' => 'marriott.com'],
        'marriott bonvoy' => ['type' => 'hotel', 'domain' => 'marriott.com'],
        'tribute portfolio' => ['type' => 'hotel', 'domain' => 'marriott.com'],
        'autograph collection' => ['type' => 'hotel', 'domain' => 'marriott.com'],
        'amtrak.com' => ['type' => 'train', 'domain' => 'amtrak.com'],
        'amtrak' => ['type' => 'train', 'domain' => 'amtrak.com'],
    ];

    /**
     * @var string[]
     */
    private const RIDESHARE_DOMAINS = ['uber.com', 'lyft.com'];

    /**
     * @var string[]
     */
    private const AIRPORT_KEYWORDS = ['airport', 'terminal', ' int\'l', 'intl', 'international airport'];

    /**
     * @return array{type: string, event: string, subtype: ?string, matched_domain: ?string}
     */
    public function detect(EmailMessage $message): array
    {
        $domain = $this->domainOf($message->fromAddress);
        $type = 'unknown';
        $matched = null;

        foreach (self::SENDER_DOMAIN_SUFFIXES as $candidateType => $suffixes) {
            foreach ($suffixes as $suffix) {
                if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                    $type = $candidateType;
                    $matched = $domain;
                    break 2;
                }
            }
        }

        if ($type === 'unknown') {
            $hint = $this->detectFromContent($message->subject . "\n" . $message->bestText());
            if ($hint !== null) {
                $type = $hint['type'];
                $matched = $hint['domain'];
            }
        }

        if ($type === 'unknown') {
            $forwarded = $this->detectFromForwardedFromHeader($message->bestText());
            if ($forwarded !== null) {
                $type = $forwarded['type'];
                $matched = $forwarded['domain'];
            }
        }

        if ($type === 'unknown' && in_array($domain, self::RIDESHARE_DOMAINS, true)) {
            $haystack = strtolower($message->subject . ' ' . $message->bestText());
            foreach (self::AIRPORT_KEYWORDS as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return [
                        'type' => 'car',
                        'event' => 'confirm',
                        'subtype' => 'rideshare',
                        'matched_domain' => $domain,
                    ];
                }
            }
            return ['type' => 'unknown', 'event' => 'ignore', 'subtype' => null, 'matched_domain' => $domain];
        }

        $event = $this->classifyEvent($type, $message->subject, $message->bestText());

        return [
            'type' => $type,
            'event' => $event,
            'subtype' => null,
            'matched_domain' => $matched,
        ];
    }

    /**
     * @return array{type: string, domain: string}|null
     */
    private function detectFromContent(string $haystack): ?array
    {
        $lower = strtolower($haystack);
        // Prefer longer / more specific hints first.
        $keys = array_keys(self::CONTENT_HINTS);
        usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($keys as $needle) {
            if (str_contains($lower, $needle)) {
                return self::CONTENT_HINTS[$needle];
            }
        }
        return null;
    }

    /**
     * Outlook/Gmail/Proton forwards often include "From: Vendor <addr@domain>".
     *
     * @return array{type: string, domain: string}|null
     */
    private function detectFromForwardedFromHeader(string $text): ?array
    {
        if (preg_match_all(
            '/^From:\s*.*?([a-z0-9._%+\-]+@([a-z0-9.\-]+\.[a-z]{2,}))/mi',
            $text,
            $matches,
            PREG_SET_ORDER
        ) === false || $matches === []) {
            return null;
        }
        foreach ($matches as $match) {
            $domain = strtolower($match[2]);
            foreach (self::SENDER_DOMAIN_SUFFIXES as $candidateType => $suffixes) {
                foreach ($suffixes as $suffix) {
                    if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                        return ['type' => $candidateType, 'domain' => $suffix];
                    }
                }
            }
        }
        return null;
    }

    private function classifyEvent(string $type, string $subject, string $body): string
    {
        $haystack = strtolower($subject . "\n" . $body);
        $subjectLower = strtolower($subject);

        if ($type === 'hotel') {
            if (str_contains($subjectLower, 'enjoyed your stay')
                || str_contains($subjectLower, 'come again soon')
                || (str_contains($subjectLower, 'stay at ') && !str_contains($subjectLower, 'confirmation'))
            ) {
                return 'ignore';
            }
            if (str_contains($subjectLower, 'cancellation') || str_contains($subjectLower, 'canceled')
                || str_contains($subjectLower, 'cancelled')) {
                return 'cancel';
            }
            if (str_contains($subjectLower, 'modified') || str_contains($subjectLower, 'modification')
                || str_contains($subjectLower, 'updated reservation') || str_contains($subjectLower, 'reservation update')
                || str_contains($subjectLower, 'change to your') || str_contains($subjectLower, 'itinerary change')) {
                return 'change';
            }
            if (str_contains($subjectLower, 'confirmation') || str_contains($haystack, 'check-in')
                || str_contains($haystack, 'check in')) {
                return 'confirm';
            }
            return 'confirm';
        }

        if ($type === 'flight') {
            if (str_contains($subjectLower, 'status update')
                || str_contains($subjectLower, 'thanks for your purchase')
                || (str_contains($subjectLower, 'receipt') && !str_contains($subjectLower, 'itinerary')
                    && !str_contains($subjectLower, 'confirmation') && !str_contains($subjectLower, 'eticket'))) {
                if (str_contains($subjectLower, 'flight receipt') || str_contains($subjectLower, 'eticket')) {
                    return 'confirm';
                }
                if (str_contains($subjectLower, 'status update')) {
                    return 'status';
                }
                if (str_contains($subjectLower, 'thanks for your purchase')) {
                    return 'ignore';
                }
            }
            if (str_contains($subjectLower, 'refund')) {
                return 'ignore';
            }
            if (str_contains($subjectLower, 'cancel') || str_contains($subjectLower, 'canceled')
                || str_contains($subjectLower, 'cancelled')) {
                return 'cancel';
            }
            if (str_contains($subjectLower, 'time change') || str_contains($subjectLower, 'schedule change')
                || str_contains($subjectLower, 'rebooked') || str_contains($subjectLower, 'rebook')
                || str_contains($subjectLower, 'new itinerary') || str_contains($subjectLower, 'itinerary change')
                || str_contains($subjectLower, 'flight update') || str_contains($subjectLower, 'updated itinerary')) {
                return 'change';
            }
            return 'confirm';
        }

        if ($type === 'train') {
            if (str_contains($subjectLower, 'refund') || str_contains($subjectLower, 'cancel')
                || str_contains($subjectLower, 'canceled') || str_contains($subjectLower, 'cancelled')) {
                return 'cancel';
            }
            if (str_contains($subjectLower, 'updated') || str_contains($subjectLower, 'schedule change')
                || str_contains($subjectLower, 'modified') || str_contains($subjectLower, 'change to')) {
                return 'change';
            }
            return 'confirm';
        }

        return 'confirm';
    }

    private function domainOf(string $emailAddress): string
    {
        $parts = explode('@', $emailAddress);
        return strtolower(trim(end($parts)));
    }
}
