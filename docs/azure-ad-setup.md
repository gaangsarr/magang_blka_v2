# Panduan Setup Microsoft Azure Entra ID (SSO ITPLN) untuk Sistem INTERN ITPLN

> **Dokumen ini ditujukan untuk Tim IT Kampus ITPLN dan Developer.**  
> Sistem INTERN ITPLN menggunakan autentikasi langsung Microsoft Entra ID (OAuth 2.0 Authorization Code Flow) tanpa perantara Firebase.

---

## 1. Daftarkan Aplikasi di Azure Portal (Microsoft Entra ID)

1. Buka [Azure Portal](https://portal.azure.com/) atau [Microsoft Entra admin center](https://entra.microsoft.com) dan login dengan akun ITPLN.
2. Cari dan pilih layanan **Microsoft Entra ID**.
3. Di bilah menu kiri, pilih **App registrations** $\rightarrow$ klik **+ New registration**.
4. Lengkapi formulir pendaftaran:
   - **Name**: `INTERN ITPLN Magang`
   - **Supported account types**: Pilih **"Accounts in this organizational directory only (Institut Teknologi PLN only - Single tenant)"**.
   - **Redirect URI**:
     - Platform: Pilih **Web** *(bukan Single Page Application/SPA)*.
     - URL lokal: `http://localhost:8000/api/auth/azure/callback.php`
     - URL produksi: `https://domain-anda.itpln.ac.id/api/auth/azure/callback.php`
5. Klik tombol **Register**.

---

## 2. Catat Application (Client) ID dan Tenant ID

Pada halaman **Overview** aplikasi:
- Salin nilai **Application (client) ID** $\rightarrow$ masukkan ke `.env` sebagai `AZURE_CLIENT_ID`.
- Salin nilai **Directory (tenant) ID** $\rightarrow$ masukkan ke `.env` sebagai `AZURE_TENANT_ID`.

---

## 3. Buat Client Secret

1. Di bilah menu kiri aplikasi, pilih **Certificates & secrets** $\rightarrow$ tab **Client secrets**.
2. Klik **+ New client secret**.
3. Isi deskripsi (mis. `INTERN ITPLN App Secret`) dan pilih masa berlaku (disarankan **24 months**).
4. Klik **Add**.
5. **Salin nilai pada kolom Value** (BUKAN Secret ID).
   - Masukkan ke `.env` sebagai `AZURE_CLIENT_SECRET`.
   > *Catatan: Nilai Value hanya tampil sekali ini saja.*

---

## 4. Konfigurasi API Permissions

1. Di bilah menu kiri, pilih **API permissions**.
2. Pastikan permission berikut terdaftar (tipe *Delegated*):
   - `User.Read`
   - `openid`
   - `profile`
   - `email`
3. Jika ada yang belum terdaftar, klik **+ Add a permission** $\rightarrow$ **Microsoft Graph** $\rightarrow$ **Delegated permissions** $\rightarrow$ centang izin di atas $\rightarrow$ klik **Add permissions**.
4. *(Opsional - jika memiliki hak admin global)*: Klik **Grant admin consent for ITPLN** agar mahasiswa tidak melihat dialog consent satu per satu saat login pertama kali. Jika tombol ini nonaktif, tidak masalah karena izin di atas bersifat *user consent*.

---

## 5. Konfigurasi File `.env`

Masukkan variabel ke file `.env` di root proyek:

```dotenv
# --- Microsoft Azure Entra ID (SSO ITPLN) ---
AZURE_TENANT_ID=7b388d18-1900-418c-a5d3-e28d7a9a38e6
AZURE_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
AZURE_CLIENT_SECRET=your_client_secret_value_here
AZURE_REDIRECT_URI=http://localhost:8000/api/auth/azure/callback.php
```

---

## 6. Mode Pengujian Lokal (Dev Mock)

Jika Anda sedang mengembangkan aplikasi di lingkungan lokal (`APP_ENV=local`) dan kredensial Azure belum diisi, sistem secara otomatis mengalihkan tombol Masuk ke halaman **Dev Mock Login** (`/api/auth/azure/mock.php`) sehingga Anda dapat langsung login sebagai mahasiswa uji coba atau admin tanpa perlu login asli Microsoft.
