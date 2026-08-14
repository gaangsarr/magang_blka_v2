<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel mahasiswa — profil dasar, di-upsert saat login pertama via Firebase.
 */
final class CreateMahasiswa extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('mahasiswa', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('uid_firebase', 'string', [
                'limit'   => 128,
                'comment' => 'UID dari Firebase Auth',
            ])
            ->addColumn('email', 'string', [
                'limit'   => 150,
            ])
            ->addColumn('nim', 'string', [
                'limit'   => 10,
                'null'    => true,
                'comment' => 'Diparsing dari prefix email: {nama}{AA}{BB}{CCC}',
            ])
            ->addColumn('nama', 'string', [
                'limit'   => 150,
                'null'    => true,
                'comment' => 'displayName dari Microsoft, atau input manual',
            ])
            ->addColumn('needs_nama', 'boolean', [
                'default' => false,
                'comment' => 'true jika nama belum diisi (displayName tidak tersedia)',
            ])
            ->addColumn('jenis_kelamin', 'enum', [
                'values'  => ['L', 'P'],
                'null'    => true,
            ])
            ->addColumn('jurusan_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'FK ke jurusan.id, diisi saat login pertama dari kode NIM',
            ])
            ->addColumn('angkatan', 'smallinteger', [
                'null'    => true,
                'comment' => '2 digit awal NIM, mis. 24 untuk angkatan 2024',
            ])
            ->addColumn('no_urut_absen', 'smallinteger', [
                'null'    => true,
                'comment' => '3 digit akhir NIM',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['uid_firebase'], ['unique' => true])
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['nim'])
            ->create();

        // FK ditambah setelah tabel ada (menghindari errno 150 di MariaDB)
        $this->execute('
            ALTER TABLE mahasiswa
            ADD CONSTRAINT fk_mahasiswa_jurusan
            FOREIGN KEY (jurusan_id) REFERENCES jurusan(id)
            ON DELETE SET NULL ON UPDATE CASCADE
        ');
    }
}

