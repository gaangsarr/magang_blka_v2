<?php
$files = [
    'public/js/admin/unit.js',
    'public/js/admin/periode.js',
    'public/js/daftar.js'
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // Store CSRF token globally in JS
    if (strpos($content, 'let csrfToken = null;') === false) {
        // Add after 'let' declarations
        $content = preg_replace('/(let [a-zA-Z0-9_]+List = \[\];)/', "$1\nlet csrfToken = null;", $content);
        
        // Sometimes it's at the top. If not matched:
        if (strpos($content, 'let csrfToken = null;') === false) {
            $content = "let csrfToken = null;\n" . $content;
        }
    }
    
    // Extract CSRF token from status API response
    $content = preg_replace('/(const statusData = await statusRes\.json\(\);[^}]*if[^{]*\{)/', "$1\n    if(statusData.csrf_token) csrfToken = statusData.csrf_token;", $content);
    
    // Add X-CSRF-Token header to fetch POST calls
    // Find: headers: { 'Content-Type': 'application/json' }
    // Replace: headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }
    $content = preg_replace('/(headers:\s*\{\s*\'Content-Type\':\s*\'application\/json\')(\s*\})/', "$1, 'X-CSRF-Token': csrfToken$2", $content);
    
    file_put_contents($file, $content);
    echo "Updated frontend $file\n";
}
