<?php
declare(strict_types=1);

/** Catches PHP errors, exceptions & fatals — logs to DB + admin Error Log */

function ensureErrorCatcher(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    getDB()->exec("CREATE TABLE IF NOT EXISTS platform_errors (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        level VARCHAR(24) NOT NULL,
        message TEXT NOT NULL,
        file VARCHAR(512) DEFAULT NULL,
        line INT UNSIGNED DEFAULT NULL,
        url VARCHAR(1024) DEFAULT NULL,
        request_method VARCHAR(10) DEFAULT NULL,
        actor_type VARCHAR(24) DEFAULT NULL,
        actor_id INT UNSIGNED DEFAULT NULL,
        trace MEDIUMTEXT,
        context_json JSON DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        is_resolved TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_level_created (level, created_at),
        INDEX idx_resolved (is_resolved, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function errorCatcherActor(): array
{
    if (!empty($_SESSION['admin_id'])) {
        return ['admin', (int)$_SESSION['admin_id']];
    }
    if (!empty($_SESSION['merchant_id'])) {
        return ['merchant', (int)$_SESSION['merchant_id']];
    }
    return ['guest', 0];
}

function logPlatformError(string $level, string $message, array $context = []): void
{
    static $inFlight = false;
    if ($inFlight || trim($message) === '') {
        return;
    }
    $inFlight = true;

    $file = (string)($context['file'] ?? '');
    $line = isset($context['line']) ? (int)$context['line'] : null;
    $trace = (string)($context['trace'] ?? '');
    unset($context['file'], $context['line'], $context['trace']);

    error_log(sprintf('UniWeb [%s] %s in %s:%s', $level, $message, $file ?: '?', $line ?? '?'));

    try {
        ensureErrorCatcher();
        $level = substr($level, 0, 24);
        $msg = mb_substr($message, 0, 65000);
        $dup = getDB()->prepare('SELECT id FROM platform_errors WHERE is_resolved = 0 AND level = ? AND message = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 1');
        $dup->execute([$level, $msg]);
        if ($dup->fetch()) {
            $inFlight = false;
            return;
        }
        [$actorType, $actorId] = errorCatcherActor();
        $stmt = getDB()->prepare('INSERT INTO platform_errors
            (level, message, file, line, url, request_method, actor_type, actor_id, trace, context_json, ip)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $level,
            $msg,
            $file !== '' ? mb_substr($file, 0, 512) : null,
            $line ?: null,
            mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 1024) ?: null,
            substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10) ?: null,
            $actorType,
            $actorId > 0 ? $actorId : null,
            $trace !== '' ? mb_substr($trace, 0, 65000) : null,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
        prunePlatformErrors(800);
    } catch (Throwable $e) {
        error_log('UniWeb error catcher DB write failed: ' . $e->getMessage());
    } finally {
        $inFlight = false;
    }
}

function prunePlatformErrors(int $keep = 800): void
{
    try {
        $count = (int)getDB()->query('SELECT COUNT(*) FROM platform_errors')->fetchColumn();
        if ($count > $keep + 50) {
            getDB()->exec('DELETE FROM platform_errors WHERE id NOT IN (
                SELECT id FROM (SELECT id FROM platform_errors ORDER BY id DESC LIMIT ' . (int)$keep . ') t
            )');
        }
    } catch (Throwable $e) {
        /* ok */
    }
}

function getRecentPlatformErrors(int $limit = 50, bool $unresolvedOnly = true): array
{
    try {
        ensureErrorCatcher();
        $sql = 'SELECT * FROM platform_errors';
        if ($unresolvedOnly) {
            $sql .= ' WHERE is_resolved = 0';
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';
        $stmt = getDB()->prepare($sql);
        $stmt->execute([max(1, min(200, $limit))]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function countUnresolvedPlatformErrors(): int
{
    try {
        ensureErrorCatcher();
        return (int)getDB()->query("SELECT COUNT(*) FROM platform_errors
            WHERE is_resolved = 0
              AND level != 'watchdog'
              AND message NOT LIKE 'Cron auth failed%'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function resolvePlatformError(int $id): void
{
    ensureErrorCatcher();
    getDB()->prepare('UPDATE platform_errors SET is_resolved = 1 WHERE id = ?')->execute([$id]);
}

function resolveAllPlatformErrors(): int
{
    ensureErrorCatcher();
    return getDB()->exec('UPDATE platform_errors SET is_resolved = 1 WHERE is_resolved = 0');
}

/** Clear repeat watchdog noise + very old warnings on each auto-audit run */
function autoResolveAuditNoise(): int
{
    try {
        ensureErrorCatcher();
        $db = getDB();
        $cleared = 0;
        $cleared += (int)$db->exec("UPDATE platform_errors SET is_resolved = 1 WHERE is_resolved = 0 AND level = 'watchdog'");
        $cleared += (int)$db->exec("UPDATE platform_errors SET is_resolved = 1 WHERE is_resolved = 0 AND message LIKE 'Cron auth failed%'");
        $cleared += (int)$db->exec("UPDATE platform_errors SET is_resolved = 1 WHERE is_resolved = 0 AND level IN ('warning','notice') AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)");
        // Transient deploy races
        $cleared += (int)$db->exec("UPDATE platform_errors SET is_resolved = 1 WHERE is_resolved = 0 AND message LIKE 'Call to undefined function renderMerchantModeToggle%'");
        return $cleared;
    } catch (Throwable $e) {
        return 0;
    }
}

function getErrorLogSummary(): array
{
    try {
        ensureErrorCatcher();
        $db = getDB();
        $total = (int)$db->query('SELECT COUNT(*) FROM platform_errors WHERE is_resolved = 0')->fetchColumn();
        $byLevel = $db->query("SELECT level, COUNT(*) AS c FROM platform_errors WHERE is_resolved = 0 GROUP BY level ORDER BY c DESC")->fetchAll();
        $top = $db->query('SELECT level, message, COUNT(*) AS c FROM platform_errors WHERE is_resolved = 0 GROUP BY level, message ORDER BY c DESC LIMIT 5')->fetchAll();
        return ['total' => $total, 'by_level' => $byLevel, 'top_messages' => $top];
    } catch (Throwable $e) {
        return ['total' => 0, 'by_level' => [], 'top_messages' => []];
    }
}

function uniwebRenderCaughtError(?Throwable $e = null): never
{
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, ($e ? $e->getMessage() : 'Fatal error') . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $isImage = str_contains($uri, 'qr_image.php') || str_ends_with($script, 'qr_image.php');
    if ($isImage) {
        if (!headers_sent()) {
            header('Content-Type: image/png');
        }
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        exit;
    }
    if (str_contains($uri, 'api.php') || str_starts_with($uri, '/api')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Internal server error. Our team has been notified.',
        ]);
        exit;
    }

    $safeMsg = 'Something went wrong on our side. The error was logged automatically — admin can review it in Error Log.';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>UniWeb — Error</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}'
        . '.box{max-width:420px;background:#1e293b;border:1px solid #334155;border-radius:16px;padding:28px;text-align:center}'
        . 'h1{font-size:1.25rem;margin:0 0 12px;color:#f87171}p{font-size:.9rem;line-height:1.5;color:#94a3b8;margin:0}'
        . 'a{color:#38bdf8;text-decoration:none}</style></head><body><div class="box">'
        . '<h1>We hit a snag</h1><p>' . htmlspecialchars($safeMsg, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin-top:16px"><a href="' . htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') . '">← Back to UniWeb</a></p>'
        . '</div></body></html>';
    exit;
}

function initErrorCatcher(): void
{
    if (defined('UNIWEB_ERROR_CATCHER_INIT')) {
        return;
    }
    define('UNIWEB_ERROR_CATCHER_INIT', true);

    set_exception_handler(static function (Throwable $e): void {
        logPlatformError('exception', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'class' => get_class($e),
        ]);
        uniwebRenderCaughtError($e);
    });

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        $level = match (true) {
            in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true) => 'error',
            in_array($severity, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING], true) => 'warning',
            default => 'notice',
        };
        if ($level === 'notice') {
            return false;
        }
        logPlatformError($level, $message, ['file' => $file, 'line' => $line]);
        return false;
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        logPlatformError('fatal', (string)$err['message'], [
            'file' => (string)($err['file'] ?? ''),
            'line' => (int)($err['line'] ?? 0),
            'type' => (int)$err['type'],
        ]);
        uniwebRenderCaughtError(null);
    });
}

/** Watchdog — run key checks; log anything broken (cron or admin) */
function runPlatformWatchdog(): array
{
    $results = [];
    $fail = static function (string $id, string $label, string $detail) use (&$results): void {
        $results[] = ['id' => $id, 'label' => $label, 'ok' => false, 'detail' => $detail];
        $throttleKey = 'watchdog_fail_' . $id;
        $last = (int)getSetting($throttleKey, '0');
        if (time() - $last > 3600) {
            logPlatformError('watchdog', $label . ': ' . $detail, ['check' => $id]);
            if (function_exists('saveAutoAuditMeta')) {
                saveAutoAuditMeta($throttleKey, (string)time());
            }
        }
    };
    $pass = static function (string $id, string $label, string $detail = 'OK') use (&$results): void {
        $results[] = ['id' => $id, 'label' => $label, 'ok' => true, 'detail' => $detail];
    };

    try {
        getDB()->query('SELECT 1');
        $pass('db', 'Database connection');
    } catch (Throwable $e) {
        $fail('db', 'Database connection', $e->getMessage());
    }

    $required = [
        'config.php', 'admin_website.php', 'merchant_toggle_mode.php',
        'includes/baas.php', 'includes/platform_api.php', 'includes/error_catcher.php',
    ];
    foreach ($required as $rel) {
        if (is_file(__DIR__ . '/../' . $rel)) {
            $pass('file_' . $rel, 'File: ' . $rel);
        } else {
            $fail('file_' . $rel, 'Missing file', $rel);
        }
    }

    if (function_exists('runAdminPlatformSelfChecks')) {
        $self = runAdminPlatformSelfChecks();
        foreach ($self['checks'] as $c) {
            if ($c['ok']) {
                $pass('self_' . $c['id'], $c['label'], $c['detail']);
            } else {
                $fail('self_' . $c['id'], $c['label'], $c['detail']);
            }
        }
    }

    if (function_exists('runFullLinkWatchdog')) {
        $linkScan = runFullLinkWatchdog(false);
        if ($linkScan['summary']['broken_links'] > 0) {
            $fail('link_scan', 'Broken internal links', (int)$linkScan['summary']['broken_links'] . ' broken link(s) — see Link Watchdog');
        } else {
            $pass('link_scan', 'Internal links (static scan)', 'No broken links in PHP files');
        }
        if ($linkScan['summary']['missing_files'] > 0) {
            $fail('missing_pages', 'Missing PHP pages', (int)$linkScan['summary']['missing_files'] . ' file(s) missing');
        }
    }

    $optionalSelf = ['self_error_log', 'self_demo_mode_toggle', 'self_verified_not_live'];
    $failed = 0;
    foreach ($results as $r) {
        if (!$r['ok'] && !in_array($r['id'] ?? '', $optionalSelf, true)) {
            $failed++;
        }
    }

    return ['checks' => $results, 'failed' => $failed, 'ok' => $failed === 0];
}

initErrorCatcher();
