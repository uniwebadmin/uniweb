<?php

require_once __DIR__ . '/config.php';

requireLogin();

$merchant = getMerchant();

if (function_exists('ensureNotificationSchema')) {
    ensureNotificationSchema();
}

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read']) && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $db->prepare('UPDATE notifications SET is_read = 1 WHERE merchant_id = ? AND archived_at IS NULL')->execute([$merchant['id']]);
    flash('success', 'All notifications marked as read.');
    redirect('notifications.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_read']) && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $n = function_exists('archiveOldNotifications') ? archiveOldNotifications((int)$merchant['id'], 30) : 0;
    flash('success', $n > 0 ? $n . ' read notification(s) archived.' : 'Nothing to archive yet (read items older than 30 days).');
    redirect('notifications.php');
}

if (isset($_GET['read']) && ctype_digit((string)$_GET['read'])) {
    $nid = (int)$_GET['read'];
    $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND merchant_id = ?')->execute([$nid, $merchant['id']]);
    $st = $db->prepare('SELECT title, message FROM notifications WHERE id = ? AND merchant_id = ?');
    $st->execute([$nid, $merchant['id']]);
    $row = $st->fetch();
    if ($row) {
        redirect(notificationActionUrl($row));
    }
    redirect('notifications.php');
}

$notifQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$readFilter = trim($_GET['filter'] ?? 'all');
$listParams = listPageParams(25);
$where = 'merchant_id = ? AND archived_at IS NULL';
$params = [$merchant['id']];
if ($notifQ !== '') {
    $like = '%' . strtolower($notifQ) . '%';
    $where .= ' AND (LOWER(title) LIKE ? OR LOWER(message) LIKE ?)';
    array_push($params, $like, $like);
}
if ($readFilter === 'unread') {
    $where .= ' AND is_read = 0';
} elseif ($readFilter === 'read') {
    $where .= ' AND is_read = 1';
}
$countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE {$where}");
$countStmt->execute($params);
$notifTotal = (int)$countStmt->fetchColumn();
$notifs = $db->prepare("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC LIMIT {$listParams['perPage']} OFFSET {$listParams['offset']}");
$notifs->execute($params);
$notifications = $notifs->fetchAll();

$pageTitle = 'Notifications';
require_once __DIR__ . '/header.php';

?>

<div class="flex flex-wrap justify-between items-center gap-3 mb-6">
    <p class="text-sm text-gray-500"><?= count(array_filter($notifications, fn($n) => !$n['is_read'])) ?> unread on this page</p>
    <div class="flex gap-2 items-center flex-wrap">
        <form method="GET" class="flex gap-2 items-center">
            <label class="sr-only" for="notif-q">Search notifications</label>
            <input id="notif-q" type="search" name="q" value="<?= e($notifQ) ?>" placeholder="Search title / message" class="input-field text-sm">
            <select name="filter" class="input-field text-sm" aria-label="Read filter">
                <option value="all" <?= $readFilter==='all'?'selected':'' ?>>All</option>
                <option value="unread" <?= $readFilter==='unread'?'selected':'' ?>>Unread</option>
                <option value="read" <?= $readFilter==='read'?'selected':'' ?>>Read</option>
            </select>
            <button type="submit" class="btn-primary text-sm px-3 py-1.5">Filter</button>
        </form>
        <?= renderExportCsvLink('export_notifications.php?' . http_build_query(['q' => $notifQ, 'filter' => $readFilter])) ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="mark_all_read" value="1">
            <button type="submit" class="text-sm text-brand-400">Mark all as read</button>
        </form>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="archive_read" value="1">
            <button type="submit" class="text-sm text-gray-500">Archive read (30+ days)</button>
        </form>
    </div>
</div>

<div class="space-y-3">

    <?php if (empty($notifications)): ?>

    <?= renderMerchantEmptyState('No notifications yet', 'Alerts appear after payments, settlements and account events. Open the dashboard to collect a test payment.', 'dashboard.php', 'Go to dashboard →') ?>

    <?php else: foreach ($notifications as $n):

        $href = 'notifications.php?read=' . (int)$n['id'];

    ?>

    <a href="<?= e($href) ?>" class="notification-row glass rounded-xl p-5 gap-4 hover:bg-white/[0.03] transition <?= !$n['is_read'] ? 'border-l-2 border-brand-500' : '' ?>">

        <div class="flex-1">

            <p class="font-medium text-sm <?= !$n['is_read'] ? 'text-white' : 'text-gray-400' ?>"><?= e($n['title']) ?></p>

            <p class="text-sm text-gray-500 mt-1"><?= e($n['message']) ?></p>

            <p class="text-xs text-gray-600 mt-2"><?= formatDate($n['created_at']) ?> · Tap to open</p>

        </div>

        <svg class="w-4 h-4 text-gray-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>

    </a>

    <?php endforeach; endif; ?>

</div>
<?= renderListPagination($listParams['page'], $notifTotal, $listParams['perPage'], ['q' => $notifQ, 'filter' => $readFilter]) ?>
<?php require_once __DIR__ . '/footer.php'; ?>
