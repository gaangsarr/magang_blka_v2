<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Auth;

Auth::logout();
header('Location: /admin/login.html');
exit;
