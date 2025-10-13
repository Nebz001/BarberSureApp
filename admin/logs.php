<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_login();
if (!has_role('admin')) redirect('../login.php');
// Logs page disabled — redirect to dashboard
redirect('admin/dashboard.php');
