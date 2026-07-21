<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
customerLogout();
flash('info', 'You have been logged out.');
redirect('customer_login.php');
