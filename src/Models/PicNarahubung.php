<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database;

class PicNarahubung
{
    /**
     * Mengambil seluruh data PIC narahubung dengan filter pencarian dan agregasi jumlah unit terhubung.
     */
    public static function getAll(array $filters = []): array
    {
        $pdo = Database::getInstance();

        $sql = "
            SELECT 
                p.id,
                p.entitas_id,
                p.area_hcbp,
                p.nama_pic,
                p.no_wa,
                p.email,
                p.keterangan,
                p.aktif,
                p.created_at,
                p.updated_at,
                e.nama AS nama_entitas,
                e.tipe AS tipe_entitas,
                e.singkatan AS singkatan_entitas
            FROM pic_narahubung p
            JOIN entitas_perusahaan e ON p.entitas_id = e.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['search'])) {
            $search = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (
                p.nama_pic LIKE :s1 
                OR p.no_wa LIKE :s2 
                OR p.area_hcbp LIKE :s3 
                OR e.nama LIKE :s4 
                OR e.singkatan LIKE :s5
            )";
            $params[':s1'] = $search;
            $params[':s2'] = $search;
            $params[':s3'] = $search;
            $params[':s4'] = $search;
            $params[':s5'] = $search;
        }

        if (!empty($filters['area_hcbp'])) {
            $sql .= " AND p.area_hcbp = :area_hcbp";
            $params[':area_hcbp'] = $filters['area_hcbp'];
        }

        if (isset($filters['aktif']) && $filters['aktif'] !== '') {
            $sql .= " AND p.aktif = :aktif";
            $params[':aktif'] = (int)$filters['aktif'];
        }

        $sql .= " ORDER BY p.area_hcbp ASC, e.nama ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hitung unit bawahan untuk setiap entitas
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['entitas_id'] = (int)$item['entitas_id'];
            $item['aktif'] = (bool)$item['aktif'];
            $item['total_unit_terhubung'] = self::countConnectedUnits($item['entitas_id']);
        }

        return $items;
    }

    /**
     * Mengambil data PIC berdasarkan ID.
     */
    public static function getById(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                e.nama AS nama_entitas,
                e.tipe AS tipe_entitas,
                e.singkatan AS singkatan_entitas
            FROM pic_narahubung p
            JOIN entitas_perusahaan e ON p.entitas_id = e.id
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $res['id'] = (int)$res['id'];
            $res['entitas_id'] = (int)$res['entitas_id'];
            $res['aktif'] = (bool)$res['aktif'];
            $res['total_unit_terhubung'] = self::countConnectedUnits($res['entitas_id']);
            return $res;
        }
        return null;
    }

    /**
     * Mengambil data PIC berdasarkan entitas_id langsung.
     */
    public static function getByEntitasId(int $entitasId): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                e.nama AS nama_entitas,
                e.tipe AS tipe_entitas,
                e.singkatan AS singkatan_entitas
            FROM pic_narahubung p
            JOIN entitas_perusahaan e ON p.entitas_id = e.id
            WHERE p.entitas_id = :eid AND p.aktif = 1
            LIMIT 1
        ");
        $stmt->execute([':eid' => $entitasId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $res['id'] = (int)$res['id'];
            $res['entitas_id'] = (int)$res['entitas_id'];
            $res['aktif'] = (bool)$res['aktif'];
            return $res;
        }
        return null;
    }

    /**
     * Resolusi hierarki rekursif untuk mencari PIC Narahubung dari suatu unit.
     * Jika unit (mis. ULP/UP3) tidak memiliki PIC langsung, telusuri parent_id ke atas sampai menemukan
     * node Unit Induk/Pusat yang memiliki PIC narahubung aktif.
     * Catatan: PT PLN (Persero) Holding Pusat (ID: 1) hanya berlaku jika mahasiswa magang langsung di Kantor Pusat.
     */
    public static function resolveForUnit(int $unitId): ?array
    {
        $pdo = Database::getInstance();
        $currentId = $unitId;
        $visited = [];
        $isFirst = true;

        while ($currentId !== null && !in_array($currentId, $visited, true)) {
            $visited[] = $currentId;

            // Ambil info entitas saat ini
            $stmt = $pdo->prepare("SELECT id, tipe, parent_id FROM entitas_perusahaan WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $currentId]);
            $currEntity = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currEntity) {
                break;
            }

            // Jika node ini adalah PT PLN (Persero) Holding Pusat (ID: 1), hanya berlaku jika mahasiswa mendaftar langsung di holding tersebut
            if ((int)$currEntity['id'] === 1 && !$isFirst) {
                // Jangan inherit PIC Kantor Pusat ke unit wilayah/pelaksana bawahan
                break;
            }

            // Cek apakah currentId memiliki PIC narahubung aktif
            $pic = self::getByEntitasId($currentId);
            if ($pic) {
                return $pic;
            }

            if (empty($currEntity['parent_id'])) {
                break;
            }

            $currentId = (int)$currEntity['parent_id'];
            $isFirst = false;
        }

        return null;
    }

    /**
     * Menghitung total unit bawahan (anak, cucu, cicit, dsb) di bawah entitas_id tertentu.
     * Khusus PT PLN (Persero) Holding Pusat (ID: 1), tidak memiliki turunan unit operasional langsung.
     */
    public static function countConnectedUnits(int $entitasId): int
    {
        $pdo = Database::getInstance();

        if ($entitasId === 1) {
            return 0; // PT PLN (Persero) Holding Pusat tidak menghitung seluruh kantor se-Indonesia sebagai bawahan langsungnya
        }
        
        // Gunakan recursive CTE untuk menghitung seluruh hierarki turunan
        $sql = "
            WITH RECURSIVE subordinates AS (
                SELECT id, parent_id FROM entitas_perusahaan WHERE parent_id = :eid
                UNION ALL
                SELECT e.id, e.parent_id FROM entitas_perusahaan e
                JOIN subordinates s ON e.parent_id = s.id
            )
            SELECT COUNT(*) AS total FROM subordinates
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':eid' => $entitasId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($res['total'] ?? 0);
        } catch (\Throwable $e) {
            return count(self::getConnectedUnits($entitasId));
        }
    }

    /**
     * Mengambil daftar seluruh unit bawahan (UP3, UPDL, ULP, dll) di bawah entitas_id tertentu.
     */
    public static function getConnectedUnits(int $entitasId): array
    {
        $pdo = Database::getInstance();

        if ($entitasId === 1) {
            return []; // PT PLN (Persero) Holding Pusat tidak memiliki turunan bawahan langsung
        }

        $sql = "
            WITH RECURSIVE subordinates AS (
                SELECT 
                    id, parent_id, tipe, nama, singkatan, alamat, aktif, 1 as level
                FROM entitas_perusahaan 
                WHERE parent_id = :eid
                
                UNION ALL
                
                SELECT 
                    e.id, e.parent_id, e.tipe, e.nama, e.singkatan, e.alamat, e.aktif, s.level + 1 as level
                FROM entitas_perusahaan e
                JOIN subordinates s ON e.parent_id = s.id
            )
            SELECT 
                s.*,
                p.nama AS parent_nama,
                p.singkatan AS parent_singkatan
            FROM subordinates s
            LEFT JOIN entitas_perusahaan p ON s.parent_id = p.id
            ORDER BY s.level ASC, s.tipe ASC, s.nama ASC
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':eid' => $entitasId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Fallback traversal menggunakan PHP array
            $allUnitsStmt = $pdo->query("SELECT id, parent_id, tipe, nama, singkatan, alamat, aktif FROM entitas_perusahaan");
            $allUnits = $allUnitsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $lookup = [];
            foreach ($allUnits as $u) {
                $lookup[$u['parent_id']][] = $u;
            }

            $result = [];
            $queue = [[$entitasId, 1, '']];

            while (!empty($queue)) {
                [$currParentId, $level, $parentName] = array_shift($queue);
                if (isset($lookup[$currParentId])) {
                    foreach ($lookup[$currParentId] as $child) {
                        $child['level'] = $level;
                        $child['parent_nama'] = $parentName;
                        $result[] = $child;
                        $queue[] = [$child['id'], $level + 1, $child['nama']];
                    }
                }
            }

            return $result;
        }
    }

    /**
     * Tambah PIC baru
     */
    public static function create(array $data): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO pic_narahubung (entitas_id, area_hcbp, nama_pic, no_wa, email, keterangan, aktif)
            VALUES (:entitas_id, :area_hcbp, :nama_pic, :no_wa, :email, :keterangan, :aktif)
        ");

        $stmt->execute([
            ':entitas_id' => (int)$data['entitas_id'],
            ':area_hcbp'  => !empty($data['area_hcbp']) ? trim((string)$data['area_hcbp']) : null,
            ':nama_pic'   => trim((string)$data['nama_pic']),
            ':no_wa'      => trim((string)$data['no_wa']),
            ':email'      => !empty($data['email']) ? trim((string)$data['email']) : null,
            ':keterangan' => !empty($data['keterangan']) ? trim((string)$data['keterangan']) : null,
            ':aktif'      => isset($data['aktif']) ? (int)(bool)$data['aktif'] : 1,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Update PIC
     */
    public static function update(int $id, array $data): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            UPDATE pic_narahubung SET
                entitas_id = :entitas_id,
                area_hcbp = :area_hcbp,
                nama_pic = :nama_pic,
                no_wa = :no_wa,
                email = :email,
                keterangan = :keterangan,
                aktif = :aktif
            WHERE id = :id
        ");

        return $stmt->execute([
            ':id'         => $id,
            ':entitas_id' => (int)$data['entitas_id'],
            ':area_hcbp'  => !empty($data['area_hcbp']) ? trim((string)$data['area_hcbp']) : null,
            ':nama_pic'   => trim((string)$data['nama_pic']),
            ':no_wa'      => trim((string)$data['no_wa']),
            ':email'      => !empty($data['email']) ? trim((string)$data['email']) : null,
            ':keterangan' => !empty($data['keterangan']) ? trim((string)$data['keterangan']) : null,
            ':aktif'      => isset($data['aktif']) ? (int)(bool)$data['aktif'] : 1,
        ]);
    }

    /**
     * Hapus PIC
     */
    public static function delete(int $id): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM pic_narahubung WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Toggle status aktif PIC
     */
    public static function toggleStatus(int $id): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE pic_narahubung SET aktif = NOT aktif WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Mengambil daftar seluruh unit besar (unit_induk, holding, anak_perusahaan, subholding)
     * untuk pilihan dropdown form.
     */
    public static function getAvailableUnits(?int $currentPicId = null): array
    {
        $pdo = Database::getInstance();

        $sql = "
            SELECT 
                e.id, 
                e.nama, 
                e.tipe, 
                e.singkatan,
                p.id AS existing_pic_id,
                p.nama_pic AS existing_pic_nama
            FROM entitas_perusahaan e
            LEFT JOIN pic_narahubung p ON e.id = p.entitas_id
            WHERE e.tipe IN ('holding', 'subholding', 'anak_perusahaan', 'unit_induk')
              AND e.aktif = 1
            ORDER BY 
                CASE e.tipe 
                    WHEN 'holding' THEN 1 
                    WHEN 'unit_induk' THEN 2 
                    WHEN 'subholding' THEN 3 
                    WHEN 'anak_perusahaan' THEN 4 
                    ELSE 5 
                END, 
                e.nama ASC
        ";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $hasOtherPic = !empty($r['existing_pic_id']) && (int)$r['existing_pic_id'] !== $currentPicId;
            $result[] = [
                'id' => (int)$r['id'],
                'nama' => $r['nama'],
                'tipe' => $r['tipe'],
                'singkatan' => $r['singkatan'],
                'is_occupied' => $hasOtherPic,
                'occupied_by' => $hasOtherPic ? $r['existing_pic_nama'] : null
            ];
        }

        return $result;
    }

    /**
     * Mengambil daftar area HCBP unik untuk filter dropdown
     */
    public static function getDistinctAreas(): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("
            SELECT DISTINCT area_hcbp 
            FROM pic_narahubung 
            WHERE area_hcbp IS NOT NULL AND area_hcbp != '' 
            ORDER BY 
                CASE 
                    WHEN area_hcbp LIKE '%Pusat%' THEN 0
                    ELSE CAST(REGEXP_SUBSTR(area_hcbp, '[0-9]+') AS UNSIGNED)
                END ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
