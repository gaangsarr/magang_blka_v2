<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();
var_dump($_ENV['DB_USER'] ?? 'KOSONG');
