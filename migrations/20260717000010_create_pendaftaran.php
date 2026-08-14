<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel pendaftaran — hasil final submit mahasiswa.
 * Alamat domisili di-snapshot di sini (bukan hanya FK ke mahasiswa)
 * supaya perubahan profil di masa depan tidak mempengaruhi data historis.
 *
 * UNIQUE(mahasiswa_id, periode_id) mencegah submit ganda sebagai last-resort guard.
 */
final class CreatePendaftaran extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pendaftaran', ['id' => true, 'primary_key' => 'id']);
        $table
            // Relasi utama
            ->addColumn('mahasiswa_id', 'integer', ['signed' => false])
            ->addColumn('periode_id', 'integer', ['signed' => false])
            ->addColumn('reservasi_id', 'integer', [
                'null'   => true,
                'signed' => false,
                'comment' => 'Reservasi yang dikonfirmasi saat submit',
            ])
            ->addColumn('unit_pelaksana_periode_id', 'integer', ['signed' => false])

            // Program
            ->addColumn('program', 'enum', [
                'values'  => ['1_bulan', '5_bulan'],
                'comment' => 'Program magang yang dipilih mahasiswa',
            ])

            // Data diri saat submit
            ->addColumn('nama_snapshot', 'string', [
                'limit'   => 150,
                'comment' => 'Snapshot nama saat submit, bukan FK ke mahasiswa',
            ])
            ->addColumn('jenis_kelamin', 'enum', [
                'values' => ['L', 'P'],
            ])
            ->addColumn('ipk', 'decimal', [
                'precision' => 3,
                'scale'     => 2,
                'null'      => true,
            ])
            ->addColumn('jumlah_sks', 'smallinteger', [
                'null' => true,
            ])
            ->addColumn('no_hp', 'string', [
                'limit' => 20,
                'null'  => true,
            ])

            // Snapshot domisili
            ->addColumn('alamat', 'text', ['null' => true])
            ->addColumn('rt', 'string', ['limit' => 5, 'null' => true])
            ->addColumn('rw', 'string', ['limit' => 5, 'null' => true])
            ->addColumn('kelurahan', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('kecamatan', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('kota_kabupaten', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('provinsi', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('latitude', 'decimal', [
                'precision' => 10,
                'scale'     => 7,
                'null'      => true,
                'comment'   => 'Titik pin peta mahasiswa saat submit',
            ])
            ->addColumn('longitude', 'decimal', [
                'precision' => 10,
                'scale'     => 7,
                'null'      => true,
            ])

            // Status
            ->addColumn('status', 'enum', [
                'values'  => ['diajukan', 'diverifikasi', 'ditolak'],
                'default' => 'diajukan',
            ])
            ->addColumn('submitted_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])

            // Constraints
            ->addIndex(['mahasiswa_id', 'periode_id'], ['unique' => true])
            ->addIndex(['periode_id', 'status'])
            ->addIndex(['unit_pelaksana_periode_id'])

            ->addForeignKey('mahasiswa_id', 'mahasiswa', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->create();

        $this->execute('
            ALTER TABLE pendaftaran
            ADD CONSTRAINT fk_pdft_mahasiswa
            FOREIGN KEY (mahasiswa_id) REFERENCES mahasiswa(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ');
        $this->execute('
            ALTER TABLE pendaftaran
            ADD CONSTRAINT fk_pdft_periode
            FOREIGN KEY (periode_id) REFERENCES periode(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ');
        $this->execute('
            ALTER TABLE pendaftaran
            ADD CONSTRAINT fk_pdft_reservasi
            FOREIGN KEY (reservasi_id) REFERENCES reservasi(id)
            ON DELETE SET NULL ON UPDATE CASCADE
        ');
        $this->execute('
            ALTER TABLE pendaftaran
            ADD CONSTRAINT fk_pdft_upp
            FOREIGN KEY (unit_pelaksana_periode_id) REFERENCES unit_pelaksana_periode(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ');
    }
}

