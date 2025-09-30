<?php
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/functions.php';
require_login();
if (!has_role('admin')) {
    redirect('login.php');
}
$CURRENT_ADMIN = current_user();
