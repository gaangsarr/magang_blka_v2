import { showAdminAlert, showAdminConfirm, showAdminToast } from '/js/admin/common.js';

let csrfToken = null;
let currentSummary = null;
let currentMhsList = [];
let currentPeriodeId = 0;
let mhsPage = 1;
let mhsPerPage = 25;

document.addEventListener('DOMContentLoaded', async () => {
    // 1. Cek Status Auth
    try {
        const statusRes = await fetch('/api/admin/status.php');
        const statusData = await statusRes.json();
        
        if (!statusRes.ok || !statusData.authenticated) {
            window.location.href = '/admin/login.html';
            return;
        }

        if (statusData.csrf_token) {
            csrfToken = statusData.csrf_token;
        }

        // Cek jika akun bukan admin_perusahaan dan bukan super_admin
        const role = statusData.admin?.role;
        if (role !== 'admin_perusahaan' && role !== 'super_admin') {
            await showAdminAlert('Akses khusus untuk Admin Mitra Perusahaan.', 'warning');
            window.location.href = '/admin/index.html';
            return;
        }

    } catch (e) {
        console.error('Auth verification error:', e);
        window.location.href = '/admin/login.html';
        return;
    }

    // 2. Inisialisasi UI & Handlers
    initNavbar();
    initTabs();
    initForceResetModal();
    initOverviewModule();
    initKuotaModule();
    initPendaftarModule();
    initProfilModule();

    // 3. Load Data Awal
    await loadDashboardSummary();
});

// ==========================================
// NAVBAR & LOGOUT
// ==========================================
function initNavbar() {
    const btnLogout = document.getElementById('btn-logout');
    if (btnLogout) {
        btnLogout.addEventListener('click', async () => {
            const isConfirmed = await showAdminConfirm(
                'Apakah Anda yakin ingin keluar dari portal mitra perusahaan?',
                'Konfirmasi Keluar',
                'warning',
                'Ya, Keluar',
                'Batal'
            );
            if (!isConfirmed) return;

            try {
                await fetch('/api/admin/logout.php', { credentials: 'same-origin' });
            } catch (_) {}
            window.location.href = '/admin/login.html';
        });
    }
}

// ==========================================
// TAB NAVIGATION
// ==========================================
function initTabs() {
    const tabBtns = document.querySelectorAll('.perusahaan-nav-btn');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetTab = btn.getAttribute('data-tab');
            switchTab(targetTab);
        });
    });

    document.querySelectorAll('.btn-nav-to-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetTab = btn.getAttribute('data-target');
            switchTab(targetTab);
        });
    });
}

function switchTab(tabId) {
    document.querySelectorAll('.perusahaan-nav-btn').forEach(b => {
        if (b.getAttribute('data-tab') === tabId) {
            b.classList.add('active');
        } else {
            b.classList.remove('active');
        }
    });

    document.querySelectorAll('.p-tab-pane').forEach(p => {
        if (p.id === tabId) {
            p.classList.add('active');
        } else {
            p.classList.remove('active');
        }
    });

    // Trigger tab-specific refresh if needed
    if (tabId === 'tab-kuota') {
        loadKuotaData(currentPeriodeId);
    } else if (tabId === 'tab-pendaftar') {
        loadPendaftarData();
    } else if (tabId === 'tab-profil') {
        loadProfilData();
    }
}

// ==========================================
// FORCE PASSWORD CHANGE (FIRST LOGIN)
// ==========================================
function initForceResetModal() {
    const form = document.getElementById('form-force-reset');
    const errBox = document.getElementById('force-error-box');
    const btnSubmit = document.getElementById('btn-submit-force');

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            errBox.classList.add('hidden');

            const newPass = document.getElementById('force-new-password').value;
            const confirmPass = document.getElementById('force-confirm-password').value;
            const picNama = document.getElementById('force-pic-nama').value.trim();
            const picJabatan = document.getElementById('force-pic-jabatan').value.trim();
            const picKontak = document.getElementById('force-pic-kontak').value.trim();
            const picEmail = document.getElementById('force-pic-email').value.trim();

            if (newPass.length < 8) {
                errBox.innerText = 'Kata sandi baru minimal harus 8 karakter.';
                errBox.classList.remove('hidden');
                return;
            }

            if (newPass !== confirmPass) {
                errBox.innerText = 'Konfirmasi kata sandi tidak cocok.';
                errBox.classList.remove('hidden');
                return;
            }

            btnSubmit.disabled = true;
            btnSubmit.innerText = 'Menyimpan...';

            try {
                const res = await fetch('/api/perusahaan/auth/first_login_reset.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        new_password: newPass,
                        confirm_password: confirmPass,
                        pic_nama: picNama,
                        pic_jabatan: picJabatan,
                        pic_kontak: picKontak,
                        pic_email: picEmail
                    })
                });
                const data = await res.json();

                if (data.ok) {
                    await showAdminAlert(data.message || 'Akun dan data PIC berhasil diaktivasi!', 'success', 'Aktivasi Berhasil');
                    document.getElementById('modal-force-reset').classList.add('hidden');
                    loadDashboardSummary();
                } else {
                    errBox.innerText = data.error || 'Gagal menyimpan perubahan.';
                    errBox.classList.remove('hidden');
                }
            } catch (err) {
                errBox.innerText = 'Kesalahan jaringan saat menyimpan.';
                errBox.classList.remove('hidden');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.innerText = 'Simpan & Lanjutkan ke Dashboard →';
            }
        });
    }
}

