<?php
try {
    $pdoRoot = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
    echo "Root connection: SUCCESS\n";
    $dbs = $pdoRoot->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    echo "Databases found: " . implode(', ', $dbs) . "\n";
} catch (Exception $e) {
    echo "Root connection FAILED: " . $e->getMessage() . "\n";
}

try {
    $pdoApp = new PDO('mysql:host=127.0.0.1;port=3306;dbname=magang_itpln', 'magang_app', 'MagangApp#2026');
    echo "App connection: SUCCESS\n";
} catch (Exception $e) {
    echo "App connection FAILED: " . $e->getMessage() . "\n";
}
