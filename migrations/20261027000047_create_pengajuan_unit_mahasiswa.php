<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migrasi untuk tabel pengajuan_unit_mahasiswa
 * Mendukung request kuota unit terdaftar, usulan unit baru, dan dukungan usulan unit.
 */
final class CreatePengajuanUnitMahasiswa extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pengajuan_unit_mahasiswa', [
            'id' => true,
            'primary_key' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn('mahasiswa_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'FK ke mahasiswa.id'
            ])
            ->addColumn('periode_id', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'FK ke periode.id saat pengajuan dibuat (opsional)'
            ])
            ->addColumn('jenis', 'enum', [
                'values' => ['request_kuota', 'usulan_unit_baru', 'dukungan_usulan'],
                'null' => false,
                'comment' => 'Tipe pengajuan: request kuota unit terdaftar, usulan unit baru, atau mendukung usulan'
            ])
            ->addColumn('entitas_id', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'FK ke entitas_perusahaan.id untuk jenis request_kuota'
            ])
            ->addColumn('usulan_induk_id', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'FK ke pengajuan_unit_mahasiswa.id untuk jenis dukungan_usulan'
            ])
            ->addColumn('nama_unit_baru', 'string', [
                'limit' => 150,
                'null' => true,
                'comment' => 'Nama unit baru yang diusulkan mahasiswa'
            ])
            ->addColumn('nama_unit_normal', 'string', [
                'limit' => 150,
                'null' => true,
                'comment' => 'Nama ternormalisasi untuk pencocokan kemiripan/fuzzy'
            ])
            ->addColumn('alamat_unit_baru', 'string', [
                'limit' => 500,
                'null' => true,
                'comment' => 'Alamat lengkap atau wilayah kota unit baru'
            ])
            ->addColumn('pic_nama', 'string', [
                'limit' => 150,
                'null' => true,
                'comment' => 'Nama PIC Narahubung Unit'
            ])
            ->addColumn('pic_kontak', 'string', [
                'limit' => 20,
                'null' => true,
                'comment' => 'Nomor WhatsApp PIC ternormalisasi 62...'
            ])
            ->addColumn('pic_email', 'string', [
                'limit' => 150,
                'null' => true,
                'comment' => 'Email PIC unit'
            ])
            ->addColumn('catatan', 'string', [
                'limit' => 1000,
                'null' => true,
                'comment' => 'Catatan/alasan mahasiswa mengajukan unit ini'
            ])
            ->addColumn('status', 'enum', [
                'values' => ['menunggu', 'diproses', 'disetujui', 'ditolak', 'dibatalkan'],
                'default' => 'menunggu',
                'null' => false,
                'comment' => 'Status tindak lanjut oleh BLKA'
            ])
            ->addColumn('alasan_admin', 'string', [
                'limit' => 1000,
                'null' => true,
                'comment' => 'Catatan atau alasan dari admin BLKA jika ditolak/diproses'
            ])
            ->addColumn('entitas_id_hasil', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'FK ke entitas_perusahaan.id hasil approve atau tautan'
            ])
            ->addColumn('diproses_oleh', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'FK ke admin.id yang memproses pengajuan'
            ])
            ->addColumn('diproses_at', 'datetime', [
                'null' => true,
                'comment' => 'Waktu pengajuan diproses admin'
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['mahasiswa_id', 'status'], ['name' => 'idx_pum_mhs_status'])
            ->addIndex(['jenis', 'status'], ['name' => 'idx_pum_jenis_status'])
            ->addIndex(['entitas_id', 'status'], ['name' => 'idx_pum_entitas_status'])
            ->addIndex(['usulan_induk_id'], ['name' => 'idx_pum_usulan_induk'])
            ->addIndex(['nama_unit_normal'], ['name' => 'idx_pum_nama_normal'])
            ->create();

        // Foreign keys
        $this->execute('
            ALTER TABLE pengajuan_unit_mahasiswa
            ADD CONSTRAINT fk_pum_mahasiswa FOREIGN KEY (mahasiswa_id) REFERENCES mahasiswa(id) ON DELETE CASCADE ON UPDATE CASCADE,
            ADD CONSTRAINT fk_pum_periode FOREIGN KEY (periode_id) REFERENCES periode(id) ON DELETE SET NULL ON UPDATE CASCADE,
            ADD CONSTRAINT fk_pum_entitas FOREIGN KEY (entitas_id) REFERENCES entitas_perusahaan(id) ON DELETE SET NULL ON UPDATE CASCADE,
            ADD CONSTRAINT fk_pum_usulan_induk FOREIGN KEY (usulan_induk_id) REFERENCES pengajuan_unit_mahasiswa(id) ON DELETE CASCADE ON UPDATE CASCADE,
            ADD CONSTRAINT fk_pum_entitas_hasil FOREIGN KEY (entitas_id_hasil) REFERENCES entitas_perusahaan(id) ON DELETE SET NULL ON UPDATE CASCADE,
            ADD CONSTRAINT fk_pum_admin FOREIGN KEY (diproses_oleh) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
        ');
    }

    public function down(): void
    {
        if ($this->hasTable('pengajuan_unit_mahasiswa')) {
            $this->table('pengajuan_unit_mahasiswa')->drop()->save();
        }
    }
}
