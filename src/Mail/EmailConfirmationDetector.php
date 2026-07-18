<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

/**
 * Classifies an inbound email as flight / hotel / train / car / unknown
 * based on sender domain + subject keyword patterns. v1 ships a curated
 * sender list for major US airlines, hotel brands, rail, and car rental
 * companies; add new senders here as they show up in real confirmations.
 */
final class EmailConfirmationDetector
{
    /**
     * @var array<string, string[]>
     */
    private const SENDER_DOMAINS = [
        'flight' => [
            'delta.com', 'united.com', 'aa.com', 'southwest.com',
            'alaskaair.com', 'spirit.com', 'flyfrontier.com',
        ],
        'hotel' => [
            'marriott.com', 'hilton.com', 'ihg.com', 'hyatt.com', 'choicehotels.com',
        ],
        'train' => [
            'amtrak.com',
        ],
        'car' => [
            'enterprise.com', 'hertz.com', 'avis.com', 'nationalcar.com',
        ],
    ];

    /**
     * Rideshare confirmations are only relevant if they're airport-adjacent
     * (per project scope: log the ride to/from the airport, not every trip).
     *
     * @var string[]
     */
    private const RIDESHARE_DOMAINS = ['uber.com', 'lyft.com'];

    /**
     * @var string[]
     */
    private const AIRPORT_KEYWORDS = ['airport', 'terminal', ' int\'l', 'intl', 'international airport'];

    /**
     * @return array{type: string, subtype: ?string, matched_domain: ?string}
     */
    public function detect(EmailMessage $message): array
    {
        $domain = $this->domainOf($message->fromAddress);

        foreach (self::SENDER_DOMAINS as $type => $domains) {
            if (in_array($domain, $domains, true)) {
                return ['type' => $type, 'subtype' => null, 'matched_domain' => $domain];
            }
        }

        if (in_array($domain, self::RIDESHARE_DOMAINS, true)) {
            $haystack = strtolower($message->subject . ' ' . $message->bestText());
            foreach (self::AIRPORT_KEYWORDS as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return ['type' => 'car', 'subtype' => 'rideshare', 'matched_domain' => $domain];
                }
            }
            return ['type' => 'unknown', 'subtype' => null, 'matched_domain' => $domain];
        }

        return ['type' => 'unknown', 'subtype' => null, 'matched_domain' => $domain];
    }

    private function domainOf(string $emailAddress): string
    {
        $parts = explode('@', $emailAddress);
        return strtolower(trim(end($parts)));
    }
}
