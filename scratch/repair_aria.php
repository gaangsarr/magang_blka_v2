<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
    
    // Attempt REPAIR on mysql aria tables
    $ariaTables = ['db', 'user', 'global_priv', 'proxies_priv', 'roles_mapping'];
    foreach ($ariaTables as $tbl) {
        try {
            $res = $pdo->query("REPAIR TABLE mysql.$tbl")->fetchAll(PDO::FETCH_ASSOC);
            echo "REPAIR mysql.$tbl: " . json_encode($res) . "\n";
        } catch (Exception $e) {
            echo "REPAIR mysql.$tbl failed: " . $e->getMessage() . "\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
