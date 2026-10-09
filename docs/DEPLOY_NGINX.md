# Panduan Konfigurasi Nginx Server — INTERN ITPLN

Dokumen ini berisi konfigurasi Nginx standar untuk mengamankan dan mendeploy aplikasi INTERN ITPLN di server Linux (VPS / Cloud).

## Konfigurasi Virtual Host (`/etc/nginx/sites-available/intern.conf`)

```nginx
server {
    listen 80;
    server_name magang.itpln.ac.id; # Ganti dengan domain Anda
    root /var/www/magang_blka/v2;

    index index.html index.php;
    charset utf-8;

    # 1. Routing Statis & Fallback
    location / {
        root /var/www/magang_blka/v2/public;
        try_files $uri $uri/ /public/index.html;
    }

    # 2. Routing API Backend
    location /api/ {
        try_files $uri $uri/ =404;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Sesuaikan versi PHP
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param HTTP_PROXY "";
            fastcgi_buffer_size 128k;
            fastcgi_buffers 4 256k;
            fastcgi_busy_buffers_size 256k;
        }
    }

    # 3. BLOKIR FILE & FOLDER SENSITIF (Security Hardening)
    location ~ /\.(env|git|gitignore) {
        deny all;
        return 404;
    }

    location ~ /(migrations|seeds|scripts|scratch|src|vendor|cache|logs) {
        deny all;
        return 404;
    }

    location ~ \.(json|lock|sql|md)$ {
        deny all;
        return 404;
    }

    location ~ ^/(phinx|seed|test_.*)\.php$ {
        deny all;
        return 404;
    }

    # 4. Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://*.firebaseapp.com https://apis.google.com https://www.gstatic.com https://static.cloudflareinsights.com https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com; img-src 'self' data: https://*.tile.openstreetmap.org https://unpkg.com; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https://unpkg.com https://cdn.jsdelivr.net https://*.googleapis.com https://*.firebaseio.com https://nominatim.openstreetmap.org https://photon.komoot.io https://wilayah.web.id https://cloudflareinsights.com; frame-src 'self' https://*.firebaseapp.com;" always;


    # 5. Gzip Compression (Hemat Bandwidth Server)
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied expired no-cache no-store private auth;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml application/json;
    gzip_disable "MSIE [1-6]\.";
}
```

## Setup Cron Job Pembersihan Reservasi Otomatis
Jalankan `crontab -e` di user server (misal `www-data`) dan tambahkan:
```bash
# Jalankan pembersihan reservasi expired setiap 1 menit
* * * * * php /var/www/magang_blka/v2/scripts/cleanup_reservasi.php >> /var/www/magang_blka/v2/logs/cron_cleanup.log 2>&1
```