// ==========================================
// TAB 1: OVERVIEW & DASHBOARD SUMMARY
// ==========================================
function initOverviewModule() {}

async function loadDashboardSummary() {
    try {
        const res = await fetch('/api/perusahaan/dashboard/summary.php');
        if (res.status === 401) {
            window.location.href = '/admin/login.html';
            return;
        }
        const data = await res.json();

        if (data.ok) {
            currentSummary = data;

            // Check force password change
            if (data.user?.force_password_change) {
                const modal = document.getElementById('modal-force-reset');
                document.getElementById('force-unit-name').innerText = data.entitas?.nama || '-';
                if (modal) modal.classList.remove('hidden');
            }

            // Update Navbar Branding
            const unitNama = data.entitas?.nama || 'Unit Mitra';
            const unitSingkatan = data.entitas?.singkatan ? ` (${data.entitas.singkatan})` : '';
            document.getElementById('header-unit-nama').innerText = unitNama + unitSingkatan;
            document.getElementById('nav-unit-name').innerText = data.entitas?.singkatan || unitNama;

            // Render Notification Banner
            renderNotificationBanner(data.notification);

            // Render Stats
            const upp = data.periode?.upp;
            const kuotaTotal = upp ? upp.kuota_total : 0;
            const kuotaSisa = upp ? upp.kuota_tersisa : 0;
            const totalMhs = data.stats?.total || 0;
            const prodiCount = data.periode?.jurusan_count || 0;

            document.getElementById('stat-kuota-total').innerText = kuotaTotal;
            document.getElementById('stat-pendaftar-masuk').innerText = totalMhs;
            document.getElementById('stat-kuota-sisa').innerText = kuotaSisa;
            document.getElementById('stat-prodi-count').innerText = prodiCount;

            // Render Periode Quick Info
            const periodeEl = document.getElementById('overview-periode-info');
            if (data.periode) {
                currentPeriodeId = data.periode.id;
                let statusBadge = `<span class="badge-status badge-${data.periode.status}">${data.periode.status.toUpperCase()}</span>`;
                periodeEl.innerHTML = `
                    <div style="font-size: 1.1rem; font-weight: 700; color: #004687; margin-bottom: 6px;">
                        ${escapeHtml(data.periode.nama)}
                    </div>
                    <div style="margin-bottom: 10px;">Status Periode: ${statusBadge}</div>
                    <div style="font-size: 0.85rem; color: #64748b; line-height: 1.6;">
                        Status Menerima Magang: <strong>${upp && upp.aktif ? '<span style="color:#166534;">Menerima Magang</span>' : '<span style="color:#991b1b;">Tidak Menerima</span>'}</strong><br>
                        Program Studi Terbuka: <strong>${prodiCount} Prodi</strong>
                    </div>
                `;
            } else {
                periodeEl.innerHTML = '<div style="color: #64748b;">Tidak ada periode magang aktif saat ini.</div>';
            }

            // Render PIC Quick Info
            const picEl = document.getElementById('overview-pic-info');
            const pic = data.entitas?.pic;
            if (pic && pic.nama) {
                picEl.innerHTML = `
                    <div style="font-weight: 700; color: #1e293b; font-size: 1rem; margin-bottom: 2px;">${escapeHtml(pic.nama)}</div>
                    <div style="font-size: 0.825rem; color: #004687; font-weight: 600; margin-bottom: 12px;">${escapeHtml(pic.jabatan || 'Penanggung Jawab Unit')}</div>
                    <div style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #475569; margin-bottom: 6px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><line x1="12" x2="12.01" y1="18" y2="18"/></svg>
                        <span>WhatsApp / HP: <strong>${escapeHtml(pic.kontak || '-')}</strong></span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #475569;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <span>Email: <strong>${escapeHtml(pic.email || '-')}</strong></span>
                    </div>
                `;
            } else {
                picEl.innerHTML = '<div style="color: #94a3b8; font-style: italic;">Data PIC resmi kantor belum dilengkapi.</div>';
            }
        }
    } catch (err) {
        console.error('Error loadDashboardSummary:', err);
    }
}

function renderNotificationBanner(notif) {
    const bannerContainer = document.getElementById('banner-container');
    if (!bannerContainer) return;
    bannerContainer.innerHTML = '';

    if (!notif) return;

    const bannerClass = notif.type === 'success' ? 'banner-success' : 'banner-info';
    const iconSvg = notif.type === 'success' 
        ? `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`
        : `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>`;

    bannerContainer.innerHTML = `
        <div class="notification-banner ${bannerClass}">
            <div class="banner-icon-box">${iconSvg}</div>
            <div class="banner-content">
                <h4 class="banner-title">${escapeHtml(notif.title)}</h4>
                <p class="banner-desc">${escapeHtml(notif.message)}</p>
            </div>
            ${notif.can_edit ? `
                <button class="btn-portal-primary btn-nav-to-tab" data-target="tab-kuota" style="white-space: nowrap; font-size: 0.8rem; padding: 7px 14px;">
                    <span>Atur Kuota Sekarang</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            ` : ''}
        </div>
    `;

    bannerContainer.querySelectorAll('.btn-nav-to-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            switchTab(btn.getAttribute('data-target'));
        });
    });
}

