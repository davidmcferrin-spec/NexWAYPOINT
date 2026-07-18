<?php

declare(strict_types=1);

namespace NexWaypont\Hotels;

/**
 * Answers "what criteria might I be missing?" for the hotel tracker.
 *
 * Two complementary techniques, both fully self-contained (no external AI
 * call, no network dependency -- deliberately, so it works offline on the
 * road):
 *
 *   1. A curated master list of criteria that frequent business travelers
 *      commonly care about but that aren't yet columns in hotel_stays.
 *      These are suggestions for fields to start tracking, not a schema
 *      migration -- if a suggestion earns its keep, promote it to a real
 *      column later.
 *   2. Keyword-frequency scanning of free-text notes/unique_features across
 *      a user's own stay history: if the same theme keeps showing up in
 *      prose (e.g. "thin walls" mentioned 3 times), that's a signal to
 *      formalize it as a structured field instead of re-typing it.
 */
final class CriteriaSuggestionEngine
{
    /**
     * @var array<string, string>
     */
    private const MASTER_CRITERIA = [
        'blackout_curtains' => 'Blackout curtains / room darkness (matters for irregular broadcast schedules)',
        'quiet_room_location' => 'Room away from elevator, ice machine, or street noise',
        'cell_signal_quality' => 'Cell signal strength in-room (relevant when working from the room)',
        'laptop_friendly_desk' => 'Desk height/chair quality suitable for a full workday, not just a laptop shelf',
        'in_room_outlet_count' => 'Number/placement of outlets near the desk and bed',
        'walkability' => 'Walking distance to food/pharmacy without needing a car',
        'parking_cost' => 'Self-parking cost and whether it is included',
        'shuttle_to_venue' => 'Shuttle availability to a specific work site/venue, not just the airport',
        'loyalty_program_value' => 'Points earned vs. redemption value for this property',
        'laundry_availability' => 'On-site or nearby laundry for multi-week trips',
        'checkin_flexibility' => 'Early check-in / late checkout reliability',
        'temperature_control' => 'Working in-room thermostat/HVAC control',
        'elevator_reliability' => 'Elevator wait times / reliability (multi-floor properties)',
        'safety_area_rating' => 'Neighborhood safety, especially for late arrivals after night shoots',
        'pet_policy' => 'Pet policy, if ever traveling with an animal',
        'smoking_policy_enforcement' => 'How well smoking policy is actually enforced (vs. what the listing says)',
    ];

    /**
     * @var array<string, string[]>
     */
    private const NOTE_KEYWORDS = [
        'noise_level' => ['thin walls', 'loud', 'noisy', 'street noise', 'could hear'],
        'wifi_quality' => ['wifi dropped', 'slow wifi', 'no signal', 'weak wifi', 'wifi was'],
        'desk_notes' => ['no outlet', 'uncomfortable chair', 'desk too small', 'no desk'],
        'parking' => ['parking cost', 'expensive parking', 'no parking', 'valet only'],
        'temperature' => ['too cold', 'too hot', 'thermostat', 'no ac', 'no heat'],
    ];

    /**
     * @return array<int, array{key: string, description: string}>
     */
    public function suggestUntrackedCriteria(): array
    {
        $suggestions = [];
        foreach (self::MASTER_CRITERIA as $key => $description) {
            $suggestions[] = ['key' => $key, 'description' => $description];
        }
        return $suggestions;
    }

    /**
     * @param HotelStay[] $stays
     * @param int $minMentions minimum occurrences across stays before flagging
     * @return array<int, array{theme: string, mentions: int, suggestion: string}>
     */
    public function analyzeNotesForRecurringThemes(array $stays, int $minMentions = 2): array
    {
        $counts = array_fill_keys(array_keys(self::NOTE_KEYWORDS), 0);

        foreach ($stays as $stay) {
            $haystack = strtolower(trim(($stay->notes ?? '') . ' ' . ($stay->uniqueFeatures ?? '')));
            if ($haystack === '') {
                continue;
            }
            foreach (self::NOTE_KEYWORDS as $theme => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($haystack, $keyword)) {
                        $counts[$theme]++;
                        break; // count each stay once per theme
                    }
                }
            }
        }

        $results = [];
        foreach ($counts as $theme => $mentions) {
            if ($mentions >= $minMentions) {
                $results[] = [
                    'theme' => $theme,
                    'mentions' => $mentions,
                    'suggestion' => "You've mentioned {$theme} issues in {$mentions} stays' notes -- consider tracking it as a structured field instead of free text.",
                ];
            }
        }

        usort($results, static fn (array $a, array $b) => $b['mentions'] <=> $a['mentions']);
        return $results;
    }
}
