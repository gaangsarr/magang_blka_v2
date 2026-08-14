-- Contoh skrip DCL (Data Control Language) untuk server Production
-- Skrip ini hanya untuk dokumentasi kepada tim IT ITPLN/PLN

-- 1. Buat user khusus aplikasi (ganti password_kuat_disini)
CREATE USER 'app_magang'@'localhost' IDENTIFIED BY 'password_kuat_disini';

-- 2. Batasi hak akses HANYA untuk operasi manipulasi data standar (DML)
-- User ini TIDAK BOLEH DROP, ALTER, atau CREATE tabel di production
GRANT SELECT, INSERT, UPDATE, DELETE ON blka_magang.* TO 'app_magang'@'localhost';

-- 3. Terapkan perubahan
FLUSH PRIVILEGES;

-- Opsional: Jika menggunakan event/trigger, berikan hak khusus (jika ada)
-- GRANT TRIGGER, EVENT ON blka_magang.* TO 'app_magang'@'localhost';
