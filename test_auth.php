<?php
require_once 'vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['role'] = 'admin';
try {
    require 'api/admin/pendaftar/list.php';
} catch (\Throwable $e) {
    echo $e->getMessage() . "\n" . $e->getTraceAsString();
}
