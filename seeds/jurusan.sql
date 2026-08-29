-- ==================================================================
-- SEEDER MASTER DATA JURUSAN ITPLN (14 JURUSAN / PROGRAM STUDI)
-- Digunakan untuk identifikasi jurusan berdasarkan 2 digit tengah NIM
-- ==================================================================

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE `jurusan`;

INSERT INTO `jurusan` (`id`, `kode`, `nama_jurusan`, `aktif`) VALUES
(1, '11', 'Teknik Elektro', 1),
(2, '12', 'Teknik Mesin', 1),
(3, '14', 'Teknik Tenaga Listrik', 1),
(4, '15', 'Teknik Sistem Energi', 1),
(5, '21', 'Teknik Sipil', 1),
(6, '22', 'Teknik Geografi', 1),
(7, '23', 'Teknik Lingkungan', 1),
(8, '31', 'Teknik Informatika', 1),
(9, '32', 'Sistem Informasi', 1),
(10, '33', 'Data Sains', 1),
(11, '41', 'Bisnis Energi', 1),
(12, '42', 'Teknik Industri', 1),
(13, '71', 'Teknologi Listrik', 1),
(14, '72', 'Teknik Mesin', 1);

SET FOREIGN_KEY_CHECKS = 1;
