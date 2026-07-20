<?php

declare(strict_types=1);

/**
 * Shared privacy fieldset for hotel/flight forms.
 *
 * Expects in scope:
 *   - array $otherUsers  list of User objects (excluding current user)
 *   - bool $isPrivate
 *   - int[] $blockedUserIds
 *   - string $legend (optional)
 *
 * @var \NexWaypoint\Users\User[] $otherUsers
 * @var bool $isPrivate
 * @var int[] $blockedUserIds
 */

$legend = $legend ?? 'Privacy';
$isPrivate = $isPrivate ?? false;
$blockedUserIds = $blockedUserIds ?? [];
$otherUsers = $otherUsers ?? [];
?>
<fieldset class="privacy-fieldset">
    <legend><?= htmlspecialchars($legend, ENT_QUOTES) ?></legend>
    <label>
        <input type="checkbox" name="is_private" value="1" id="privacy_is_private"
            <?= $isPrivate ? 'checked' : '' ?>
            onchange="document.getElementById('privacy_hide_list').style.opacity = this.checked ? '0.4' : '1';">
        Private — hide from everyone
    </label>
    <div id="privacy_hide_list" style="<?= $isPrivate ? 'opacity:0.4;' : '' ?>">
        <p class="hint">Or hide from selected people only (ignored when Private is checked):</p>
        <?php if ($otherUsers === []): ?>
            <p class="hint">No other users yet.</p>
        <?php else: ?>
            <div class="checkbox-grid">
                <?php foreach ($otherUsers as $other): ?>
                    <label>
                        <input type="checkbox" name="hide_from[]" value="<?= (int) $other->id ?>"
                            <?= in_array($other->id, $blockedUserIds, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($other->displayName, ENT_QUOTES) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</fieldset>