function updateToggleCardVisual(isMenerima) {
    const cardWrapper = document.getElementById('toggle-card-wrapper');
    const badge = document.getElementById('toggle-status-badge');
    const desc = document.getElementById('toggle-desc-text');

    if (isMenerima) {
        if (cardWrapper) {
            cardWrapper.style.background = '#f0fdf4';
            cardWrapper.style.borderColor = '#bbf7d0';
        }
        if (badge) {
            badge.className = 'badge-status badge-success';
            badge.innerText = '✓ Aktif Menerima';
        }
        if (desc) {
            desc.style.color = '#166534';
            desc.innerText = 'Unit kantor Anda aktif menerima pendaftaran dan dapat dipilih oleh mahasiswa pelamar.';
        }
    } else {
        if (cardWrapper) {
            cardWrapper.style.background = '#fef2f2';
            cardWrapper.style.borderColor = '#fecaca';
        }
        if (badge) {
            badge.className = 'badge-status badge-danger';
            badge.innerText = '✕ Tidak Menerima';
        }
        if (desc) {
            desc.style.color = '#991b1b';
            desc.innerText = 'Unit kantor dinonaktifkan dan tidak akan menerima mahasiswa magang pada periode ini.';
        }
    }
}

// ==========================================
// TAB 2: PENGATURAN KUOTA & PRODI
// ==========================================
function initKuotaModule() {
    const selectPeriode = document.getElementById('select-kuota-periode');
    if (selectPeriode) {
        selectPeriode.addEventListener('change', () => {
            const pid = parseInt(selectPeriode.value);
            if (pid > 0) loadKuotaData(pid);
        });
    }

    const toggleMenerima = document.getElementById('toggle-menerima-magang');
    if (toggleMenerima) {
        toggleMenerima.addEventListener('change', async () => {
            const detailContainer = document.getElementById('kuota-detail-container');
            const inputKuota = document.getElementById('input-kuota-total');
            const saveWrapper = document.getElementById('btn-save-kuota-wrapper');
            const pid = parseInt(document.getElementById('select-kuota-periode')?.value || '0');

            updateToggleCardVisual(toggleMenerima.checked);

            if (!toggleMenerima.checked) {
                // Auto-save saat toggle dinonaktifkan (Tidak Menerima Magang)
                detailContainer.style.opacity = '0.4';
                detailContainer.style.pointerEvents = 'none';
                if (saveWrapper) saveWrapper.style.display = 'none';

                if (pid <= 0) return;

                try {
                    const res = await fetch('/api/perusahaan/kuota/save.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                        body: JSON.stringify({
                            periode_id: pid,
                            menerima_magang: false,
                            kuota_total: 0,
                            jurusan_ids: [],
                            peminatan_ids: []
                        })
                    });
                    const data = await res.json();

                    if (data.ok) {
                        showAdminToast('Status diubah: Unit Tidak Menerima Mahasiswa Magang pada periode ini.', 'info', 4000);
                        await loadDashboardSummary();
                        await loadKuotaData(pid);
                    } else {
                        await showAdminAlert(data.error || 'Gagal mengubah status penerimaan magang.', 'error');
                        toggleMenerima.checked = true;
                        updateToggleCardVisual(true);
                        detailContainer.style.opacity = '1';
                        detailContainer.style.pointerEvents = 'auto';
                        if (saveWrapper) saveWrapper.style.display = 'flex';
                    }
                } catch (err) {
                    console.error(err);
                    await showAdminAlert('Kesalahan jaringan saat mengubah status.', 'error');
                    toggleMenerima.checked = true;
                    updateToggleCardVisual(true);
                    detailContainer.style.opacity = '1';
                    detailContainer.style.pointerEvents = 'auto';
                    if (saveWrapper) saveWrapper.style.display = 'flex';
                }

            } else {
                // Ketika toggle diaktifkan kembali ke Menerima Magang
                detailContainer.style.opacity = '1';
                detailContainer.style.pointerEvents = 'auto';
                if (saveWrapper) saveWrapper.style.display = 'flex';
                if (inputKuota && (inputKuota.value === '0' || !inputKuota.value)) inputKuota.value = '5';
                showAdminToast('Penerimaan magang diaktifkan. Silakan atur kuota & pilih prodi, lalu klik Simpan Pengaturan Kuota.', 'info', 4000);
            }
        });
    }

    // Select/Deselect All Prodi
    document.getElementById('btn-select-all-prodi')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-prodi').forEach(c => {
            c.checked = true;
            c.closest('.custom-checkbox-card')?.classList.add('checked');
        });
    });
    document.getElementById('btn-deselect-all-prodi')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-prodi').forEach(c => {
            c.checked = false;
            c.closest('.custom-checkbox-card')?.classList.remove('checked');
        });
    });

    // Select/Deselect All Peminatan
    document.getElementById('btn-select-all-pem')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-peminatan').forEach(c => {
            c.checked = true;
            c.closest('.custom-checkbox-card')?.classList.add('checked');
        });
    });
    document.getElementById('btn-deselect-all-pem')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-peminatan').forEach(c => {
            c.checked = false;
            c.closest('.custom-checkbox-card')?.classList.remove('checked');
        });
    });

    // Form Submit
    const form = document.getElementById('form-kuota-setting');
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pid = parseInt(document.getElementById('select-kuota-periode')?.value || '0');
            const menerima = document.getElementById('toggle-menerima-magang')?.checked;
            const kuotaTotal = parseInt(document.getElementById('input-kuota-total')?.value || '0');

            const jurusanIds = [];
            document.querySelectorAll('.chk-prodi:checked').forEach(c => {
                jurusanIds.push(parseInt(c.value));
            });

            const peminatanIds = [];
            document.querySelectorAll('.chk-peminatan:checked').forEach(c => {
                peminatanIds.push(parseInt(c.value));
            });

            if (menerima && kuotaTotal <= 0) {
                await showAdminAlert('Total kuota mahasiswa minimal 1 jika unit menerima magang.', 'warning', 'Kuota Tidak Valid');
                return;
            }

            if (menerima && jurusanIds.length === 0) {
                await showAdminAlert('Silakan pilih minimal 1 Program Studi yang diterima.', 'warning', 'Pilih Program Studi');
                return;
            }

            const btnSave = document.getElementById('btn-save-kuota');
            btnSave.disabled = true;
            btnSave.innerHTML = `
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="spin-animation"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span>Menyimpan ke Database...</span>
            `;

            try {
                const res = await fetch('/api/perusahaan/kuota/save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        periode_id: pid,
                        menerima_magang: menerima,
                        kuota_total: kuotaTotal,
                        jurusan_ids: jurusanIds,
                        peminatan_ids: peminatanIds
                    })
                });
                const data = await res.json();

                if (data.ok) {
                    // 1. Tombol Sukses dengan Animasi Checkmark
                    btnSave.disabled = false;
                    btnSave.style.background = '#059669';
                    btnSave.style.borderColor = '#059669';
                    btnSave.innerHTML = `
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>✓ Pengaturan Kuota Tersimpan!</span>
                    `;

                    // 2. Tampilkan Toast Kustom
                    showAdminToast('Pengaturan kuota berhasil disimpan dan berlaku aktif!', 'success', 4000);

                    // 3. Reload Data Terkini
                    await loadDashboardSummary();
                    await loadKuotaData(pid);

                    // Kembalikan tombol ke tampilan standar setelah 3 detik
                    setTimeout(() => {
                        btnSave.style.background = '';
                        btnSave.style.borderColor = '';
                        btnSave.innerHTML = `
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <span>Simpan Pengaturan Kuota</span>
                        `;
                    }, 3000);

                } else {
                    await showAdminAlert(data.error || 'Gagal menyimpan pengaturan kuota.', 'error');
                    btnSave.disabled = false;
                    btnSave.innerHTML = `
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <span>Simpan Pengaturan Kuota</span>
                    `;
                }
            } catch (err) {
                console.error(err);
                await showAdminAlert('Kesalahan jaringan saat menyimpan pengaturan kuota.', 'error');
                btnSave.disabled = false;
                btnSave.innerHTML = `
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span>Simpan Pengaturan Kuota</span>
                `;
            }
        });
    }
}

