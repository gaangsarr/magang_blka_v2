import { showAdminAlert } from './common.js';

let csrfToken = null;

document.addEventListener('DOMContentLoaded', async () => {
    // Auth check
    const statusRes = await fetch('/api/admin/status.php');
    const statusData = await statusRes.json();
    if (statusData.csrf_token) csrfToken = statusData.csrf_token;
    if (!statusRes.ok || !statusData.authenticated) {
        window.location.href = '/admin/login.html';
        return;
    }

    // Load current settings
    await loadSettings();

    // Form submit
    document.getElementById('form-pengaturan').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-save');
        const statusEl = document.getElementById('save-status');
        btn.disabled = true;
        btn.innerText = 'Menyimpan...';
        statusEl.style.display = 'none';

        const payload = {
            min_ipk_5bulan: document.getElementById('min_ipk').value,
            min_sks_5bulan: document.getElementById('min_sks').value,
        };

        try {
            const res = await fetch('/api/admin/pengaturan/update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload),
            });
            const data = await res.json();

            if (res.ok && data.ok) {
                statusEl.innerText = 'Tersimpan';
                statusEl.style.color = '#10b981';
                statusEl.style.display = 'inline';
                await showAdminAlert('Pengaturan syarat program 5 bulan berhasil diperbarui!', 'success');
                setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
            } else {
                await showAdminAlert(data.error || 'Gagal menyimpan pengaturan.', 'error');
            }
        } catch (err) {
            await showAdminAlert('Kesalahan jaringan saat menyimpan.', 'error');
        }
        btn.disabled = false;
        btn.innerText = 'Simpan Pengaturan';
    });

    // Form submit for email settings
    const formEmail = document.getElementById('form-notifikasi-email');
    if (formEmail) {
        formEmail.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btn-save-email');
            const statusEl = document.getElementById('save-status-email');
            btn.disabled = true;
            btn.innerText = 'Menyimpan...';
            statusEl.style.display = 'none';

            const payload = {
                email_notifikasi_penolakan: document.getElementById('email_notifikasi_penolakan').checked ? '1' : '0',
                email_notifikasi_pengumuman: document.getElementById('email_notifikasi_pengumuman').checked ? '1' : '0',
                email_notifikasi_submit: document.getElementById('email_notifikasi_submit').checked ? '1' : '0',
            };

            try {
                const res = await fetch('/api/admin/pengaturan/update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();

                if (res.ok && data.ok) {
                    statusEl.innerText = 'Tersimpan';
                    statusEl.style.color = '#10b981';
                    statusEl.style.display = 'inline';
                    await showAdminAlert('Pengaturan notifikasi email berhasil diperbarui!', 'success');
                    setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
                } else {
                    await showAdminAlert(data.error || 'Gagal menyimpan pengaturan email.', 'error');
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat menyimpan.', 'error');
            }
            btn.disabled = false;
            btn.innerText = 'Simpan Pengaturan Email';
        });
    }
});

async function loadSettings() {
    try {
        const res = await fetch('/api/admin/pengaturan/list.php');
        const data = await res.json();

        if (data.ok && data.data) {
            if (data.data.min_ipk_5bulan) {
                document.getElementById('min_ipk').value = data.data.min_ipk_5bulan.nilai;
            }
            if (data.data.min_sks_5bulan) {
                document.getElementById('min_sks').value = data.data.min_sks_5bulan.nilai;
            }
            if (data.data.email_notifikasi_penolakan) {
                document.getElementById('email_notifikasi_penolakan').checked = data.data.email_notifikasi_penolakan.nilai === '1';
            }
            if (data.data.email_notifikasi_pengumuman) {
                document.getElementById('email_notifikasi_pengumuman').checked = data.data.email_notifikasi_pengumuman.nilai === '1';
            }
            if (data.data.email_notifikasi_submit) {
                document.getElementById('email_notifikasi_submit').checked = data.data.email_notifikasi_submit.nilai === '1';
            }
        }
    } catch (err) {
        console.error('Gagal memuat pengaturan:', err);
    }
}
