<?php

declare(strict_types=1);

/**
 * Shared hotel property fields for modal create and edit-property page.
 * Expects optional $property (?HotelProperty) and optional $prefix (default '').
 */

use NexWaypoint\Hotels\HotelProperty;

/** @var ?HotelProperty $property */
$property = $property ?? null;
$prefix = $prefix ?? '';
$name = static fn (string $field): string => $prefix . $field;

$val = static function (?HotelProperty $p, string $camel, string $postKey) use ($prefix): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST[$prefix . $postKey])) {
        return (string) $_POST[$prefix . $postKey];
    }
    if ($p === null) {
        return '';
    }
    return (string) ($p->{$camel} ?? '');
};

$checked = static function (?HotelProperty $p, string $camel, string $postKey) use ($prefix): bool {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return isset($_POST[$prefix . $postKey]) && $_POST[$prefix . $postKey] === '1';
    }
    return $p !== null && (bool) $p->{$camel};
};

$amenities = [
    'has_desk' => ['hasDesk', 'Desk suitable for working'],
    'has_pool' => ['hasPool', 'Pool'],
    'has_hot_tub' => ['hasHotTub', 'Hot tub'],
    'has_breakfast' => ['hasBreakfast', 'Breakfast included'],
    'has_gym' => ['hasGym', 'On-site gym'],
    'has_offsite_gym' => ['hasOffsiteGym', 'Off-site gym'],
    'has_free_parking' => ['hasFreeParking', 'Free parking'],
    'has_airport_shuttle' => ['hasAirportShuttle', 'Airport shuttle'],
    'has_ev_charging' => ['hasEvCharging', 'EV charging'],
    'has_onsite_restaurant' => ['hasOnsiteRestaurant', 'On-site restaurant'],
    'walk_to_office' => ['walkToOffice', 'Walking distance to office/venue'],
    'has_destination_fee' => ['hasDestinationFee', 'Charges a destination fee'],
];
?>
<label>Hotel name<input type="text" name="<?= htmlspecialchars($name('hotel_name'), ENT_QUOTES) ?>" required
    value="<?= htmlspecialchars($val($property, 'hotelName', 'hotel_name'), ENT_QUOTES) ?>"></label>
<label>Brand<input type="text" name="<?= htmlspecialchars($name('brand'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($val($property, 'brand', 'brand'), ENT_QUOTES) ?>"></label>
<label>Address line 1<input type="text" name="<?= htmlspecialchars($name('address_line1'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($val($property, 'addressLine1', 'address_line1'), ENT_QUOTES) ?>"></label>
<label>Address line 2<input type="text" name="<?= htmlspecialchars($name('address_line2'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($val($property, 'addressLine2', 'address_line2'), ENT_QUOTES) ?>"></label>
<label>City<input type="text" name="<?= htmlspecialchars($name('city'), ENT_QUOTES) ?>" required
    value="<?= htmlspecialchars($val($property, 'city', 'city'), ENT_QUOTES) ?>"></label>
<label>State / region<input type="text" name="<?= htmlspecialchars($name('state_region'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($val($property, 'stateRegion', 'state_region'), ENT_QUOTES) ?>"></label>
<label>Postal code<input type="text" name="<?= htmlspecialchars($name('postal_code'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($val($property, 'postalCode', 'postal_code'), ENT_QUOTES) ?>"></label>
<label>Country<input type="text" name="<?= htmlspecialchars($name('country'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($val($property, 'country', 'country'), ENT_QUOTES) ?>"></label>
<label>Phone<input type="text" name="<?= htmlspecialchars($name('phone'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($val($property, 'phone', 'phone'), ENT_QUOTES) ?>"></label>

<div class="checkbox-grid">
    <?php foreach ($amenities as $field => [$camel, $label]): ?>
        <label>
            <input type="checkbox" name="<?= htmlspecialchars($name($field), ENT_QUOTES) ?>" value="1"
                <?= $checked($property, $camel, $field) ? 'checked' : '' ?>>
            <?= htmlspecialchars($label, ENT_QUOTES) ?>
        </label>
    <?php endforeach; ?>
</div>

<label>Which office / venue (if walking distance)
    <input type="text" name="<?= htmlspecialchars($name('walk_to_office_notes'), ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($val($property, 'walkToOfficeNotes', 'walk_to_office_notes'), ENT_QUOTES) ?>">
</label>
<label>Destination fee notes (amount / how charged)
    <input type="text" name="<?= htmlspecialchars($name('destination_fee_notes'), ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($val($property, 'destinationFeeNotes', 'destination_fee_notes'), ENT_QUOTES) ?>"
        placeholder="$35/night, resort fee, etc.">
</label>
<label>Desk notes
    <input type="text" name="<?= htmlspecialchars($name('desk_notes'), ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($val($property, 'deskNotes', 'desk_notes'), ENT_QUOTES) ?>">
</label>
<label>Breakfast notes
    <input type="text" name="<?= htmlspecialchars($name('breakfast_notes'), ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($val($property, 'breakfastNotes', 'breakfast_notes'), ENT_QUOTES) ?>">
</label>
<label>WiFi quality (1-5)
    <select name="<?= htmlspecialchars($name('wifi_quality'), ENT_QUOTES) ?>">
        <option value="">—</option>
        <?php for ($i = 1; $i <= 5; $i++): ?>
            <option value="<?= $i ?>" <?= $val($property, 'wifiQuality', 'wifi_quality') === (string) $i ? 'selected' : '' ?>><?= $i ?></option>
        <?php endfor; ?>
    </select>
</label>
<label>Noise level (1 = quiet, 5 = loud)
    <select name="<?= htmlspecialchars($name('noise_level'), ENT_QUOTES) ?>">
        <option value="">—</option>
        <?php for ($i = 1; $i <= 5; $i++): ?>
            <option value="<?= $i ?>" <?= $val($property, 'noiseLevel', 'noise_level') === (string) $i ? 'selected' : '' ?>><?= $i ?></option>
        <?php endfor; ?>
    </select>
</label>
<label>Unique features
    <textarea name="<?= htmlspecialchars($name('unique_features'), ENT_QUOTES) ?>" rows="2"><?= htmlspecialchars($val($property, 'uniqueFeatures', 'unique_features'), ENT_QUOTES) ?></textarea>
</label>
<label>
    <input type="checkbox" name="<?= htmlspecialchars($name('is_blacklisted'), ENT_QUOTES) ?>" value="1"
        <?= $checked($property, 'isBlacklisted', 'is_blacklisted') ? 'checked' : '' ?>>
    Blacklist this property
</label>
<label>Blacklist reason
    <input type="text" name="<?= htmlspecialchars($name('blacklist_reason'), ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($val($property, 'blacklistReason', 'blacklist_reason'), ENT_QUOTES) ?>">
</label>
