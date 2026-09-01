<?php
/**
 * api/public/penempatan.php
 * API Publik untuk Rekapitulasi Penempatan Mahasiswa berbasis Folder Hierarki PLN Group.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\SuratGenerator;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getInstance();

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Akses ditolak. Tautan memerlukan token otentikasi resmi.'
        ]);
        exit;
    }

    $periode = SuratGenerator::getPeriodeByToken($pdo, $token);
    if (!$periode) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => 'Tautan pengumuman penempatan tidak valid atau telah diperbarui.'
        ]);
        exit;
    }

    $periodeId = (int)$periode['id'];

    // 1. Ambil daftar Jurusan yang memiliki mahasiswa diterima pada periode ini
    $stmtJurusan = $pdo->prepare("
        SELECT DISTINCT j.id, j.nama_jurusan AS nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN jurusan j ON m.jurusan_id = j.id
        WHERE p.periode_id = :pid AND p.status = 'diterima'
        ORDER BY j.nama_jurusan ASC
    ");
    $stmtJurusan->execute([':pid' => $periodeId]);
    $jurusanList = $stmtJurusan->fetchAll(PDO::FETCH_ASSOC);

    // Ambil struktur hierarki grouped data
    $groupedData = SuratGenerator::getGroupedSuratData($pdo, $periodeId);

    // Hitung total perusahaan / subholding & anak perusahaan yang ada data
    $totalPerusahaan = 1; // Kantor Pusat PLN
    foreach ($groupedData['subholdings'] as $sh) {
        if ($sh['total_mahasiswa'] > 0) $totalPerusahaan++;
    }
    foreach ($groupedData['anak_perusahaan'] as $ap) {
        if ($ap['total_mahasiswa'] > 0) $totalPerusahaan++;
    }

    $viewMode = trim((string)($_GET['view'] ?? ''));

    // Mode TREE (Root View & Navigasi Folder)
    if ($viewMode === 'tree') {
        // Format categories untuk holding_unit
        $categoriesList = [];
        foreach ($groupedData['holding_unit']['categories'] as $k => $c) {
            $categoriesList[] = [
                'key' => $k,
                'nama' => $c['nama'],
                'singkatan' => $c['singkatan'],
                'total_mahasiswa' => $c['total']
            ];
        }

        echo json_encode([
            'ok' => true,
            'periode' => [
                'id' => $periode['id'],
                'nama' => $periode['nama'],
                'tahun_akademik' => $periode['tahun_akademik'] ?? '',
                'perihal' => $periode['perihal'] ?? '',
                'tanggal_surat' => $periode['tanggal_surat'] ?? '',
                'status' => $periode['status'],
                'program_1_bulan' => (int)$periode['program_1_bulan'],
                'program_5_bulan' => (int)$periode['program_5_bulan']
            ],
            'stats' => [
                'total_mahasiswa' => $groupedData['total_semua'],
                'total_perusahaan' => $totalPerusahaan,
                'total_prodi' => count($jurusanList)
            ],
            'tree' => [
                'holding_unit' => [
                    'nama' => 'Kantor Pusat PLN dan Unit-Unit',
                    'total_mahasiswa' => $groupedData['holding_unit']['total_mahasiswa'],
                    'categories' => $categoriesList
                ],
                'subholdings' => array_map(function($sh) {
                    return [
                        'id' => $sh['id'],
                        'nama' => $sh['nama'],
                        'singkatan' => $sh['singkatan'],
                        'alamat' => $sh['alamat'],
                        'total_mahasiswa' => $sh['total_mahasiswa']
                    ];
                }, $groupedData['subholdings']),
                'anak_perusahaan' => array_map(function($ap) {
                    return [
                        'id' => $ap['id'],
                        'nama' => $ap['nama'],
                        'singkatan' => $ap['singkatan'],
                        'alamat' => $ap['alamat'],
                        'total_mahasiswa' => $ap['total_mahasiswa']
                    ];
                }, $groupedData['anak_perusahaan'])
            ],
            'jurusan_list' => $jurusanList
        ]);
        exit;
    }

    // Mode DATA TABLE: Filter data mahasiswa
    $groupType = trim((string)($_GET['group_type'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $entitasId = (int)($_GET['entitas_id'] ?? 0);
    $jurusanFilter = (int)($_GET['jurusan_id'] ?? 0);
    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(10, min(200, (int)($_GET['per_page'] ?? 25)));

    // Ambil list mahasiswa berdasarkan group/kategori/entitas
    $allCandidateStudents = [];
    $folderTitle = 'Semua Mahasiswa Penempatan';

    if ($groupType === 'holding_unit') {
        if ($category !== '' && isset($groupedData['holding_unit']['categories'][$category])) {
            $catObj = $groupedData['holding_unit']['categories'][$category];
            $allCandidateStudents = $catObj['students'];
            $folderTitle = $catObj['nama'];
        } else {
            $allCandidateStudents = $groupedData['holding_unit']['students'];
            $folderTitle = 'Kantor Pusat PLN dan Unit-Unit';
        }
    } elseif ($groupType === 'subholding') {
        if ($entitasId > 0) {
            foreach ($groupedData['subholdings'] as $sh) {
                if ($sh['id'] === $entitasId) {
                    $allCandidateStudents = $sh['students'];
                    $folderTitle = $sh['nama'];
                    break;
                }
            }
        } else {
            // Semua subholding
            foreach ($groupedData['subholdings'] as $sh) {
                foreach ($sh['students'] as $st) {
                    $allCandidateStudents[] = $st;
                }
            }
            $folderTitle = 'Subholding PLN';
        }
    } elseif ($groupType === 'anak_perusahaan') {
        if ($entitasId > 0) {
            foreach ($groupedData['anak_perusahaan'] as $ap) {
                if ($ap['id'] === $entitasId) {
                    $allCandidateStudents = $ap['students'];
                    $folderTitle = $ap['nama'];
                    break;
                }
            }
        } else {
            // Semua anak perusahaan
            foreach ($groupedData['anak_perusahaan'] as $ap) {
                foreach ($ap['students'] as $st) {
                    $allCandidateStudents[] = $st;
                }
            }
            $folderTitle = 'Anak Perusahaan PLN';
        }
    } else {
        // Gabungan semua
        foreach ($groupedData['holding_unit']['students'] as $st) {
            $allCandidateStudents[] = $st;
        }
        foreach ($groupedData['subholdings'] as $sh) {
            foreach ($sh['students'] as $st) {
                $allCandidateStudents[] = $st;
            }
        }
        foreach ($groupedData['anak_perusahaan'] as $ap) {
            foreach ($ap['students'] as $st) {
                $allCandidateStudents[] = $st;
            }
        }
    }

    // Filter tambahan: Jurusan & Search
    $filtered = array_filter($allCandidateStudents, function($s) use ($jurusanFilter, $search, $pdo) {
        if ($jurusanFilter > 0) {
            // Cek nama jurusan
            // Filter via prodi string matching or jurusan_id if available
        }
        if ($search !== '') {
            $sLower = mb_strtolower($search, 'UTF-8');
            $nim = mb_strtolower((string)($s['nim'] ?? ''), 'UTF-8');
            $nama = mb_strtolower((string)($s['nama'] ?? ''), 'UTF-8');
            $prodi = mb_strtolower((string)($s['prodi'] ?? ''), 'UTF-8');
            $unit = mb_strtolower((string)($s['entitas_nama'] ?? ''), 'UTF-8');
            $peminatan = mb_strtolower((string)($s['peminatan'] ?? ''), 'UTF-8');

            if (!str_contains($nim, $sLower) &&
                !str_contains($nama, $sLower) &&
                !str_contains($prodi, $sLower) &&
                !str_contains($unit, $sLower) &&
                !str_contains($peminatan, $sLower)) {
                return false;
            }
        }
        return true;
    });

    if ($jurusanFilter > 0) {
        // Ambil nama jurusan dari database untuk filtering
        $stmtJ = $pdo->prepare("SELECT nama_jurusan FROM jurusan WHERE id = ?");
        $stmtJ->execute([$jurusanFilter]);
        $targetJName = $stmtJ->fetchColumn();
        if ($targetJName) {
            $filtered = array_filter($filtered, function($s) use ($targetJName) {
                return ($s['prodi'] ?? '') === $targetJName;
            });
        }
    }

    $filtered = array_values($filtered);
    $totalFiltered = count($filtered);
    $totalPages = (int)ceil($totalFiltered / $perPage);
    $offset = ($page - 1) * $perPage;
    $pagedData = array_slice($filtered, $offset, $perPage);

    // Format output baris data
    $outputRows = [];
    foreach ($pagedData as $idx => $row) {
        $outputRows[] = [
            'pendaftaran_id' => (int)$row['pendaftaran_id'],
            'nim' => $row['nim'] ?? '-',
            'nama' => $row['nama'] ?? '-',
            'jenis_kelamin' => ($row['jenis_kelamin'] ?? '') === 'P' ? 'Perempuan' : 'Laki - Laki',
            'no_hp' => $row['no_hp'] ?? '-',
            'email' => $row['email'] ?? '-',
            'prodi' => $row['prodi'] ?? '-',
            'ipk' => $row['ipk'] !== null ? number_format((float)$row['ipk'], 2) : '-',
            'jumlah_sks' => (int)($row['jumlah_sks'] ?? 0),
            'peminatan' => $row['peminatan'] ?? '-',
            'unit_penempatan' => $row['entitas_nama'] ?? '-',
            'lokasi_unit_pelaksana' => $row['lokasi_unit_pelaksana'] ?? $row['entitas_nama'] ?? '-',
            'lokasi_unit_layanan' => $row['lokasi_unit_layanan'] ?? '-',
            'program' => $row['program'] ?? '5_bulan'
        ];
    }

    echo json_encode([
        'ok' => true,
        'periode' => [
            'id' => $periode['id'],
            'nama' => $periode['nama'],
            'tahun_akademik' => $periode['tahun_akademik'] ?? '',
            'perihal' => $periode['perihal'] ?? '',
            'tanggal_surat' => $periode['tanggal_surat'] ?? '',
            'status' => $periode['status'],
            'program_1_bulan' => (int)$periode['program_1_bulan'],
            'program_5_bulan' => (int)$periode['program_5_bulan']
        ],
        'folder' => [
            'title' => $folderTitle,
            'group_type' => $groupType,
            'category' => $category,
            'entitas_id' => $entitasId,
            'total_mahasiswa' => count($allCandidateStudents)
        ],
        'stats' => [
            'total_mahasiswa' => $groupedData['total_semua'],
            'total_perusahaan' => $totalPerusahaan,
            'total_prodi' => count($jurusanList)
        ],
        'jurusan_list' => $jurusanList,
        'data' => $outputRows,
        'pagination' => [
            'total' => $totalFiltered,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ]
    ]);

} catch (\Throwable $e) {
    error_log('[api/public/penempatan.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Terjadi kesalahan sistem saat memproses data penempatan.'
    ]);
}