async function loadKuotaData(periodeId = 0) {
    try {
        const url = `/api/perusahaan/kuota/get.php` + (periodeId > 0 ? `?periode_id=${periodeId}` : '');
        const res = await fetch(url);
        const data = await res.json();

        if (data.ok) {
            // Populate Dropdown
            const select = document.getElementById('select-kuota-periode');
            if (select && data.all_periode) {
                select.innerHTML = '';
                data.all_periode.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.text = `${p.nama} (${p.status.toUpperCase()})`;
                    if (data.selected_periode && parseInt(p.id) === parseInt(data.selected_periode.id)) {
                        opt.selected = true;
                    }
                    select.appendChild(opt);
                });
            }

            // Lock Alert
            const lockAlert = document.getElementById('kuota-lock-alert');
            const btnSave = document.getElementById('btn-save-kuota');
            const canEdit = data.selected_periode?.can_edit;

            if (canEdit) {
                lockAlert.classList.add('hidden');
                if (btnSave) btnSave.disabled = false;
            } else {
                lockAlert.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span><strong>Pengaturan Kuota Dikunci:</strong> Periode ini berstatus <strong>${data.selected_periode?.status.toUpperCase()}</strong>. Pengaturan kuota hanya dapat diubah saat masa Persiapan atau oleh Super Admin BLKA.</span>
                    </div>
                `;
                lockAlert.classList.remove('hidden');
                if (btnSave) btnSave.disabled = true;
            }

            // Populate Form Fields
            const k = data.kuota;
            const toggleMenerima = document.getElementById('toggle-menerima-magang');
            const inputKuota = document.getElementById('input-kuota-total');
            const detailContainer = document.getElementById('kuota-detail-container');

            // Render Live Kuota Status Summary Bar
            const barTitle = document.getElementById('kuota-bar-title');
            const barSub = document.getElementById('kuota-bar-subtitle');
            const pillTerima = document.getElementById('pill-terima-status');
            const pillKuota = document.getElementById('pill-kuota-count');
            const pillProdi = document.getElementById('pill-prodi-count');
            const pillPem = document.getElementById('pill-pem-count');

            const selectedProdiCount = (k?.jurusan_list || []).filter(j => j.is_selected).length;
            const selectedPemCount = (k?.peminatan_list || []).filter(p => p.is_selected).length;

            if (k && k.menerima_magang) {
                if (barTitle) barTitle.innerText = `Konfigurasi Kuota Aktif (${data.selected_periode?.nama || 'Periode'})`;
                if (barSub) barSub.innerText = `Unit siap menerima kapasitas ${k.kuota_total} mahasiswa untuk ${selectedProdiCount} Program Studi.`;
                if (pillTerima) {
                    pillTerima.className = 'badge-status badge-success';
                    pillTerima.innerText = '✓ Menerima Magang';
                }
                if (pillKuota) {
                    pillKuota.className = 'badge-status badge-info';
                    pillKuota.innerText = `${k.kuota_total} Mahasiswa`;
                }
                if (pillProdi) {
                    pillProdi.className = 'badge-status badge-neutral';
                    pillProdi.innerText = `${selectedProdiCount} Prodi Dibuka`;
                }
                if (pillPem) {
                    pillPem.className = 'badge-status badge-neutral';
                    pillPem.innerText = `${selectedPemCount} Peminatan`;
                }
            } else {
                if (barTitle) barTitle.innerText = `Konfigurasi Kuota (${data.selected_periode?.nama || 'Periode'})`;
                if (barSub) barSub.innerText = 'Unit kantor tidak membuka penerimaan magang pada periode ini.';
                if (pillTerima) {
                    pillTerima.className = 'badge-status badge-danger';
                    pillTerima.innerText = '✕ Tidak Menerima';
                }
                if (pillKuota) {
                    pillKuota.className = 'badge-status badge-neutral';
                    pillKuota.innerText = '0 Kuota';
                }
                if (pillProdi) {
                    pillProdi.className = 'badge-status badge-neutral';
                    pillProdi.innerText = '0 Prodi';
                }
                if (pillPem) {
                    pillPem.className = 'badge-status badge-neutral';
                    pillPem.innerText = '0 Peminatan';
                }
            }

            const saveWrapper = document.getElementById('btn-save-kuota-wrapper');
            if (toggleMenerima) {
                toggleMenerima.checked = k ? k.menerima_magang : true;
                updateToggleCardVisual(toggleMenerima.checked);
                if (toggleMenerima.checked) {
                    detailContainer.style.opacity = '1';
                    detailContainer.style.pointerEvents = 'auto';
                    if (saveWrapper) saveWrapper.style.display = 'flex';
                } else {
                    detailContainer.style.opacity = '0.4';
                    detailContainer.style.pointerEvents = 'none';
                    if (saveWrapper) saveWrapper.style.display = 'none';
                }
            }
            if (inputKuota) {
                inputKuota.value = k ? k.kuota_total : 0;
            }

            // Render Prodi Checkboxes (Hanya Nama Prodi)
            const prodiGrid = document.getElementById('prodi-checkboxes');
            if (prodiGrid && k?.jurusan_list) {
                prodiGrid.innerHTML = '';
                k.jurusan_list.forEach(j => {
                    const checkedClass = j.is_selected ? 'checked' : '';
                    const card = document.createElement('label');
                    card.className = `custom-checkbox-card ${checkedClass}`;
                    card.innerHTML = `
                        <input type="checkbox" class="chk-prodi" value="${j.id}" ${j.is_selected ? 'checked' : ''}>
                        <span style="font-size: 0.875rem; font-weight: 600; color: #1e293b;">${escapeHtml(j.nama_jurusan)}</span>
                    `;
                    card.querySelector('input').addEventListener('change', (e) => {
                        if (e.target.checked) card.classList.add('checked');
                        else card.classList.remove('checked');
                    });
                    prodiGrid.appendChild(card);
                });
            }

            // Render Peminatan Checkboxes (Hanya Judul Peminatan)
            const pemGrid = document.getElementById('peminatan-checkboxes');
            if (pemGrid && k?.peminatan_list) {
                pemGrid.innerHTML = '';
                k.peminatan_list.forEach(pem => {
                    const checkedClass = pem.is_selected ? 'checked' : '';
                    const card = document.createElement('label');
                    card.className = `custom-checkbox-card ${checkedClass}`;
                    card.innerHTML = `
                        <input type="checkbox" class="chk-peminatan" value="${pem.id}" ${pem.is_selected ? 'checked' : ''}>
                        <span style="font-size: 0.875rem; font-weight: 600; color: #1e293b;">${escapeHtml(pem.nama_peminatan)}</span>
                    `;
                    card.querySelector('input').addEventListener('change', (e) => {
                        if (e.target.checked) card.classList.add('checked');
                        else card.classList.remove('checked');
                    });
                    pemGrid.appendChild(card);
                });
            }
        }
    } catch (err) {
        console.error('Error loadKuotaData:', err);
    }
}

// ==========================================
// TAB 3: LIVE MONITORING PENDAFTAR
// ==========================================
function initPendaftarModule() {
    const searchInput = document.getElementById('filter-search-mhs');
    const statusFilter = document.getElementById('filter-mhs-status');
    const progFilter = document.getElementById('filter-mhs-program');

    if (searchInput) searchInput.addEventListener('input', () => { mhsPage = 1; loadPendaftarData(); });
    if (statusFilter) statusFilter.addEventListener('change', () => { mhsPage = 1; loadPendaftarData(); });
    if (progFilter) progFilter.addEventListener('change', () => { mhsPage = 1; loadPendaftarData(); });

    // Export Excel
    const btnExport = document.getElementById('btn-export-pendaftar');
    if (btnExport) {
        btnExport.addEventListener('click', () => {
            const url = `/api/perusahaan/pendaftar/export.php` + (currentPeriodeId > 0 ? `?periode_id=${currentPeriodeId}` : '');
            window.location.href = url;
        });
    }

    // Modal Detail
    const modalDetail = document.getElementById('modal-detail-mhs');
    const btnCloseDetail = document.getElementById('btn-close-detail-mhs');
    const btnOkDetail = document.getElementById('btn-ok-detail-mhs');
    const closeDetail = () => modalDetail.classList.add('hidden');
    if (btnCloseDetail) btnCloseDetail.addEventListener('click', closeDetail);
    if (btnOkDetail) btnOkDetail.addEventListener('click', closeDetail);
}

async function loadPendaftarData() {
    const search = document.getElementById('filter-search-mhs')?.value.trim() || '';
    const status = document.getElementById('filter-mhs-status')?.value || '';
    const program = document.getElementById('filter-mhs-program')?.value || '';

    let url = `/api/perusahaan/pendaftar/list.php?page=${mhsPage}&per_page=${mhsPerPage}`;
    if (currentPeriodeId > 0) url += `&periode_id=${currentPeriodeId}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (status) url += `&filter_status=${encodeURIComponent(status)}`;
    if (program) url += `&filter_program=${encodeURIComponent(program)}`;

    try {
        const res = await fetch(url);
        const data = await res.json();

        if (data.ok) {
            currentMhsList = data.data || [];
            renderPendaftarTable(currentMhsList);
            renderPendaftarPagination(data.pagination);
        }
    } catch (err) {
        console.error('Error loadPendaftarData:', err);
    }
}

function renderPendaftarTable(list) {
    const tbody = document.getElementById('table-live-pendaftar');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #64748b; padding: 32px;">Belum ada pendaftar mahasiswa pada kriteria pencarian ini.</td></tr>';
        return;
    }

    list.forEach((mhs, idx) => {
        let statusBadge = `<span class="badge-status badge-${mhs.status}">${mhs.status.toUpperCase()}</span>`;
        if (mhs.is_dipindahkan) {
            statusBadge += `<div style="font-size: 0.7rem; color: #4338ca; font-weight: 700; margin-top: 3px; display: inline-flex; align-items: center; gap: 3px;">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/></svg>
                <span>Dipindahkan</span>
            </div>`;
        }
        const peminatanText = mhs.peminatan && mhs.peminatan.length > 0 ? mhs.peminatan.join(', ') : '-';
        const rowNum = ((mhsPage - 1) * mhsPerPage) + (idx + 1);

        const tr = document.createElement('tr');
        if (mhs.is_dipindahkan) {
            tr.style.backgroundColor = 'rgba(99, 102, 241, 0.03)';
        }
        tr.innerHTML = `
            <td style="text-align: center; color: #64748b;">${rowNum}</td>
            <td>
                <div style="font-weight: 700; color: #004687;">${escapeHtml(mhs.nama)}</div>
                <div style="font-family: monospace; font-size: 0.8rem; color: #64748b;">NIM: ${escapeHtml(mhs.nim)} (${escapeHtml(mhs.jenis_kelamin || '-')})</div>
            </td>
            <td>
                <div style="font-weight: 600; color: #1e293b;">${escapeHtml(mhs.jurusan_nama)}</div>
                <div style="font-size: 0.775rem; color: #64748b;">Angkatan: ${escapeHtml(mhs.angkatan)}</div>
            </td>
            <td style="text-align: center;">
                <span style="font-size: 0.775rem; font-weight: 600; background: #f1f5f9; padding: 4px 8px; border-radius: 6px;">${escapeHtml(mhs.program)}</span>
            </td>
            <td style="text-align: center; font-size: 0.85rem;">
                <strong>${escapeHtml(mhs.ipk)}</strong> / ${escapeHtml(mhs.jumlah_sks)} SKS
            </td>
            <td style="font-size: 0.825rem; color: #334155; max-width: 180px;">
                ${escapeHtml(peminatanText)}
            </td>
            <td>
                <div style="font-size: 0.825rem; color: #1e293b; display: flex; align-items: center; gap: 4px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><line x1="12" x2="12.01" y1="18" y2="18"/></svg>
                    <span>${escapeHtml(mhs.no_hp)}</span>
                </div>
                <div style="font-size: 0.75rem; color: #64748b; display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <span>${escapeHtml(mhs.email)}</span>
                </div>
            </td>
            <td style="text-align: center;">${statusBadge}</td>
            <td style="text-align: center;">
                <button class="btn-portal-outline btn-view-mhs" data-id="${mhs.id}" style="padding: 5px 10px; font-size: 0.775rem;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span>Detail</span>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });

    document.querySelectorAll('.btn-view-mhs').forEach(btn => {
        btn.addEventListener('click', () => {
            const mhsId = parseInt(btn.getAttribute('data-id'));
            const mhs = currentMhsList.find(item => item.id === mhsId);
            if (mhs) showMhsDetailModal(mhs);
        });
    });
}

function showMhsDetailModal(mhs) {
    const modal = document.getElementById('modal-detail-mhs');
    const body = document.getElementById('mhs-detail-body');

    let transferAlertHtml = '';
    if (mhs.is_dipindahkan) {
        transferAlertHtml = `
            <div style="background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; text-align: left;">
                <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; color: #3730a3; font-size: 0.875rem; margin-bottom: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
                    <span>Mahasiswa Hasil Penetapan / Pengalihan Unit</span>
                </div>
                <div style="font-size: 0.825rem; color: #4338ca; line-height: 1.5;">
                    Mahasiswa ini dialihkan oleh Administrator BLKA dari pilihan kantor awal:
                    <div style="margin-top: 6px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="background: #ffffff; border: 1px solid #cbd5e1; padding: 3px 10px; border-radius: 6px; font-weight: 600; color: #64748b; text-decoration: line-through; font-size: 0.8rem;">
                            ${escapeHtml(mhs.unit_asal_nama || 'Kantor Pilihan Awal')}
                        </span>
                        <span style="font-weight: 800; color: #4338ca;">➔</span>
                        <span style="background: #e0e7ff; border: 1px solid #a5b4fc; padding: 3px 10px; border-radius: 6px; font-weight: 700; color: #1e1b4b; font-size: 0.8rem;">
                            ${escapeHtml(mhs.unit_tujuan_nama || 'Unit Kantor Anda')}
                        </span>
                    </div>
                    ${mhs.catatan_admin ? `<div style="margin-top: 8px; font-size: 0.8rem; color: #4f46e5;"><strong>Catatan Administrator:</strong> ${escapeHtml(mhs.catatan_admin)}</div>` : ''}
                </div>
            </div>
        `;
    }

    body.innerHTML = `
        <div style="text-align: center; margin-bottom: 20px;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #004687; color: #fff; font-size: 1.4rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 8px;">
                ${escapeHtml(mhs.nama.charAt(0))}
            </div>
            <h3 style="margin: 0; font-size: 1.15rem; color: #004687; font-weight: 800;">${escapeHtml(mhs.nama)}</h3>
            <span style="font-family: monospace; color: #64748b; font-size: 0.9rem;">NIM: ${escapeHtml(mhs.nim)}</span>
        </div>

        ${transferAlertHtml}

        <div class="mhs-detail-grid">
            <div class="mhs-detail-item">
                <div class="mhs-detail-label">Program Studi & Angkatan</div>
                <div class="mhs-detail-val">${escapeHtml(mhs.jurusan_nama)} (${escapeHtml(mhs.angkatan)})</div>
            </div>
            <div class="mhs-detail-item">
                <div class="mhs-detail-label">Program Magang</div>
                <div class="mhs-detail-val">${escapeHtml(mhs.program)}</div>
            </div>
            <div class="mhs-detail-item">
                <div class="mhs-detail-label">IPK / SKS Kumulatif</div>
                <div class="mhs-detail-val">IPK: ${escapeHtml(mhs.ipk)} | ${escapeHtml(mhs.jumlah_sks)} SKS</div>
            </div>
            <div class="mhs-detail-item">
                <div class="mhs-detail-label">Status Pendaftaran</div>
                <div class="mhs-detail-val"><span class="badge-status badge-${mhs.status}">${mhs.status.toUpperCase()}</span></div>
            </div>
            <div class="mhs-detail-item">
                <div class="mhs-detail-label">Kontak WhatsApp / HP</div>
                <div class="mhs-detail-val" style="display: flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><line x1="12" x2="12.01" y1="18" y2="18"/></svg>
                    <span>${escapeHtml(mhs.no_hp)}</span>
                </div>
            </div>
            <div class="mhs-detail-item">
                <div class="mhs-detail-label">Email Kampus</div>
                <div class="mhs-detail-val" style="display: flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <span>${escapeHtml(mhs.email)}</span>
                </div>
            </div>
        </div>

        <div class="mhs-detail-item" style="margin-top: 16px;">
            <div class="mhs-detail-label">Peminatan yang Dipilih</div>
            <div class="mhs-detail-val">${mhs.peminatan && mhs.peminatan.length > 0 ? escapeHtml(mhs.peminatan.join(', ')) : '-'}</div>
        </div>

        <div class="mhs-detail-item" style="margin-top: 12px;">
            <div class="mhs-detail-label">Domisili / Alamat Mahasiswa</div>
            <div class="mhs-detail-val" style="font-weight: 500; font-size: 0.875rem;">${escapeHtml(mhs.alamat_lengkap)}</div>
        </div>
    `;

    modal.classList.remove('hidden');
}

function renderPendaftarPagination(pag) {
    const info = document.getElementById('pendaftar-pagination-info');
    const btns = document.getElementById('pendaftar-pagination-btns');
    if (!pag) return;

    info.innerText = `Menampilkan ${currentMhsList.length} dari ${pag.total} pendaftar (Halaman ${pag.page} dari ${pag.total_pages || 1})`;
    btns.innerHTML = '';

    if (pag.total_pages > 1) {
        if (pag.page > 1) {
            const btnPrev = document.createElement('button');
            btnPrev.className = 'btn-action-sm btn-action-outline';
            btnPrev.innerText = '← Sebelumnya';
            btnPrev.addEventListener('click', () => { mhsPage--; loadPendaftarData(); });
            btns.appendChild(btnPrev);
        }

        if (pag.page < pag.total_pages) {
            const btnNext = document.createElement('button');
            btnNext.className = 'btn-action-sm btn-action-outline';
            btnNext.innerText = 'Berikutnya →';
            btnNext.addEventListener('click', () => { mhsPage++; loadPendaftarData(); });
            btns.appendChild(btnNext);
        }
    }
}

// ==========================================
// TAB 4: PROFIL UNIT & PIC
// ==========================================
function initProfilModule() {
    const formProfil = document.getElementById('form-update-profil');
    if (formProfil) {
        formProfil.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alamat = document.getElementById('prof-alamat').value.trim();
            const lat = document.getElementById('prof-lat').value.trim();
            const lng = document.getElementById('prof-lng').value.trim();
            const picNama = document.getElementById('prof-pic-nama').value.trim();
            const picJabatan = document.getElementById('prof-pic-jabatan').value.trim();
            const picKontak = document.getElementById('prof-pic-kontak').value.trim();
            const picEmail = document.getElementById('prof-pic-email').value.trim();

            const btnSave = document.getElementById('btn-save-profil');
            btnSave.disabled = true;
            btnSave.innerHTML = `
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="spin-animation"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span>Menyimpan Profil...</span>
            `;

            try {
                const res = await fetch('/api/perusahaan/profil/update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        mode: 'profile',
                        alamat,
                        latitude: lat || null,
                        longitude: lng || null,
                        pic_nama: picNama,
                        pic_jabatan: picJabatan,
                        pic_kontak: picKontak,
                        pic_email: picEmail
                    })
                });
                const data = await res.json();

                if (data.ok) {
                    btnSave.style.background = '#059669';
                    btnSave.style.borderColor = '#059669';
                    btnSave.innerHTML = `
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>✓ Profil Berhasil Disimpan!</span>
                    `;
                    showAdminToast('Profil kantor dan PIC berhasil diperbarui.', 'success', 3500);
                    await loadDashboardSummary();
                    await loadProfilData();
                    setTimeout(() => {
                        btnSave.style.background = '';
                        btnSave.style.borderColor = '';
                        btnSave.innerHTML = `<span>Simpan Profil & PIC</span>`;
                    }, 3000);
                } else {
                    await showAdminAlert(data.error || 'Gagal menyimpan profil.', 'error');
                    btnSave.innerHTML = `<span>Simpan Profil & PIC</span>`;
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat menyimpan profil.', 'error');
                btnSave.innerHTML = `<span>Simpan Profil & PIC</span>`;
            } finally {
                btnSave.disabled = false;
            }
        });
    }

    const formPassword = document.getElementById('form-update-password');
    if (formPassword) {
        formPassword.addEventListener('submit', async (e) => {
            e.preventDefault();
            const curr = document.getElementById('pass-current').value;
            const newP = document.getElementById('pass-new').value;
            const conf = document.getElementById('pass-confirm').value;

            if (newP.length < 8) {
                await showAdminAlert('Kata sandi baru minimal 8 karakter.', 'warning', 'Kata Sandi Terlalu Pendek');
                return;
            }
            if (newP !== conf) {
                await showAdminAlert('Konfirmasi kata sandi tidak cocok.', 'warning', 'Konfirmasi Tidak Cocok');
                return;
            }

            const btnSave = document.getElementById('btn-save-password');
            btnSave.disabled = true;
            btnSave.innerHTML = `
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="spin-animation"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span>Mengubah Kata Sandi...</span>
            `;

            try {
                const res = await fetch('/api/perusahaan/profil/update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        mode: 'password',
                        current_password: curr,
                        new_password: newP,
                        confirm_password: conf
                    })
                });
                const data = await res.json();

                if (data.ok) {
                    btnSave.style.background = '#059669';
                    btnSave.style.borderColor = '#059669';
                    btnSave.innerHTML = `
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>✓ Sandi Berhasil Diperbarui!</span>
                    `;
                    showAdminToast('Kata sandi akun admin berhasil diubah.', 'success', 3500);
                    formPassword.reset();
                    setTimeout(() => {
                        btnSave.style.background = '';
                        btnSave.style.borderColor = '';
                        btnSave.innerHTML = `<span>Perbarui Kata Sandi</span>`;
                    }, 3000);
                } else {
                    await showAdminAlert(data.error || 'Gagal mengubah kata sandi.', 'error');
                    btnSave.innerHTML = `<span>Perbarui Kata Sandi</span>`;
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat mengubah kata sandi.', 'error');
                btnSave.innerHTML = `<span>Perbarui Kata Sandi</span>`;
            } finally {
                btnSave.disabled = false;
            }
        });
    }
}

async function loadProfilData() {
    try {
        const res = await fetch('/api/perusahaan/profil/get.php');
        const data = await res.json();

        if (data.ok) {
            const ent = data.data?.entitas;
            const acc = data.data?.account;

            document.getElementById('prof-nama').value = ent?.nama || '';
            document.getElementById('prof-alamat').value = ent?.alamat || '';
            document.getElementById('prof-lat').value = ent?.latitude || '';
            document.getElementById('prof-lng').value = ent?.longitude || '';
            document.getElementById('prof-pic-nama').value = ent?.pic_nama || '';
            document.getElementById('prof-pic-jabatan').value = ent?.pic_jabatan || '';
            document.getElementById('prof-pic-kontak').value = ent?.pic_kontak || '';
            document.getElementById('prof-pic-email').value = ent?.pic_email || '';

            document.getElementById('prof-account-username').innerText = acc?.username || '-';
        }
    } catch (err) {
        console.error('Error loadProfilData:', err);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
