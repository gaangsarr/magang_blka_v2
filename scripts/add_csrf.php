<?php
$files = [
    'api/admin/entitas/create.php',
    'api/admin/entitas/update.php',
    'api/admin/periode/create.php',
    'api/admin/periode/update_status.php',
    'api/admin/unit/update_kuota.php',
    'api/mahasiswa/batal_reservasi.php',
    'api/mahasiswa/reservasi.php',
    'api/mahasiswa/submit.php'
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if (strpos($content, 'requireCsrfApi') === false) {
        $content = str_replace('Auth::requireAdminApi();', "Auth::requireAdminApi();\nAuth::requireCsrfApi();", $content);
        $content = str_replace('Auth::requireMahasiswaApi();', "Auth::requireMahasiswaApi();\nAuth::requireCsrfApi();", $content);
        file_put_contents($file, $content);
        echo "Updated $file\n";
    }
}
