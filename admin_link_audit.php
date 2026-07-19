<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
redirect('admin_watchdog.php?tab=rules');
