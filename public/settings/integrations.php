<?php

declare(strict_types=1);

use NexWaypoint\Core\Csrf;
use NexWaypoint\Core\Env;
use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$userRepo = new UserRepository($app['db'], $app['logger']);

if (!$userRepo->isAdmin($user)) {
    http_response_code(403);
    echo 'Site admin access required.';
    exit;
}

$settingsSection = 'integrations';
$errors = [];
$message = null;

$boolFromPost = static function (string $key): string {
    return isset($_POST[$key]) && $_POST[$key] === '1' ? 'true' : 'false';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save_mail') {
                $encryption = strtolower(trim((string) ($_POST['imap_encryption'] ?? 'ssl')));
                if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
                    throw new InvalidArgumentException('IMAP encryption must be ssl, tls, or none.');
                }
                $port = trim((string) ($_POST['imap_port'] ?? ''));
                if ($port === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                    throw new InvalidArgumentException('IMAP port must be a number between 1 and 65535.');
                }
                $source = trim((string) ($_POST['mail_source'] ?? 'dreamhost_imap'));
                if ($source !== 'dreamhost_imap') {
                    throw new InvalidArgumentException('Only dreamhost_imap is supported in v1.');
                }
                $confidence = trim((string) ($_POST['mail_min_parse_confidence'] ?? '0.75'));
                if (!is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1) {
                    throw new InvalidArgumentException('Parse confidence must be between 0 and 1.');
                }

                $updates = [
                    'MAIL_SOURCE' => $source,
                    'IMAP_HOST' => trim((string) ($_POST['imap_host'] ?? '')),
                    'IMAP_PORT' => $port,
                    'IMAP_ENCRYPTION' => $encryption,
                    'IMAP_USERNAME' => trim((string) ($_POST['imap_username'] ?? '')),
                    'IMAP_PASSWORD' => (string) ($_POST['imap_password'] ?? ''),
                    'IMAP_INBOX_FOLDER' => trim((string) ($_POST['imap_inbox_folder'] ?? 'INBOX')),
                    'IMAP_PROCESSED_FOLDER' => trim((string) ($_POST['imap_processed_folder'] ?? 'INBOX.Processed')),
                    'IMAP_FAILED_FOLDER' => trim((string) ($_POST['imap_failed_folder'] ?? 'INBOX.ParseFailed')),
                    'MAIL_DELETE_ON_SUCCESS' => $boolFromPost('mail_delete_on_success'),
                    'MAIL_MIN_PARSE_CONFIDENCE' => $confidence,
                ];
                if ($updates['IMAP_HOST'] === '') {
                    throw new InvalidArgumentException('IMAP host is required.');
                }
                if ($updates['IMAP_USERNAME'] === '') {
                    throw new InvalidArgumentException('IMAP username is required.');
                }
                if (!Env::isSecretSet('IMAP_PASSWORD') && $updates['IMAP_PASSWORD'] === '') {
                    throw new InvalidArgumentException('IMAP password is required (not set yet).');
                }

                $changed = Env::update($updates, Env::INTEGRATION_KEYS);
                $app['db']->audit($user->id, 'update', 'env_integrations', null, [
                    'section' => 'mail',
                    'keys' => $changed,
                ]);
                $app['logger']->info('Mail integration settings updated', [
                    'actor' => $user->id,
                    'keys' => $changed,
                ]);
                $message = $changed === []
                    ? 'No mail settings changed.'
                    : 'Mail / IMAP settings saved.';
            } elseif ($action === 'save_flightaware') {
                $rate = trim((string) ($_POST['flightaware_rate_limit'] ?? ''));
                $cache = trim((string) ($_POST['flightaware_cache_minutes'] ?? ''));
                $budget = trim((string) ($_POST['flightaware_budget'] ?? ''));
                if ($rate === '' || !ctype_digit($rate) || (int) $rate < 1) {
                    throw new InvalidArgumentException('Rate limit must be a positive integer.');
                }
                if ($cache === '' || !ctype_digit($cache) || (int) $cache < 0) {
                    throw new InvalidArgumentException('Cache minutes must be a non-negative integer.');
                }
                if ($budget === '' || !is_numeric($budget) || (float) $budget < 0) {
                    throw new InvalidArgumentException('Monthly budget must be a non-negative number.');
                }
                $baseUrl = trim((string) ($_POST['flightaware_base_url'] ?? ''));
                if ($baseUrl === '' || !preg_match('#^https://#i', $baseUrl)) {
                    throw new InvalidArgumentException('FlightAware base URL must start with https://');
                }

                $updates = [
                    'FLIGHTAWARE_API_KEY' => (string) ($_POST['flightaware_api_key'] ?? ''),
                    'FLIGHTAWARE_BASE_URL' => rtrim($baseUrl, '/'),
                    'FLIGHTAWARE_RATE_LIMIT_PER_MINUTE' => $rate,
                    'FLIGHTAWARE_CACHE_MINUTES' => $cache,
                    'FLIGHTAWARE_MONTHLY_BUDGET_USD' => $budget,
                ];
                if (!Env::isSecretSet('FLIGHTAWARE_API_KEY') && $updates['FLIGHTAWARE_API_KEY'] === '') {
                    throw new InvalidArgumentException('FlightAware API key is required (not set yet).');
                }

                $changed = Env::update($updates, Env::INTEGRATION_KEYS);
                $app['db']->audit($user->id, 'update', 'env_integrations', null, [
                    'section' => 'flightaware',
                    'keys' => $changed,
                ]);
                $app['logger']->info('FlightAware settings updated', [
                    'actor' => $user->id,
                    'keys' => $changed,
                ]);
                $message = $changed === []
                    ? 'No FlightAware settings changed.'
                    : 'FlightAware settings saved.';
            } else {
                throw new InvalidArgumentException('Unknown action.');
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$v = static fn (string $key, string $default = ''): string => (string) (Env::get($key, $default) ?? $default);
$envWritable = Env::path() !== null && is_writable((string) Env::path());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexWAYPOINT &middot; Integrations</title>
    <?php require dirname(__DIR__) . '/_head_assets.php'; ?>
</head>
<body>
<?php require dirname(__DIR__) . '/_nav.php'; ?>
<main class="container">
    <?php require __DIR__ . '/_settings_nav.php'; ?>
    <h1>Integrations</h1>
    <p class="hint">
        Site-wide mail poller and FlightAware credentials (stored in <code>.env</code>).
        Cron jobs pick up changes on the next run.
        <a href="/settings/jobs.php">Cron / service status</a>
    </p>

    <?php if (!$envWritable): ?>
        <p class="alert alert-error">
            <code>.env</code> is not writable by the web user. Fix file permissions, or edit over SSH /
            <code>bash setup.sh</code>.
        </p>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endforeach; ?>
    <?php if ($message !== null): ?>
        <p class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <section class="card stack" style="margin-bottom: 1.5rem;">
        <h2>Mail / IMAP</h2>
        <p class="hint">DreamHost IMAP mailbox used by <code>cron/poll_mail.php</code>.</p>
        <form method="post" class="stack" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="save_mail">
            <input type="hidden" name="mail_source" value="dreamhost_imap">

            <label>IMAP host
                <input type="text" name="imap_host" required
                    value="<?= htmlspecialchars($v('IMAP_HOST', 'imap.dreamhost.com'), ENT_QUOTES) ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <div class="form-row">
                <label>Port
                    <input type="number" name="imap_port" min="1" max="65535" required
                        value="<?= htmlspecialchars($v('IMAP_PORT', '993'), ENT_QUOTES) ?>"
                        <?= $envWritable ? '' : 'disabled' ?>>
                </label>
                <label>Encryption
                    <select name="imap_encryption" <?= $envWritable ? '' : 'disabled' ?>>
                        <?php
                        $enc = strtolower($v('IMAP_ENCRYPTION', 'ssl'));
                        foreach (['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None'] as $opt => $label):
                        ?>
                            <option value="<?= $opt ?>" <?= $enc === $opt ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>Username
                <input type="text" name="imap_username" required autocomplete="off"
                    value="<?= htmlspecialchars($v('IMAP_USERNAME'), ENT_QUOTES) ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <label>Password
                <input type="password" name="imap_password" autocomplete="new-password"
                    placeholder="<?= Env::isSecretSet('IMAP_PASSWORD') ? 'Leave blank to keep current password' : 'Required' ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <?php if (Env::isSecretSet('IMAP_PASSWORD')): ?>
                <p class="hint">Password is set. Enter a new value only to replace it.</p>
            <?php endif; ?>

            <label>Inbox folder
                <input type="text" name="imap_inbox_folder"
                    value="<?= htmlspecialchars($v('IMAP_INBOX_FOLDER', 'INBOX'), ENT_QUOTES) ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <label>Processed folder
                <input type="text" name="imap_processed_folder"
                    value="<?= htmlspecialchars($v('IMAP_PROCESSED_FOLDER', 'INBOX.Processed'), ENT_QUOTES) ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <label>Failed folder
                <input type="text" name="imap_failed_folder"
                    value="<?= htmlspecialchars($v('IMAP_FAILED_FOLDER', 'INBOX.ParseFailed'), ENT_QUOTES) ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <label>
                <input type="checkbox" name="mail_delete_on_success" value="1"
                    <?= Env::getBool('MAIL_DELETE_ON_SUCCESS', true) ? 'checked' : '' ?>
                    <?= $envWritable ? '' : 'disabled' ?>>
                Delete from inbox after successful process (moves to Processed first when configured)
            </label>
            <label>Min parse confidence (0–1)
                <input type="text" name="mail_min_parse_confidence"
                    value="<?= htmlspecialchars($v('MAIL_MIN_PARSE_CONFIDENCE', '0.75'), ENT_QUOTES) ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <button type="submit" class="primary" <?= $envWritable ? '' : 'disabled' ?>>Save mail settings</button>
        </form>
    </section>

    <section class="card stack">
        <h2>FlightAware AeroAPI</h2>
        <p class="hint">Used by <code>cron/enrich_flights.php</code> for live flight status.</p>
        <form method="post" class="stack" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="save_flightaware">

            <label>API key
                <input type="password" name="flightaware_api_key" autocomplete="new-password"
                    placeholder="<?= Env::isSecretSet('FLIGHTAWARE_API_KEY') ? 'Leave blank to keep current key' : 'Required' ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <?php if (Env::isSecretSet('FLIGHTAWARE_API_KEY')): ?>
                <p class="hint">API key is set. Enter a new value only to replace it.</p>
            <?php endif; ?>
            <label>Base URL
                <input type="url" name="flightaware_base_url" required
                    value="<?= htmlspecialchars($v('FLIGHTAWARE_BASE_URL', 'https://aeroapi.flightaware.com/aeroapi'), ENT_QUOTES) ?>"
                    <?= $envWritable ? '' : 'disabled' ?>>
            </label>
            <div class="form-row">
                <label>Rate limit / minute
                    <input type="number" name="flightaware_rate_limit" min="1" required
                        value="<?= htmlspecialchars($v('FLIGHTAWARE_RATE_LIMIT_PER_MINUTE', '10'), ENT_QUOTES) ?>"
                        <?= $envWritable ? '' : 'disabled' ?>>
                </label>
                <label>Cache minutes
                    <input type="number" name="flightaware_cache_minutes" min="0" required
                        value="<?= htmlspecialchars($v('FLIGHTAWARE_CACHE_MINUTES', '10'), ENT_QUOTES) ?>"
                        <?= $envWritable ? '' : 'disabled' ?>>
                </label>
                <label>Monthly budget (USD)
                    <input type="text" name="flightaware_budget" required
                        value="<?= htmlspecialchars($v('FLIGHTAWARE_MONTHLY_BUDGET_USD', '25'), ENT_QUOTES) ?>"
                        <?= $envWritable ? '' : 'disabled' ?>>
                </label>
            </div>
            <button type="submit" class="primary" <?= $envWritable ? '' : 'disabled' ?>>Save FlightAware settings</button>
        </form>
    </section>
</main>
</body>
</html>
