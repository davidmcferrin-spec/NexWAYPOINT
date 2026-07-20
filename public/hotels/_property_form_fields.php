<?php

declare(strict_types=1);

/**
 * Shared hotel property fields for modal create and edit-property page.
 * Expects optional $property (?HotelProperty) and optional $prefix (default '').
 */

use NexWaypoint\Hotels\HotelBrandRepository;
use NexWaypoint\Hotels\HotelProperty;
use NexWaypoint\Hotels\HotelPropertyRepository;
use NexWaypoint\Hotels\OfficeVenueRepository;

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
    return $p !== null && property_exists($p, $camel) && (bool) $p->{$camel};
};

/** @var array{isBlacklisted?: bool, blacklistReason?: ?string}|null $overrideBlacklist */
$overrideBlacklist = $overrideBlacklist ?? null;

$blacklistChecked = static function () use ($property, $prefix, $overrideBlacklist, $checked): bool {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return isset($_POST[$prefix . 'is_blacklisted']) && $_POST[$prefix . 'is_blacklisted'] === '1';
    }
    if (is_array($overrideBlacklist) && array_key_exists('isBlacklisted', $overrideBlacklist)) {
        return (bool) $overrideBlacklist['isBlacklisted'];
    }
    return false;
};

$blacklistReasonVal = static function () use ($property, $prefix, $overrideBlacklist, $val): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST[$prefix . 'blacklist_reason'])) {
        return (string) $_POST[$prefix . 'blacklist_reason'];
    }
    if (is_array($overrideBlacklist) && array_key_exists('blacklistReason', $overrideBlacklist)) {
        return (string) ($overrideBlacklist['blacklistReason'] ?? '');
    }
    return '';
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
    'has_destination_fee' => ['hasDestinationFee', 'Destination charge'],
];
?>
<label>Hotel name<input type="text" name="<?= htmlspecialchars($name('hotel_name'), ENT_QUOTES) ?>" required
    value="<?= htmlspecialchars($val($property, 'hotelName', 'hotel_name'), ENT_QUOTES) ?>"></label>
<?php
/** @var list<string> $hotelBrandNames */
if (!isset($hotelBrandNames) || !is_array($hotelBrandNames)) {
    $hotelBrandNames = [];
}
if ($hotelBrandNames === [] && isset($app['db'], $app['logger'])) {
    $extraBrand = ($property !== null) ? $property->brand : null;
    $hotelBrandNames = (new HotelBrandRepository($app['db'], $app['logger']))->namesForSelect($extraBrand);
}
$hotelBrandNames = array_values(array_filter(
    $hotelBrandNames,
    static fn ($n) => is_string($n) && trim($n) !== ''
));
$currentBrand = $val($property, 'brand', 'brand');
?>
<label>Brand
    <select name="<?= htmlspecialchars($name('brand'), ENT_QUOTES) ?>">
        <option value="">— none —</option>
        <?php foreach ($hotelBrandNames as $brandName): ?>
            <option value="<?= htmlspecialchars($brandName, ENT_QUOTES) ?>"
                <?= strcasecmp($currentBrand, $brandName) === 0 ? 'selected' : '' ?>>
                <?= htmlspecialchars($brandName, ENT_QUOTES) ?>
            </option>
        <?php endforeach; ?>
    </select>
</label>

<div class="address-search" data-address-search data-field-prefix="<?= htmlspecialchars($prefix, ENT_QUOTES) ?>">
    <label>Look up address or hotel
        <input type="search" data-address-search-input autocomplete="off"
            placeholder="e.g. Hilton Midtown New York or 1335 Avenue of the Americas">
    </label>
    <div class="modal-actions" style="margin:0.35rem 0">
        <button type="button" class="secondary" data-address-search-trigger>Search using hotel name + city</button>
    </div>
    <div class="address-search-results" data-address-search-results hidden></div>
    <p class="hint" data-address-search-status></p>
</div>

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
<?php
$countryValue = $val($property, 'country', 'country');
if ($countryValue === '' && $property === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $countryValue = 'USA';
}
$latValue = $val($property, 'latitude', 'latitude');
$lonValue = $val($property, 'longitude', 'longitude');
?>
<label>Country<input type="text" name="<?= htmlspecialchars($name('country'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($countryValue, ENT_QUOTES) ?>"></label>
<input type="hidden" name="<?= htmlspecialchars($name('latitude'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($latValue, ENT_QUOTES) ?>">
<input type="hidden" name="<?= htmlspecialchars($name('longitude'), ENT_QUOTES) ?>"
    value="<?= htmlspecialchars($lonValue, ENT_QUOTES) ?>">
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

<label>Office / venue (walking distance)
    <input type="text" name="<?= htmlspecialchars($name('walk_to_office_notes'), ENT_QUOTES) ?>"
        list="<?= htmlspecialchars($name('walk_to_office_venues'), ENT_QUOTES) ?>"
        autocomplete="off"
        placeholder="e.g. NewsNation bureau — type or pick from Site settings"
        value="<?= htmlspecialchars($val($property, 'walkToOfficeNotes', 'walk_to_office_notes'), ENT_QUOTES) ?>">
</label>
<?php
/** @var list<string> $walkToOfficeVenues */
if (!isset($walkToOfficeVenues) || !is_array($walkToOfficeVenues)) {
    $walkToOfficeVenues = [];
}
if ($walkToOfficeVenues === [] && isset($app['db'], $app['logger'], $user)) {
    $walkToOfficeVenues = array_values(array_unique(array_merge(
        (new OfficeVenueRepository($app['db'], $app['logger']))->namesForSelect(),
        (new HotelPropertyRepository($app['db'], $app['logger']))->walkToOfficeVenuesForUser((int) $user->id),
    )));
    natcasesort($walkToOfficeVenues);
    $walkToOfficeVenues = array_values($walkToOfficeVenues);
}
$walkToOfficeVenues = array_values(array_filter(
    $walkToOfficeVenues,
    static fn ($n) => is_string($n) && trim($n) !== ''
));
?>
<datalist id="<?= htmlspecialchars($name('walk_to_office_venues'), ENT_QUOTES) ?>">
    <?php foreach ($walkToOfficeVenues as $venue): ?>
        <option value="<?= htmlspecialchars($venue, ENT_QUOTES) ?>"></option>
    <?php endforeach; ?>
</datalist>
<p class="hint">Pick a site office/venue or type a one-off name. Filling this in also marks the property as walkable. Admins manage the catalog under Settings → Site catalogs.</p>
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
        <?= $blacklistChecked() ? 'checked' : '' ?>>
    Blacklist this property (my preference only)
</label>
<label>Blacklist reason
    <input type="text" name="<?= htmlspecialchars($name('blacklist_reason'), ENT_QUOTES) ?>"
        value="<?= htmlspecialchars($blacklistReasonVal(), ENT_QUOTES) ?>">
</label>
