<?php

declare(strict_types=1);

/**
 * Shared status override modal. Expects:
 * - $user (User)
 * - $app with db/logger
 * Optional: $statusFlash (array{type: string, text: string}|null)
 */

use NexWaypoint\Core\Csrf;
use NexWaypoint\Trips\TripRepository;
use NexWaypoint\Trips\TripStatusEngine;

/** @var \NexWaypoint\Users\User $user */

if (!isset($app) || !is_array($app) || !isset($app['db'], $app['logger'])) {
    return;
}

$navTripRepo = new TripRepository($app['db'], $app['logger']);
$navStatusEngine = new TripStatusEngine($navTripRepo, $app['logger']);
$navMyStatus = $navStatusEngine->resolveForUser($user->id);
$navMyOverride = $navTripRepo->activeStatusOverride($user->id);

$navTodayYmd = (new DateTimeImmutable('today'))->format('Y-m-d');
$navDefaultExpires = $navMyOverride['expires_on']
    ?? $navMyOverride['effective_date']
    ?? $navTodayYmd;
$navFormStatus = (string) ($navMyOverride['status'] ?? 'home');
$navFormNote = (string) ($navMyOverride['note'] ?? '');
$navFormLocCity = (string) ($navMyOverride['location_city'] ?? '');
$navFormLocState = (string) ($navMyOverride['location_state'] ?? '');
$navOverrideActive = $navMyOverride !== null;
$navTravelOverridesManual = $navOverrideActive
    && !in_array($navMyStatus['status'], ['home', 'office', 'remote', 'unavailable'], true);

$navReturnTo = $_SERVER['REQUEST_URI'] ?? '/dashboard/index.php';
if (!str_starts_with($navReturnTo, '/') || str_starts_with($navReturnTo, '//')) {
    $navReturnTo = '/dashboard/index.php';
}

$navOpenModal = isset($statusFlash) && is_array($statusFlash) && ($statusFlash['type'] ?? '') === 'error';
?>
<div id="status-override-modal" class="modal-backdrop" <?= $navOpenModal ? '' : 'hidden' ?>>
    <div class="modal-panel" role="dialog" aria-labelledby="status-override-modal-title">
        <h2 id="status-override-modal-title">Set status override</h2>
        <p class="hint">
            Temporary status for the team board. Active travel (in flight, layover, hotel)
            still takes priority while you are on the road.
        </p>
        <?php if ($navTravelOverridesManual): ?>
            <p class="hint">
                Travel is showing now; your manual override resumes after travel
                (through <?= htmlspecialchars((string) ($navMyOverride['expires_on'] ?? $navMyOverride['effective_date']), ENT_QUOTES) ?>).
            </p>
        <?php endif; ?>
        <?php if (isset($statusFlash) && is_array($statusFlash) && ($statusFlash['type'] ?? '') === 'error'): ?>
            <p class="alert alert-error"><?= htmlspecialchars((string) $statusFlash['text'], ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="post" action="/status/override.php" class="stack status-override-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($navReturnTo, ENT_QUOTES) ?>">
            <div class="status-override-row">
                <label>Status
                    <select name="status" id="status-override-status" required>
                        <?php foreach ([
                            'home' => 'Home',
                            'office' => 'Office',
                            'remote' => 'Working remote',
                            'unavailable' => 'Unavailable',
                        ] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $navFormStatus === $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Until
                    <input type="date" name="expires_on" required
                        min="<?= htmlspecialchars($navTodayYmd, ENT_QUOTES) ?>"
                        value="<?= htmlspecialchars((string) $navDefaultExpires, ENT_QUOTES) ?>">
                </label>
            </div>
            <div class="status-override-row remote-location-fields" id="remote-location-fields" hidden>
                <label>City
                    <input type="text" name="location_city" maxlength="120"
                        value="<?= htmlspecialchars($navFormLocCity, ENT_QUOTES) ?>"
                        placeholder="Austin">
                </label>
                <label>State
                    <input type="text" name="location_state" maxlength="120"
                        value="<?= htmlspecialchars($navFormLocState, ENT_QUOTES) ?>"
                        placeholder="TX">
                </label>
            </div>
            <p class="hint remote-location-hint" id="remote-location-hint" hidden>
                Remote status requires a city so teammates can place you on the map.
            </p>
            <label>Note (optional)
                <input type="text" name="note" maxlength="255"
                    value="<?= htmlspecialchars($navFormNote, ENT_QUOTES) ?>"
                    placeholder="e.g. WFH while waiting on parts">
            </label>
            <div class="modal-actions">
                <button type="submit" class="primary" name="action" value="set">Save override</button>
                <?php if ($navOverrideActive): ?>
                    <button type="submit" class="secondary" name="action" value="clear">Clear override</button>
                <?php endif; ?>
                <button type="button" class="secondary" data-close-modal>Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('status-override-modal');
    if (!modal) return;
    var statusSelect = document.getElementById('status-override-status');
    var remoteFields = document.getElementById('remote-location-fields');
    var remoteHint = document.getElementById('remote-location-hint');
    var cityInput = remoteFields ? remoteFields.querySelector('input[name="location_city"]') : null;

    function syncRemoteFields() {
        var isRemote = statusSelect && statusSelect.value === 'remote';
        if (remoteFields) remoteFields.hidden = !isRemote;
        if (remoteHint) remoteHint.hidden = !isRemote;
        if (cityInput) cityInput.required = !!isRemote;
    }

    function openModal() {
        modal.hidden = false;
        document.body.classList.add('modal-open');
        syncRemoteFields();
        if (statusSelect) statusSelect.focus();
    }
    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    }
    document.querySelectorAll('[data-open-modal="status-override-modal"]').forEach(function (btn) {
        btn.addEventListener('click', openModal);
    });
    modal.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (ev) {
        if (ev.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !modal.hidden) closeModal();
    });
    if (statusSelect) {
        statusSelect.addEventListener('change', syncRemoteFields);
    }
    syncRemoteFields();
    if (!modal.hidden) {
        document.body.classList.add('modal-open');
    }
})();
</script>
