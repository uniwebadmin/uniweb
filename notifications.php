<?php

require_once __DIR__ . '/config.php';

requireLogin();

$merchant = getMerchant();

$db = getDB();



if (isset($_GET['read']) && $_GET['read'] === 'all') {

    $db->prepare('UPDATE notifications SET is_read = 1 WHERE merchant_id = ?')->execute([$merchant['id']]);

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



$notifs = $db->prepare('SELECT * FROM notifications WHERE merchant_id = ? ORDER BY created_at DESC LIMIT 50');

$notifs->execute([$merchant['id']]);

$notifications = $notifs->fetchAll();

$pageTitle = 'Notifications';

require_once __DIR__ . '/header.php';

?>

<div class="flex justify-between items-center mb-6">

    <p class="text-sm text-gray-500"><?= count(array_filter($notifications, fn($n) => !$n['is_read'])) ?> unread</p>

    <a href="?read=all" class="text-sm text-brand-400">Mark all as read</a>

</div>

<div class="space-y-3">

    <?php if (empty($notifications)): ?>

    <div class="glass rounded-xl p-12 text-center text-gray-500">No notifications yet.</div>

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

<?php require_once __DIR__ . '/footer.php'; ?>

