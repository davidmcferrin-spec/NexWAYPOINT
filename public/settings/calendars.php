<?php

declare(strict_types=1);

use NexWaypoint\Calendar\CalendarFeed;
use NexWaypoint\Calendar\CalendarFeedRepository;
use NexWaypoint\Core\Csrf;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$db = $app['db'];
$logger = $app['logger'];
$settingsSection = 'calendars';

$userRepo = new UserRepository($db, $logger);
$errors = [];
$message = null;
$schemaWarning = null;

if (!$db->tableExists('calendar_feeds')) {
    $schemaWarning = 'Database is missing calendar_feeds. On the server run: php scripts/migrate.php';
}

$feedRepo = $schemaWarning === null ? new CalendarFeedRepository($db, $logger) : null;
$personal = null;
$team = null;

if ($feedRepo !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'ensure_personal') {
                $personal = $feedRepo->ensureForOwner($user->id, CalendarFeed::KIND_PERSONAL, $user->id);
                $message = 'Personal calendar feed ready.';
            } elseif ($action === 'ensure_team') {
                $team = $feedRepo->ensureForOwner($user->id, CalendarFeed::KIND_TEAM, $user->id);
                $message = 'Team calendar feed ready.';
            } elseif ($action === 'rotate_personal') {
                $feed = $feedRepo->ensureForOwner($user->id, CalendarFeed::KIND_PERSONAL, $user->id);
                $personal = $feedRepo->rotateToken((int) $feed->id, $user->id, $user->id);
                $message = 'Personal feed link rotated. Update Outlook/iOS/Android with the new URL.';
            } elseif ($action === 'rotate_team') {
                $feed = $feedRepo->ensureForOwner($user->id, CalendarFeed::KIND_TEAM, $user->id);
                $team = $feedRepo->rotateToken((int) $feed->id, $user->id, $user->id);
                $message = 'Team feed link rotated. Update Outlook/iOS/Android with the new URL.';
            } elseif ($action === 'save_team_members') {
                $feed = $feedRepo->ensureForOwner($user->id, CalendarFeed::KIND_TEAM, $user->id);
                $mode = (string) ($_POST['member_mode'] ?? 'all');
                if ($mode === 'all') {
                    $team = $feedRepo->clearMemberSelection((int) $feed->id, $user->id, $user->id);
                    $message = 'Team feed includes all active teammates.';
                } else {
                    $ids = [];
                    foreach ((array) ($_POST['member_ids'] ?? []) as $raw) {
                        $ids[] = (int) $raw;
                    }
                    $team = $feedRepo->setMembers((int) $feed->id, $user->id, $ids, $user->id);
                    $message = 'Team feed member list saved.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if ($feedRepo !== null) {
    $personal ??= $feedRepo->findForOwner($user->id, CalendarFeed::KIND_PERSONAL);
    $team ??= $feedRepo->findForOwner($user->id, CalendarFeed::KIND_TEAM);
}

$teammates = array_values(array_filter(
    $userRepo->findAllActive(false),
    static fn ($u) => $u->id !== $user->id
));

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $https ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$base = $scheme . '://' . $host;

$feedUrl = static function (?CalendarFeed $feed) use ($base): ?string {
    if ($feed === null) {
        return null;
    }
    return $base . '/feeds/calendar.php?t=' . rawurlencode($feed->token);
};

$webcalUrl = static function (?string $httpsUrl): ?string {
    if ($httpsUrl === null) {
        return null;
    }
    return preg_replace('#^https?#i', 'webcal', $httpsUrl);
};

$personalUrl = $feedUrl($personal);
$teamUrl = $feedUrl($team);
$teamModeAll = $team === null || $team->memberUserIds === null;
$selectedMembers = $team?->memberUserIds ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Calendar feeds</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>Calendar feeds</h1>
    <p>
        Subscribe in Outlook, Microsoft 365, iOS, or Android with a private URL.
        Anyone who has the link can read that feed until you rotate it.
        Calendar apps usually refresh every few hours, not live.
    </p>

    <?php if ($schemaWarning !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($schemaWarning, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php if ($schemaWarning === null): ?>
    <div class="card">
        <h3>My travel</h3>
        <p class="hint">
            Your flights/trains (timed, airport timezones) plus “In {city}” blocks
            between legs until you fly on or re-base home. Full detail — your itinerary.
        </p>
        <?php if ($personalUrl === null): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="ensure_personal">
                <button type="submit" class="primary">Create personal feed link</button>
            </form>
        <?php else: ?>
            <label>Subscribe URL
                <input type="text" readonly id="personal-url" value="<?= htmlspecialchars($personalUrl, ENT_QUOTES) ?>">
            </label>
            <p class="hint">webcal: <?= htmlspecialchars((string) $webcalUrl($personalUrl), ENT_QUOTES) ?></p>
            <div class="row-actions">
                <button type="button" class="secondary" data-copy="#personal-url">Copy URL</button>
                <form method="post" class="inline-form" onsubmit="return confirm('Rotate this link? Your old subscribe URL will stop working.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                    <input type="hidden" name="action" value="rotate_personal">
                    <button type="submit" class="danger">Rotate link</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Team whereabouts</h3>
        <p class="hint">
            Teammate whereabouts as “Name · City” presence blocks (and timed flights
            when sharing allows). Same visibility rules as the team board; private /
            blocked trips are omitted. You are not on your own team feed.
        </p>
        <?php if ($teamUrl === null): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="ensure_team">
                <button type="submit" class="primary">Create team feed link</button>
            </form>
        <?php else: ?>
            <label>Subscribe URL
                <input type="text" readonly id="team-url" value="<?= htmlspecialchars($teamUrl, ENT_QUOTES) ?>">
            </label>
            <p class="hint">webcal: <?= htmlspecialchars((string) $webcalUrl($teamUrl), ENT_QUOTES) ?></p>
            <div class="row-actions">
                <button type="button" class="secondary" data-copy="#team-url">Copy URL</button>
                <form method="post" class="inline-form" onsubmit="return confirm('Rotate this link? Your old subscribe URL will stop working.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                    <input type="hidden" name="action" value="rotate_team">
                    <button type="submit" class="danger">Rotate link</button>
                </form>
            </div>

            <form method="post" class="stack" style="margin-top:1.25rem">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="save_team_members">
                <fieldset>
                    <legend>Who appears on this calendar</legend>
                    <label class="check">
                        <input type="radio" name="member_mode" value="all" <?= $teamModeAll ? 'checked' : '' ?>>
                        All active teammates
                    </label>
                    <label class="check">
                        <input type="radio" name="member_mode" value="selected" <?= !$teamModeAll ? 'checked' : '' ?>>
                        Selected people only
                    </label>
                    <?php if ($teammates === []): ?>
                        <p class="hint">No other active users yet.</p>
                    <?php else: ?>
                        <div class="check-list">
                            <?php foreach ($teammates as $mate): ?>
                                <label class="check">
                                    <input
                                        type="checkbox"
                                        name="member_ids[]"
                                        value="<?= (int) $mate->id ?>"
                                        <?= (!$teamModeAll && in_array($mate->id, $selectedMembers, true)) || $teamModeAll ? 'checked' : '' ?>
                                    >
                                    <?= htmlspecialchars($mate->displayName, ENT_QUOTES) ?>
                                    <span class="hint">@<?= htmlspecialchars($mate->username, ENT_QUOTES) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>
                <button type="submit" class="primary">Save team members</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>How to subscribe</h3>
        <ul class="hint-list">
            <li><strong>Outlook desktop:</strong> Add calendar → From Internet → paste the HTTPS URL.</li>
            <li><strong>Outlook on the web / M365:</strong> Add calendar → Subscribe from web.</li>
            <li><strong>iOS:</strong> Settings → Calendar → Accounts → Add Subscribed Calendar, or open the webcal link.</li>
            <li><strong>Android / Google Calendar:</strong> From URL → paste the HTTPS URL.</li>
        </ul>
    </div>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.querySelector(btn.getAttribute('data-copy'));
        if (!el) return;
        el.select();
        el.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(el.value).then(function () {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = 'Copy URL'; }, 1500);
        }).catch(function () {
            document.execCommand('copy');
        });
    });
});
</script>
</body>
</html>
