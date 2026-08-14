# Panduan Setup Firebase + Azure AD untuk Sistem Magang ITPLN

> **Dokumen ini ditujukan untuk Tim IT Kampus ITPLN dan Developer.**  
> Ikuti langkah-langkah di bawah secara berurutan sebelum sistem digunakan.

---

## Bagian 1 — Buat Firebase Project

### 1.1 Buat Project Baru

1. Buka [Firebase Console](https://console.firebase.google.com/) dan masuk dengan akun Google.
2. Klik **"Add project"**.
3. Isi nama project: misalnya `magang-itpln-pln`.
4. Matikan Google Analytics jika tidak diperlukan → klik **"Create project"**.

### 1.2 Aktifkan Authentication

1. Di sidebar kiri, pilih **Build → Authentication**.
2. Klik **"Get started"**.
3. Buka tab **"Sign-in method"**.
4. Klik **"Add new provider"** → pilih **"Microsoft"**.
5. Toggle **Enable** aktif.
6. Salin nilai **OAuth redirect URI** yang ditampilkan Firebase (format: `https://<project-id>.firebaseapp.com/__/auth/handler`) — dibutuhkan di langkah Azure AD.

### 1.3 Ambil Firebase Config

1. Di sidebar kiri, klik ikon **Settings (⚙️)** → **"Project settings"**.
2. Scroll ke bawah ke bagian **"Your apps"**.
3. Klik **"Add app"** → pilih ikon **`</>`** (Web).
4. Isi nama app (mis. `magang-web`) → klik **"Register app"**.
5. Salin objek `firebaseConfig` yang ditampilkan:

```js
const firebaseConfig = {
  apiKey:            "AIza...",
  authDomain:        "magang-itpln-pln.firebaseapp.com",
  projectId:         "magang-itpln-pln",
  storageBucket:     "magang-itpln-pln.appspot.com",
  messagingSenderId: "123456789",
  appId:             "1:123456789:web:abc..."
};
```

6. Salin nilai-nilai ini ke file `.env` di server:

```dotenv
FIREBASE_PROJECT_ID=magang-itpln-pln
```

---

## Bagian 2 — Setup Azure AD (Microsoft Entra ID) ITPLN

> Langkah ini dilakukan oleh **Admin IT kampus ITPLN** yang memiliki akses ke Azure Portal.

### 2.1 Daftarkan Aplikasi di Azure Portal

1. Buka [Azure Portal](https://portal.azure.com/) dan login dengan akun admin ITPLN.
2. Cari dan buka **"Microsoft Entra ID"** (dulu Azure Active Directory).
3. Di sidebar kiri, pilih **"App registrations"**.
4. Klik **"+ New registration"**.
5. Isi form:
   - **Name**: `Magang ITPLN × PLN`
   - **Supported account types**: pilih **"Accounts in this organizational directory only (Single tenant)"**
   - **Redirect URI**: pilih tipe **"Web"**, isi URI dari Firebase (langkah 1.2 di atas):
     ```
     https://<project-id>.firebaseapp.com/__/auth/handler
     ```
6. Klik **"Register"**.

### 2.2 Catat Application (Client) ID dan Tenant ID

Setelah registrasi berhasil, halaman **Overview** akan menampilkan:
- **Application (client) ID** → ini adalah `CLIENT_ID`
- **Directory (tenant) ID** → ini adalah `TENANT_ID`

Catat keduanya.

### 2.3 Buat Client Secret

1. Di sidebar kiri, pilih **"Certificates & secrets"**.
2. Tab **"Client secrets"** → klik **"+ New client secret"**.
3. Isi deskripsi (mis. `Magang App Secret`) dan pilih expiry (disarankan **24 months**).
4. Klik **"Add"**.
5. **Salin nilai secret segera** — tidak bisa dilihat lagi setelah halaman di-refresh.

### 2.4 Konfigurasi API Permissions

1. Di sidebar kiri, pilih **"API permissions"**.
2. Klik **"+ Add a permission"** → pilih **"Microsoft Graph"** → **"Delegated permissions"**.
3. Cari dan centang:
   - `openid`
   - `profile`
   - `email`
   - `User.Read`
4. Klik **"Add permissions"**.
5. Klik **"Grant admin consent for ITPLN"** → konfirmasi.

> **Langkah ini penting**: tanpa admin consent, mahasiswa akan diminta menyetujui permissions sendiri satu per satu, yang mengganggu UX.

### 2.5 Konfigurasi Token Claims (Pastikan `name` Tersedia)

1. Di sidebar kiri, pilih **"Token configuration"**.
2. Klik **"+ Add optional claim"** → pilih token type **"ID"**.
3. Centang **`email`** dan **`family_name`**, **`given_name`**, **`name`**.
4. Klik **"Add"** → jika diminta aktifkan profile claim, konfirmasi.

> Ini memastikan Firebase dapat menerima `displayName` dari akun Microsoft ITPLN.

---

## Bagian 3 — Hubungkan Azure AD ke Firebase

1. Kembali ke **Firebase Console → Authentication → Sign-in method → Microsoft**.
2. Isi field:
   - **Client ID**: `<Application (client) ID dari langkah 2.2>`
   - **Client secret**: `<nilai secret dari langkah 2.3>`
3. Klik **"Save"**.

---

## Bagian 4 — Konfigurasi `.env` di Server

Buka file `.env` di root project dan isi:

```dotenv
# --- Firebase ---
FIREBASE_PROJECT_ID=magang-itpln-pln

# --- Session ---
SESSION_KEY=<random string 64 karakter, generate dengan: openssl rand -hex 32>
```

> **Cara generate SESSION_KEY** (di Linux/Mac):
> ```bash
> openssl rand -hex 32
> ```
> Di Windows (PowerShell):
> ```powershell
> [System.Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Minimum 0 -Maximum 256 }))
> ```

---

## Bagian 5 — Inject Firebase Config ke Frontend

Buka file `public/login.html` dan inject config sebelum tag `<script type="module" src="js/auth.js">`:

```html
<script>
  window.FIREBASE_CONFIG = {
    apiKey:            "AIza...",
    authDomain:        "magang-itpln-pln.firebaseapp.com",
    projectId:         "magang-itpln-pln",
    storageBucket:     "magang-itpln-pln.appspot.com",
    messagingSenderId: "123456789",
    appId:             "1:123456789:web:abc..."
  };
</script>
<script type="module" src="js/auth.js"></script>
```

> **Alternatif lebih aman**: buat endpoint PHP `api/config/firebase.php` yang mengembalikan config ini dari `.env`, sehingga tidak perlu hardcode di HTML.

---

## Bagian 6 — Authorized Domains di Firebase

1. Di Firebase Console → Authentication → **"Settings"** tab.
2. Di bagian **"Authorized domains"**, tambahkan domain production app kamu:
   - `localhost` (sudah ada default)
   - `magang.itpln.ac.id` (domain production)

---

## Checklist Final

- [ ] Firebase project dibuat
- [ ] Microsoft provider diaktifkan di Firebase Auth
- [ ] App didaftarkan di Azure AD (Microsoft Entra ID)
- [ ] Admin consent sudah diberikan untuk Graph API permissions
- [ ] Optional claims (email, name) sudah ditambahkan ke ID token
- [ ] Client ID & Secret diisi di Firebase Microsoft provider settings
- [ ] `FIREBASE_PROJECT_ID` diisi di `.env`
- [ ] `SESSION_KEY` diisi di `.env`
- [ ] `window.FIREBASE_CONFIG` diinjeksi di `login.html`
- [ ] Domain production ditambahkan ke Authorized Domains Firebase

---

## Troubleshooting

| Masalah | Penyebab | Solusi |
|---|---|---|
| `auth/popup-closed-by-user` | User tutup popup sebelum selesai | Normal, tidak perlu ditangani |
| `auth/popup-blocked` | Browser block popup | Minta user izinkan popup untuk domain ini |
| `auth/unauthorized-domain` | Domain belum ditambahkan ke Firebase | Firebase Console → Authentication → Settings → Authorized domains → Add domain (`127.0.0.1` untuk dev, `magang.itpln.ac.id` untuk production) |
| `auth/invalid-credential` + AADSTS50194 | App Azure AD adalah single-tenant tapi Firebase pakai `/common` endpoint | Isi `azureTenantId` di `window.FIREBASE_CONFIG` dengan **Directory (tenant) ID** dari Azure Portal → App registration → Overview |
| Error 403 dari `/api/auth/verify.php` | Email bukan @itpln.ac.id | Pastikan user pakai akun kampus |
| `displayName` kosong → `needs_nama: true` | Azure AD tenant tidak kirim `name` claim | Ikuti langkah 2.5 (Token configuration) |
| Token verification failed (500) | `FIREBASE_PROJECT_ID` salah/kosong | Cek `.env` |
| `AADSTS50011` redirect URI mismatch | URI di Azure tidak cocok dengan Firebase | Salin ulang URI dari Firebase ke Azure |


