#!/usr/bin/env bash
# ==============================================================================
# INTERN ITPLN — Automated Database Backup Script
# ==============================================================================
# Deskripsi:
#   Script bash untuk melakukan automated backup database MySQL/MariaDB INTERN ITPLN.
#   Fitur:
#   - Membaca kredensial secara aman dari file .env
#   - Menggunakan mysqldump dengan mode non-locking (--single-transaction)
#   - Kompresi maksimal dengan gzip
#   - Rotasi otomatis: menghapus backup lokal yang lebih tua dari N hari (default: 14 hari)
#   - Hook sinkronisasi ke cloud / offsite backup (Rclone, S3, SCP, Rsync)
#   - Logging terstruktur ke file log
#
# Panduan Pasang di Cron Job VPS (Jalankan setiap hari jam 02:00 dini hari):
#   1. Berikan permission eksekusi:
#      chmod +x /path/to/magang_blka/v2/scripts/backup_db.sh
#   2. Buka crontab:
#      crontab -e
#   3. Tambahkan baris:
#      0 2 * * * /path/to/magang_blka/v2/scripts/backup_db.sh >> /var/log/intern_backup.log 2>&1
# ==============================================================================

set -euo pipefail

# 1. Konfigurasi Direktori & Waktu
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${PROJECT_ROOT}/.env"

BACKUP_DIR="${PROJECT_ROOT}/backups"
TIMESTAMP="$(date +"%Y%m%d_%H%M%S")"
DATE_TAG="$(date +"%Y-%m-%d")"
RETENTION_DAYS=14 # Simpan backup lokal selama 14 hari terakhir

# 2. Validasi file .env
if [ ! -f "$ENV_FILE" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] File .env tidak ditemukan di $ENV_FILE!" >&2
    exit 1
fi

# 3. Ekstrak kredensial dari .env tanpa mengeksekusi shell sembarangan
get_env() {
    local key="$1"
    grep -E "^${key}=" "$ENV_FILE" | cut -d '=' -f2- | tr -d '\r"' | sed "s/^'//;s/'$//"
}

DB_HOST="$(get_env "DB_HOST")"
DB_PORT="$(get_env "DB_PORT")"
DB_NAME="$(get_env "DB_NAME")"
DB_USER="$(get_env "DB_USER")"
DB_PASS="$(get_env "DB_PASS")"

# Fallback nilai default jika tidak terdefinisi di .env
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] DB_NAME atau DB_USER kosong di file .env!" >&2
    exit 1
fi

# 4. Buat folder backup jika belum ada
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_backup_${TIMESTAMP}.sql.gz"

echo "=================================================================="
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] Memulai Database Backup: ${DB_NAME}"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] Host: ${DB_HOST}:${DB_PORT} | User: ${DB_USER}"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] Output: ${BACKUP_FILE}"

# 5. Eksekusi mysqldump dengan opsi aman untuk InnoDB production
# --single-transaction : konsistensi data tanpa me-lock tabel pendaftaran/reservasi
# --quick              : streaming row-by-row (menghemat memori VPS)
# --routines --triggers: menyertakan stored procedures & triggers jika ada
export MYSQL_PWD="$DB_PASS"

if mysqldump \
    -h "$DB_HOST" \
    -P "$DB_PORT" \
    -u "$DB_USER" \
    --default-character-set=utf8mb4 \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    "$DB_NAME" | gzip -9 > "$BACKUP_FILE"; then
    
    FILE_SIZE="$(du -h "$BACKUP_FILE" | cut -f1)"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [SUCCESS] Backup berhasil dibuat! Ukuran: ${FILE_SIZE}"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] Gagal melakukan mysqldump database ${DB_NAME}!" >&2
    rm -f "$BACKUP_FILE"
    exit 1
fi
unset MYSQL_PWD

# 6. Rotasi Backup Lokal (Hapus file backup lokal yang lebih tua dari $RETENTION_DAYS hari)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] Membersihkan backup lokal yang lebih tua dari ${RETENTION_DAYS} hari..."
find "$BACKUP_DIR" -type f -name "${DB_NAME}_backup_*.sql.gz" -mtime +"$RETENTION_DAYS" -exec rm -f {} \;

# 7. Sinkronisasi ke Penyimpanan Eksternal / Cloud (Opsional tapi SANGAT Direkomendasikan)
# Aktifkan salah satu metode di bawah ini untuk mengamankan data jika VPS mengalami crash total:

# --- Pilihan A: Rclone (Google Drive / Cloudflare R2 / AWS S3 / Wasabi) ---
# if command -v rclone &> /dev/null; then
#     echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] Mengupload backup ke remote storage via Rclone..."
#     rclone copy "$BACKUP_FILE" "my_remote_drive:intern_backups/${DATE_TAG}/" --retries 3
#     echo "[$(date '+%Y-%m-%d %H:%M:%S')] [SUCCESS] Sinkronisasi Rclone selesai."
# fi

# --- Pilihan B: AWS CLI S3 ---
# if command -v aws &> /dev/null; then
#     echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] Mengupload backup ke AWS S3 Bucket..."
#     aws s3 cp "$BACKUP_FILE" "s3://my-intern-backups-bucket/${DB_NAME}/${DB_NAME}_backup_${TIMESTAMP}.sql.gz"
#     echo "[$(date '+%Y-%m-%d %H:%M:%S')] [SUCCESS] Upload ke AWS S3 selesai."
# fi

# --- Pilihan C: SCP / Rsync ke Backup Server Terpisah ---
# BACKUP_SERVER="backupuser@192.168.1.100"
# BACKUP_SERVER_PATH="/mnt/storage/intern_backups/"
# scp -P 22 -i /root/.ssh/id_rsa "$BACKUP_FILE" "${BACKUP_SERVER}:${BACKUP_SERVER_PATH}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] [COMPLETED] Seluruh proses backup selesai dengan sukses."
echo "=================================================================="
