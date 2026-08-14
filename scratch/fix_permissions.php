<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
    
    // Create user if not exists and grant privileges
    $pdo->exec("CREATE USER IF NOT EXISTS 'magang_app'@'localhost' IDENTIFIED BY 'MagangApp#2026'");
    $pdo->exec("CREATE USER IF NOT EXISTS 'magang_app'@'127.0.0.1' IDENTIFIED BY 'MagangApp#2026'");
    
    $pdo->exec("GRANT ALL PRIVILEGES ON magang_itpln.* TO 'magang_app'@'localhost'");
    $pdo->exec("GRANT ALL PRIVILEGES ON magang_itpln.* TO 'magang_app'@'127.0.0.1'");
    
    $pdo->exec("FLUSH PRIVILEGES");
    
    echo "Privileges granted successfully!\n";

    // Test App connection now
    $pdoApp = new PDO('mysql:host=127.0.0.1;port=3306;dbname=magang_itpln', 'magang_app', 'MagangApp#2026');
    echo "App connection Test: SUCCESS!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
