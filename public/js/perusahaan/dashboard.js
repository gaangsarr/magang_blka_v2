import { showAdminAlert, showAdminConfirm, showAdminToast } from '/js/admin/common.js';
import { setupUnitPicker } from '/js/admin/unit_picker.js';

let csrfToken = null;
let currentSummary = null;
let currentMhsList = [];
let currentPeriodeId = 0;
let mhsPage = 1;
let mhsPerPage = 25;

// Wizard Kuota Module State
let wizardCurrentStep = 1;
let wizardCurrentMode = 'keseluruhan';
let wizardJurusanData = [];
let wizardPeminatanData = [];

// Verification & Transfer State
let selectedPendaftarIds = new Set();
let currentTransferSubtab = 'masuk';
let currentTransferList = [];
let availableUnitOptions = [];

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
    initTransferModule();
    initProfilModule();
    initModalCloseButtons();

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
    const tabBtns = document.querySelectorAll('.perusahaan-nav-btn, .mobile-bottom-nav-item');
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
    document.querySelectorAll('.perusahaan-nav-btn, .mobile-bottom-nav-item').forEach(b => {
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

    // Scroll halus ke atas saat ganti tab di layar HP
    if (window.innerWidth <= 768) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Trigger tab-specific refresh if needed
    if (tabId === 'tab-kuota') {
        loadKuotaData(currentPeriodeId);
    } else if (tabId === 'tab-pendaftar') {
        loadPendaftarData();
    } else if (tabId === 'tab-pemindahan') {
        loadTransferData(currentTransferSubtab);
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
                btnSubmit.innerText = 'Simpan & Lanjutkan ke Dashboard';
            }
        });
    }
}

// ==========================================
// TAB 1: OVERVIEW & DASHBOARD SUMMARY
// ==========================================
function initOverviewModule() {
    const selectOvPeriode = document.getElementById('select-overview-periode');
    if (selectOvPeriode) {
        selectOvPeriode.addEventListener('change', () => {
            const pid = parseInt(selectOvPeriode.value, 10);
            if (pid > 0) {
                loadDashboardSummary(pid);
            }
        });
    }
}

async function loadDashboardSummary(targetPeriodeId = null) {
    try {
        let url = '/api/perusahaan/dashboard/summary.php';
        if (targetPeriodeId) {
            url += `?periode_id=${targetPeriodeId}`;
        }
        const res = await fetch(url);
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
            const elHeader = document.getElementById('header-unit-nama');
            if (elHeader) elHeader.innerText = unitNama + unitSingkatan;
            const elNavUnit = document.getElementById('nav-unit-name');
            if (elNavUnit) elNavUnit.innerText = data.entitas?.singkatan || unitNama;

            // Render Notification Banner
            renderNotificationBanner(data.notification);

            // Update Pending Transfer & Pendaftar Badges
            updateTransferBadge(data.pending_transfer_count || 0);
            updatePendaftarBadge(data.pending_pendaftar_count !== undefined ? data.pending_pendaftar_count : (data.stats?.belum_dicek || 0));

            // Populate Overview Periode Dropdown
            const selectOvPeriode = document.getElementById('select-overview-periode');
            if (selectOvPeriode && data.all_periode) {
                selectOvPeriode.innerHTML = '';
                data.all_periode.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.innerText = `${p.nama} (${p.status.toUpperCase()})`;
                    if (p.id === data.periode?.id) opt.selected = true;
                    selectOvPeriode.appendChild(opt);
                });
            }

            // Render Stats Cards
            const upp = data.periode?.upp;
            const isConfigured = Boolean(data.periode?.is_configured && upp);
            const kuotaTotal = isConfigured ? (parseInt(upp.kuota_total, 10) || 0) : 0;
            const kuotaSisa = isConfigured ? (parseInt(upp.kuota_tersisa, 10) || 0) : 0;
            const totalMhs = data.stats?.total || 0;
            const prodiCount = isConfigured ? (data.periode?.jurusan_count || 0) : 0;

            document.getElementById('stat-kuota-total').innerText = kuotaTotal;
            document.getElementById('stat-pendaftar-masuk').innerText = totalMhs;
            document.getElementById('stat-kuota-sisa').innerText = kuotaSisa;
            document.getElementById('stat-prodi-count').innerText = prodiCount;

            // Render Periode Quick Info
            const periodeEl = document.getElementById('overview-periode-info');
            if (data.periode) {
                currentPeriodeId = data.periode.id;
                let statusBadge = `<span class="badge-status badge-${data.periode.status}">${data.periode.status.toUpperCase()}</span>`;
                
                // Program Magang (1 Bulan / 3 Bulan / 4 Bulan / 5 Bulan)
                const programs = [];
                if (data.periode.program_1_bulan) {
                    programs.push('<span class="badge-status badge-info" style="font-size: 0.775rem; font-weight: 600; background: #e0f2fe; color: #0369a1; border-color: #bae6fd;">1 Bulan</span>');
                }
                if (data.periode.program_3_bulan) {
                    programs.push('<span class="badge-status badge-info" style="font-size: 0.775rem; font-weight: 600; background: #fef3c7; color: #92400e; border-color: #fde68a;">3 Bulan</span>');
                }
                if (data.periode.program_4_bulan) {
                    programs.push('<span class="badge-status badge-info" style="font-size: 0.775rem; font-weight: 600; background: #e0e7ff; color: #3730a3; border-color: #c7d2fe;">4 Bulan</span>');
                }
                if (data.periode.program_5_bulan) {
                    programs.push('<span class="badge-status badge-info" style="font-size: 0.775rem; font-weight: 600; background: #ede9fe; color: #6d28d9; border-color: #ddd6fe;">5 Bulan (MBKM)</span>');
                }
                const programBadges = programs.length > 0 ? programs.join(' ') : '<span style="color: #94a3b8; font-style: italic;">Tidak ditentukan</span>';

                let unitStatusHtml = '';
                if (!isConfigured) {
                    unitStatusHtml = '<span style="color:#d97706; font-weight:700;">Belum Diatur (0 Kuota)</span>';
                } else if (upp && upp.aktif) {
                    unitStatusHtml = '<span style="color:#166534; font-weight:700;">Menerima Magang</span>';
                } else {
                    unitStatusHtml = '<span style="color:#991b1b; font-weight:700;">Tidak Menerima</span>';
                }

                periodeEl.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px; flex-wrap: wrap;">
                        <div style="font-size: 1.1rem; font-weight: 800; color: #0b3d6b;">
                            ${escapeHtml(data.periode.nama)}
                        </div>
                        <div>${statusBadge}</div>
                    </div>
                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 8px; display: flex; flex-direction: column; gap: 10px; font-size: 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="color: #475569; font-weight: 600;">Program Dibuka:</span>
                            <div style="display: inline-flex; gap: 6px; flex-wrap: wrap;">${programBadges}</div>
                        </div>
                        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding-top: 8px; border-top: 1px dashed #e2e8f0; color: #475569;">
                            <span>Status Unit: <strong>${unitStatusHtml}</strong></span>
                            <span>Prodi Dibuka: <strong>${prodiCount} Prodi</strong></span>
                        </div>
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
                    <div style="font-size: 0.825rem; color: #0b3d6b; font-weight: 600; margin-bottom: 12px;">${escapeHtml(pic.jabatan || 'Penanggung Jawab Unit')}</div>
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

    let bannerClass = 'banner-info';
    let iconSvg = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>`;

    if (notif.type === 'success') {
        bannerClass = 'banner-success';
        iconSvg = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`;
    } else if (notif.type === 'warning') {
        bannerClass = 'banner-warning';
        iconSvg = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>`;
    }

    bannerContainer.innerHTML = `
        <div class="notification-banner ${bannerClass}">
            <div class="banner-top-row">
                <div class="banner-icon-box">${iconSvg}</div>
                <div class="banner-title-box">
                    <h4 class="banner-title">${escapeHtml(notif.title)}</h4>
                </div>
                ${notif.can_edit ? `
                    <button class="btn-portal-primary btn-nav-to-tab banner-desktop-btn" data-target="tab-kuota">
                        <span>Atur Kuota Sekarang</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                ` : ''}
            </div>
            <p class="banner-desc">${escapeHtml(notif.message)}</p>
            ${notif.can_edit ? `
                <div class="banner-mobile-action">
                    <button class="btn-portal-primary btn-nav-to-tab" data-target="tab-kuota" style="width: 100%; justify-content: center; padding: 10px 16px; font-size: 0.85rem; font-weight: 700; border-radius: 10px;">
                        <span>Atur Kuota & Prodi Sekarang</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                </div>
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
    const chips = document.getElementById('kuota-quick-chips');

    if (isMenerima) {
        if (cardWrapper) {
            cardWrapper.className = 'p-control-card active-accepting';
            cardWrapper.style.background = '';
            cardWrapper.style.borderColor = '';
        }
        if (badge) {
            badge.className = 'badge-status badge-success';
            badge.innerText = 'Aktif Menerima';
        }
        if (desc) {
            desc.style.color = '#166534';
            desc.innerText = 'Unit kantor Anda aktif menerima pendaftaran pada periode ini.';
        }
        if (chips) chips.style.display = 'flex';
    } else {
        if (cardWrapper) {
            cardWrapper.className = 'p-control-card inactive-accepting';
            cardWrapper.style.background = '';
            cardWrapper.style.borderColor = '';
        }
        if (badge) {
            badge.className = 'badge-status badge-danger';
            badge.innerText = 'Tidak Menerima';
        }
        if (desc) {
            desc.style.color = '#991b1b';
            desc.innerText = 'Unit kantor dinonaktifkan dan tidak akan menerima mahasiswa magang pada periode ini.';
        }
        if (chips) chips.style.display = 'none';
    }
}

// ==========================================
// TAB 2: WIZARD KUOTA & PRODI HELPERS
// ==========================================
function selectMode(mode) {
    wizardCurrentMode = mode;
    const modeCardKeseluruhan = document.getElementById('mode-card-keseluruhan');
    const modeCardBreakdown = document.getElementById('mode-card-breakdown');
    if (mode === 'breakdown') {
        modeCardBreakdown?.classList.add('selected');
        modeCardKeseluruhan?.classList.remove('selected');
        const radioBreakdown = modeCardBreakdown?.querySelector('input[type="radio"]');
        if (radioBreakdown) radioBreakdown.checked = true;
        document.getElementById('alloc-calc-bar')?.classList.remove('hidden');
        const instr = document.getElementById('prodi-instruction-text');
        if (instr) instr.innerText = 'Centang program studi yang diterima dan tentukan jatah kuota masing-masing.';
        document.querySelectorAll('.prodi-alloc-input-wrap').forEach(el => el.style.display = 'flex');
    } else {
        wizardCurrentMode = 'keseluruhan';
        modeCardKeseluruhan?.classList.add('selected');
        modeCardBreakdown?.classList.remove('selected');
        const radioKeseluruhan = modeCardKeseluruhan?.querySelector('input[type="radio"]');
        if (radioKeseluruhan) radioKeseluruhan.checked = true;
        document.getElementById('alloc-calc-bar')?.classList.add('hidden');
        const instr = document.getElementById('prodi-instruction-text');
        if (instr) instr.innerText = 'Centang program studi yang diterima di unit kantor Anda (kuota bebas diperebutkan bersama).';
        document.querySelectorAll('.prodi-alloc-input-wrap').forEach(el => el.style.display = 'none');
    }
    updateAllocSummary();
}

function setWizardStep(targetStep) {
    wizardCurrentStep = targetStep;

    // Update Stepper Visual
    for (let i = 1; i <= 4; i++) {
        const nav = document.getElementById(`step-nav-${i}`);
        const pane = document.getElementById(`wizard-pane-${i}`);
        if (nav) {
            nav.classList.remove('active', 'completed');
            if (i === targetStep) {
                nav.classList.add('active');
            } else if (i < targetStep) {
                nav.classList.add('completed');
            }
        }
        if (pane) {
            if (i === targetStep) pane.classList.remove('hidden');
            else pane.classList.add('hidden');
        }
    }
}

function updateAllocSummary() {
    const totalTarget = parseInt(document.getElementById('input-kuota-total')?.value || '0', 10);
    let allocated = 0;

    document.querySelectorAll('.chk-prodi:checked').forEach(cb => {
        const jid = cb.value;
        const inputAlloc = document.getElementById(`alloc-prodi-${jid}`);
        if (inputAlloc) {
            allocated += parseInt(inputAlloc.value || '0', 10);
        }
    });

    const barTarget = document.getElementById('bar-target-kuota');
    const barCurrent = document.getElementById('bar-current-alloc');
    const barStatus = document.getElementById('bar-alloc-status');

    if (barTarget) barTarget.innerText = `${totalTarget} Mahasiswa`;
    if (barCurrent) barCurrent.innerText = `${allocated} Mahasiswa`;

    if (barStatus) {
        const diff = totalTarget - allocated;
        if (diff === 0) {
            barStatus.className = 'badge-status badge-success';
            barStatus.innerText = 'Alokasi Sesuai';
        } else if (diff > 0) {
            barStatus.className = 'badge-status badge-danger';
            barStatus.innerText = `Kurang ${diff} Kuota`;
        } else {
            barStatus.className = 'badge-status badge-danger';
            barStatus.innerText = `Kelebihan ${Math.abs(diff)} Kuota`;
        }
    }
}

async function validateWizardStep(step) {
    const totalKuota = parseInt(document.getElementById('input-kuota-total')?.value || '0', 10);

    if (step === 1) {
        if (totalKuota <= 0) {
            await showAdminAlert('Total kuota mahasiswa minimal 1 kursi.', 'warning', 'Kuota Tidak Valid');
            return false;
        }
        return true;
    }

    if (step === 2) {
        const checkedProdis = document.querySelectorAll('.chk-prodi:checked');
        if (checkedProdis.length === 0) {
            await showAdminAlert('Silakan pilih minimal 1 Program Studi yang diterima di unit Anda.', 'warning', 'Pilih Program Studi');
            return false;
        }

        if (wizardCurrentMode === 'breakdown') {
            let totalAllocated = 0;
            let anyZero = false;

            checkedProdis.forEach(cb => {
                const inputAlloc = document.getElementById(`alloc-prodi-${cb.value}`);
                const val = parseInt(inputAlloc?.value || '0', 10);
                if (val <= 0) anyZero = true;
                totalAllocated += val;
            });

            if (anyZero) {
                await showAdminAlert('Pada mode Breakdown, setiap prodi yang dicentang wajib memiliki jatah minimal 1 kursi.', 'warning', 'Alokasi Belum Lengkap');
                return false;
            }

            if (totalAllocated !== totalKuota) {
                const diff = totalKuota - totalAllocated;
                const msg = diff > 0
                    ? `Total alokasi per prodi (${totalAllocated}) masih KURANG ${diff} kursi dari Total Kuota (${totalKuota}). Silakan sesuaikan kuota masing-masing prodi.`
                    : `Total alokasi per prodi (${totalAllocated}) MELEBIHI Total Kuota (${totalKuota}) sebanyak ${Math.abs(diff)} kursi. Silakan sesuaikan.`;
                await showAdminAlert(msg, 'warning', 'Total Alokasi Belum Sesuai');
                return false;
            }
        }
        return true;
    }

    if (step === 3) {
        const checkedPem = document.querySelectorAll('.chk-peminatan:checked');
        if (checkedPem.length === 0) {
            await showAdminAlert('Silakan pilih minimal 1 Bidang Peminatan / Penempatan yang dibuka.', 'warning', 'Pilih Peminatan');
            return false;
        }
        return true;
    }

    return true;
}

function renderPeminatanForStep3() {
    const selectedJids = Array.from(document.querySelectorAll('.chk-prodi:checked')).map(cb => parseInt(cb.value, 10));
    const pemGrid = document.getElementById('peminatan-checkboxes');
    if (!pemGrid) return;

    pemGrid.innerHTML = '';
    const matchingPeminatan = wizardPeminatanData.filter(pem => {
        const pJids = pem.jurusan_ids || [];
        return pJids.some(jid => selectedJids.includes(jid));
    });

    if (matchingPeminatan.length === 0) {
        pemGrid.innerHTML = `
            <div style="grid-column: 1 / -1; padding: 24px; text-align: center; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 10px; color: #991b1b;">
                <strong>Tidak ada bidang peminatan yang sesuai dengan program studi yang Anda pilih.</strong><br>
                <span style="font-size: 0.8rem;">Silakan tambahkan peminatan untuk prodi tersebut melalui Admin BLKA atau kembali ke Langkah 2 untuk memilih prodi lain.</span>
            </div>
        `;
        return;
    }

    matchingPeminatan.forEach(pem => {
        const checkedClass = pem.is_selected ? 'checked' : '';
        const card = document.createElement('label');
        card.className = `custom-checkbox-card ${checkedClass}`;
        card.style.display = 'flex';
        card.style.flexDirection = 'column';
        card.style.gap = '6px';

        card.innerHTML = `
            <div style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" class="chk-peminatan" value="${pem.id}" ${pem.is_selected ? 'checked' : ''}>
                <span style="font-size: 0.875rem; font-weight: 700; color: #1e293b;">${escapeHtml(pem.nama_peminatan)}</span>
            </div>
            ${pem.deskripsi ? `<span style="font-size: 0.775rem; color: #64748b; line-height: 1.3;">${escapeHtml(pem.deskripsi)}</span>` : ''}
        `;

        card.querySelector('input').addEventListener('change', (e) => {
            pem.is_selected = e.target.checked;
            if (e.target.checked) card.classList.add('checked');
            else card.classList.remove('checked');
        });

        pemGrid.appendChild(card);
    });
}

function renderReviewForStep4() {
    const totalKuota = parseInt(document.getElementById('input-kuota-total')?.value || '0', 10);
    document.getElementById('rev-total-kuota').innerText = `${totalKuota} Mahasiswa`;
    document.getElementById('rev-mode-kuota').innerText = wizardCurrentMode === 'breakdown'
        ? 'Kuota Terbagi per Prodi (Breakdown)'
        : 'Kuota Keseluruhan (General Pool)';

    const prodiListWrap = document.getElementById('rev-prodi-list');
    const pemListWrap = document.getElementById('rev-pem-list');
    if (prodiListWrap) prodiListWrap.innerHTML = '';
    if (pemListWrap) pemListWrap.innerHTML = '';

    let prodiCount = 0;
    document.querySelectorAll('.chk-prodi:checked').forEach(cb => {
        prodiCount++;
        const jid = cb.value;
        const jObj = wizardJurusanData.find(j => j.id == jid);
        const prodiName = (jObj?.jenjang ? `${jObj.jenjang} - ` : '') + (jObj?.nama_jurusan || `Prodi ${jid}`);
        const allocVal = wizardCurrentMode === 'breakdown' ? document.getElementById(`alloc-prodi-${jid}`)?.value || '0' : null;

        const badge = document.createElement('span');
        badge.className = 'badge-status badge-info';
        badge.style.cssText = 'font-size: 0.8rem; padding: 4px 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;';
        badge.innerHTML = allocVal !== null
            ? `<span>${escapeHtml(prodiName)}</span> <strong style="background: rgba(11,61,107,0.12); padding: 1px 6px; border-radius: 4px; color: #0b3d6b;">${allocVal} Kursi</strong>`
            : `<span>${escapeHtml(prodiName)}</span>`;
        prodiListWrap?.appendChild(badge);
    });
    document.getElementById('rev-prodi-count').innerText = `${prodiCount} Prodi`;

    let pemCount = 0;
    document.querySelectorAll('.chk-peminatan:checked').forEach(cb => {
        pemCount++;
        const pemId = cb.value;
        const pemObj = wizardPeminatanData.find(p => p.id == pemId);
        const badge = document.createElement('span');
        badge.className = 'badge-status badge-success';
        badge.style.cssText = 'font-size: 0.8rem; padding: 4px 10px; font-weight: 600;';
        badge.innerText = pemObj?.nama_peminatan || `Peminatan ${pemId}`;
        pemListWrap?.appendChild(badge);
    });
    document.getElementById('rev-pem-count').innerText = `${pemCount} Bidang`;
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

    // Mode Selector Cards Click
    const modeCardKeseluruhan = document.getElementById('mode-card-keseluruhan');
    const modeCardBreakdown = document.getElementById('mode-card-breakdown');
    modeCardKeseluruhan?.addEventListener('click', () => selectMode('keseluruhan'));
    modeCardBreakdown?.addEventListener('click', () => selectMode('breakdown'));

    // Step Nav Clickable for completed steps
    for (let i = 1; i <= 4; i++) {
        document.getElementById(`step-nav-${i}`)?.addEventListener('click', () => {
            if (i < wizardCurrentStep) {
                setWizardStep(i);
            }
        });
    }

    // Wizard Next & Prev Buttons
    document.querySelectorAll('.btn-wizard-next').forEach(btn => {
        btn.addEventListener('click', async () => {
            const nextStep = parseInt(btn.getAttribute('data-next'), 10);
            const valid = await validateWizardStep(wizardCurrentStep);
            if (valid) {
                if (nextStep === 3) {
                    renderPeminatanForStep3();
                } else if (nextStep === 4) {
                    renderReviewForStep4();
                }
                setWizardStep(nextStep);
            }
        });
    });

    document.querySelectorAll('.btn-wizard-prev').forEach(btn => {
        btn.addEventListener('click', () => {
            const prevStep = parseInt(btn.getAttribute('data-prev'), 10);
            setWizardStep(prevStep);
        });
    });

    // Select/Deselect All Prodi
    document.getElementById('btn-select-all-prodi')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-prodi').forEach(c => {
            c.checked = true;
            c.closest('.custom-checkbox-card')?.classList.add('checked');
            const row = c.closest('.prodi-alloc-row');
            if (row) row.classList.add('checked');
        });
        updateAllocSummary();
    });
    document.getElementById('btn-deselect-all-prodi')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-prodi:not(:disabled)').forEach(c => {
            c.checked = false;
            c.closest('.custom-checkbox-card')?.classList.remove('checked');
            const row = c.closest('.prodi-alloc-row');
            if (row) row.classList.remove('checked');
        });
        updateAllocSummary();
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

    // Live listening on total kuota input
    document.getElementById('input-kuota-total')?.addEventListener('input', () => {
        updateAllocSummary();
    });

    // Form Submit
    const form = document.getElementById('form-kuota-setting');
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pid = parseInt(document.getElementById('select-kuota-periode')?.value || '0', 10);
            const menerima = document.getElementById('toggle-menerima-magang')?.checked;
            const kuotaTotal = parseInt(document.getElementById('input-kuota-total')?.value || '0', 10);

            const jurusanIds = [];
            const jurusanAllocations = [];

            document.querySelectorAll('.chk-prodi:checked').forEach(c => {
                const jid = parseInt(c.value, 10);
                jurusanIds.push(jid);
                const inputAlloc = document.getElementById(`alloc-prodi-${jid}`);
                const allocVal = parseInt(inputAlloc?.value || '0', 10);
                jurusanAllocations.push({ jurusan_id: jid, kuota: allocVal });
            });

            const peminatanIds = [];
            document.querySelectorAll('.chk-peminatan:checked').forEach(c => {
                peminatanIds.push(parseInt(c.value, 10));
            });

            if (menerima && kuotaTotal <= 0) {
                await showAdminAlert('Total kuota mahasiswa minimal 1 jika unit menerima magang.', 'warning', 'Kuota Tidak Valid');
                return;
            }

            if (menerima && jurusanIds.length === 0) {
                await showAdminAlert('Silakan pilih minimal 1 Program Studi yang diterima.', 'warning', 'Pilih Program Studi');
                return;
            }

            if (menerima && wizardCurrentMode === 'breakdown') {
                let sumAlloc = 0;
                for (const alloc of jurusanAllocations) {
                    if (alloc.kuota <= 0) {
                        await showAdminAlert('Pada mode Breakdown, kuota tiap prodi yang dicentang minimal 1.', 'warning', 'Alokasi Tidak Valid');
                        return;
                    }
                    sumAlloc += alloc.kuota;
                }
                if (sumAlloc !== kuotaTotal) {
                    await showAdminAlert(`Total alokasi per prodi (${sumAlloc}) harus sama persis dengan Total Kuota (${kuotaTotal}).`, 'warning', 'Total Belum Sesuai');
                    return;
                }
            }

            if (menerima && peminatanIds.length === 0) {
                await showAdminAlert('Silakan pilih minimal 1 Bidang Peminatan / Penempatan yang dibuka di unit Anda.', 'warning', 'Pilih Peminatan');
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
                        tipe_kuota: wizardCurrentMode,
                        kuota_total: kuotaTotal,
                        jurusan_ids: jurusanIds,
                        jurusan_allocations: jurusanAllocations,
                        peminatan_ids: peminatanIds
                    })
                });
                const data = await res.json();

                if (data.ok) {
                    btnSave.disabled = false;
                    btnSave.style.background = '#059669';
                    btnSave.style.borderColor = '#059669';
                    btnSave.innerHTML = `
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>Pengaturan Kuota Tersimpan</span>
                    `;

                    showAdminToast('Pengaturan kuota berhasil disimpan dan berlaku aktif!', 'success', 4000);

                    await loadDashboardSummary();
                    await loadKuotaData(pid);

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
            const isDibuka = (data.selected_periode?.status === 'dibuka');
            const k = data.kuota;
            const existingTotalKuota = parseInt(k?.kuota_total, 10) || 0;
            const hasExistingKuota = Boolean(k?.upp_id && existingTotalKuota > 0);

            if (canEdit) {
                if (isDibuka && hasExistingKuota) {
                    lockAlert.innerHTML = `
                        <div style="display: flex; align-items: center; gap: 8px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; padding: 12px 16px; border-radius: 8px; font-size: 0.85rem; line-height: 1.4;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span><strong>Pemberitahuan Periode DIBUKA:</strong> Anda <strong>hanya dapat menambah kuota</strong> (minimal ${existingTotalKuota} mahasiswa). Metode kuota terkunci dan unit aktif tidak dapat dinonaktifkan.</span>
                        </div>
                    `;
                    lockAlert.classList.remove('hidden');
                } else {
                    lockAlert.classList.add('hidden');
                }
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
            const toggleMenerima = document.getElementById('toggle-menerima-magang');
            const inputKuota = document.getElementById('input-kuota-total');
            const detailContainer = document.getElementById('kuota-detail-container');

            // Cache data
            wizardJurusanData = k?.jurusan_list || [];
            wizardPeminatanData = k?.peminatan_list || [];

            const selectedProdiCount = wizardJurusanData.filter(j => j.is_selected).length;
            const selectedPemCount = wizardPeminatanData.filter(p => p.is_selected).length;

            const chipKuota = document.getElementById('chip-kuota-val');
            const chipProdi = document.getElementById('chip-prodi-val');
            const chipPem = document.getElementById('chip-pem-val');

            if (chipKuota) chipKuota.innerText = `${k?.kuota_total || 0} Mhs`;
            if (chipProdi) chipProdi.innerText = `${selectedProdiCount} Prodi`;
            if (chipPem) chipPem.innerText = `${selectedPemCount} Bidang`;

            if (toggleMenerima) {
                toggleMenerima.checked = k ? k.menerima_magang : true;
                if (isDibuka && hasExistingKuota) {
                    toggleMenerima.disabled = true;
                    toggleMenerima.title = 'Unit yang telah membuka kuota tidak dapat dinonaktifkan saat periode DIBUKA';
                } else {
                    toggleMenerima.disabled = false;
                    toggleMenerima.title = '';
                }
                updateToggleCardVisual(toggleMenerima.checked);
                if (toggleMenerima.checked) {
                    detailContainer.style.opacity = '1';
                    detailContainer.style.pointerEvents = 'auto';
                } else {
                    detailContainer.style.opacity = '0.4';
                    detailContainer.style.pointerEvents = 'none';
                }
            }

            if (inputKuota) {
                inputKuota.value = k ? k.kuota_total : 5;
                if (isDibuka && hasExistingKuota) {
                    inputKuota.min = existingTotalKuota;
                    inputKuota.title = `Minimal ${existingTotalKuota} saat periode DIBUKA`;
                } else {
                    inputKuota.min = 1;
                    inputKuota.title = '';
                }
            }

            // Set Mode
            const initMode = (k && k.tipe_kuota === 'breakdown') ? 'breakdown' : 'keseluruhan';
            const modeCardKeseluruhan = document.getElementById('mode-card-keseluruhan');
            const modeCardBreakdown = document.getElementById('mode-card-breakdown');
            if (initMode === 'breakdown') {
                modeCardBreakdown?.click();
            } else {
                modeCardKeseluruhan?.click();
            }

            if (isDibuka && hasExistingKuota) {
                if (modeCardKeseluruhan) {
                    modeCardKeseluruhan.style.pointerEvents = 'none';
                    modeCardKeseluruhan.title = 'Metode kuota dikunci selama periode DIBUKA';
                }
                if (modeCardBreakdown) {
                    modeCardBreakdown.style.pointerEvents = 'none';
                    modeCardBreakdown.title = 'Metode kuota dikunci selama periode DIBUKA';
                }
            } else {
                if (modeCardKeseluruhan) {
                    modeCardKeseluruhan.style.pointerEvents = 'auto';
                    modeCardKeseluruhan.title = '';
                }
                if (modeCardBreakdown) {
                    modeCardBreakdown.style.pointerEvents = 'auto';
                    modeCardBreakdown.title = '';
                }
            }

            // Render Prodi Checkboxes with optional allocation input
            const prodiGrid = document.getElementById('prodi-checkboxes');
            if (prodiGrid && wizardJurusanData) {
                prodiGrid.innerHTML = '';
                wizardJurusanData.forEach(j => {
                    const checkedClass = j.is_selected ? 'checked' : '';
                    const labelProdi = (j.jenjang ? `${j.jenjang} - ` : '') + j.nama_jurusan;
                    const defaultAlloc = j.kuota_total || 1;
                    const existingProdiAlloc = parseInt(j.kuota_total, 10) || 0;
                    const isLockedProdi = isDibuka && hasExistingKuota && j.is_selected && (existingProdiAlloc > 0);

                    const card = document.createElement('div');
                    card.className = `prodi-alloc-row ${checkedClass}`;
                    card.innerHTML = `
                        <label style="display: flex; align-items: center; gap: 10px; cursor: ${isLockedProdi ? 'default' : 'pointer'}; flex: 1;">
                            <input type="checkbox" class="chk-prodi" value="${j.id}" ${j.is_selected ? 'checked' : ''} ${isLockedProdi ? 'disabled title="Prodi sudah dialokasikan kuota pada periode DIBUKA dan tidak dapat dinonaktifkan"' : ''} style="cursor: ${isLockedProdi ? 'not-allowed' : 'pointer'}; width: 17px; height: 17px;">
                            <span style="font-size: 0.875rem; font-weight: 700; color: #1e293b;">${escapeHtml(labelProdi)}</span>
                        </label>
                        <div class="prodi-alloc-input-wrap" style="display: ${initMode === 'breakdown' ? 'flex' : 'none'}; align-items: center; gap: 6px;">
                            <label for="alloc-prodi-${j.id}" style="font-size: 0.775rem; color: #64748b;">Kuota:</label>
                            <input type="number" id="alloc-prodi-${j.id}" class="prodi-alloc-input" min="${isLockedProdi ? existingProdiAlloc : 1}" max="1000" value="${defaultAlloc}" style="${!j.is_selected ? 'opacity: 0.4; pointer-events: none;' : ''}" ${isLockedProdi ? `title="Minimal ${existingProdiAlloc} saat periode DIBUKA"` : ''}>
                        </div>
                    `;

                    const chk = card.querySelector('input.chk-prodi');
                    const allocInput = card.querySelector('.prodi-alloc-input');

                    chk.addEventListener('change', (e) => {
                        j.is_selected = e.target.checked;
                        if (e.target.checked) {
                            card.classList.add('checked');
                            allocInput.style.opacity = '1';
                            allocInput.style.pointerEvents = 'auto';
                        } else {
                            card.classList.remove('checked');
                            allocInput.style.opacity = '0.4';
                            allocInput.style.pointerEvents = 'none';
                        }
                        updateAllocSummary();
                    });

                    allocInput.addEventListener('input', () => {
                        updateAllocSummary();
                    });

                    prodiGrid.appendChild(card);
                });
            }

            // Initial step is 1
            setWizardStep(1);
            updateAllocSummary();
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

    // Check All Pendaftar
    const checkAll = document.getElementById('check-all-pendaftar');
    if (checkAll) {
        checkAll.addEventListener('change', () => {
            const checked = checkAll.checked;
            document.querySelectorAll('.chk-mhs-pendaftar').forEach(cb => {
                cb.checked = checked;
                const id = parseInt(cb.value, 10);
                if (checked) selectedPendaftarIds.add(id);
                else selectedPendaftarIds.delete(id);
            });
            updateBulkToolbar();
        });
    }

    // Bulk Approve
    document.getElementById('btn-bulk-approve')?.addEventListener('click', async () => {
        if (selectedPendaftarIds.size === 0) return;
        const confirmed = await showAdminConfirm(
            `Apakah Anda yakin ingin menyetujui ${selectedPendaftarIds.size} mahasiswa yang dipilih?`,
            'Konfirmasi Setujui Terpilih',
            'info',
            'Ya, Setujui',
            'Batal'
        );
        if (!confirmed) return;
        try {
            const res = await fetch('/api/perusahaan/pendaftar/bulk_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'diterima', pendaftaran_ids: Array.from(selectedPendaftarIds) })
            });
            const data = await res.json();
            if (res.ok && data.ok) {
                showAdminToast(data.message || 'Pendaftar terpilih berhasil disetujui.', 'success');
                selectedPendaftarIds.clear();
                updateBulkToolbar();
                await loadPendaftarData();
                await loadDashboardSummary();
            } else {
                showAdminAlert(data.error || 'Gagal menyetujui mahasiswa terpilih.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        }
    });

    // Bulk Reject
    document.getElementById('btn-bulk-reject')?.addEventListener('click', async () => {
        if (selectedPendaftarIds.size === 0) return;
        const confirmed = await showAdminConfirm(
            `Apakah Anda yakin ingin menolak ${selectedPendaftarIds.size} mahasiswa yang dipilih? Kuota unit Anda akan dipulihkan secara otomatis.`,
            'Konfirmasi Tolak Terpilih',
            'warning',
            'Ya, Tolak',
            'Batal'
        );
        if (!confirmed) return;
        try {
            const res = await fetch('/api/perusahaan/pendaftar/bulk_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'ditolak', pendaftaran_ids: Array.from(selectedPendaftarIds), alasan: 'Penolakan masal oleh pihak mitra unit.' })
            });
            const data = await res.json();
            if (res.ok && data.ok) {
                showAdminToast(data.message || 'Pendaftar berhasil ditolak masal.', 'success');
                selectedPendaftarIds.clear();
                updateBulkToolbar();
                await loadPendaftarData();
                await loadDashboardSummary();
            } else {
                showAdminAlert(data.error || 'Gagal memproses tolak masal.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        }
    });

    // Bulk Relocate Trigger
    document.getElementById('btn-bulk-relocate')?.addEventListener('click', async () => {
        if (selectedPendaftarIds.size === 0) return;
        const modal = document.getElementById('modal-perusahaan-bulk-relocate');
        document.getElementById('bulk-relocate-modal-count').innerText = selectedPendaftarIds.size;
        document.getElementById('bulk-relocate-alasan').value = '';
        modal.classList.remove('hidden');

        const selectedMhs = currentMhsList.filter(m => selectedPendaftarIds.has(m.id));
        const distinctProdis = Array.from(new Set(selectedMhs.map(m => m.jurusan_nama).filter(Boolean)));
        const summaryEl = document.getElementById('p-bulk-prodi-summary');
        if (summaryEl) {
            summaryEl.innerText = distinctProdis.length > 0 ? distinctProdis.join(', ') : 'Semua Jurusan';
        }

        const units = await fetchAvailableUnitOptions();
        setupUnitPicker({
            searchInputId: 'p-bulk-relocate-search-input',
            clearBtnId: 'p-bulk-relocate-search-clear',
            statsCountId: 'p-bulk-relocate-stats-count',
            cardsContainerId: 'p-bulk-relocate-cards-container',
            hiddenInputId: 'bulk-relocate-unit',
            units: units,
            context: {
                isBulk: true,
                selectedMhs: selectedMhs,
                requiredCount: selectedMhs.length
            }
        });
    });

    // Form Bulk Relocate Submit
    document.getElementById('form-perusahaan-bulk-relocate')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const targetUppIdVal = document.getElementById('bulk-relocate-unit').value;
        if (!targetUppIdVal) {
            showAdminAlert('Silakan pilih salah satu unit pelaksana tujuan terlebih dahulu.', 'warning');
            return;
        }

        const newUppId = parseInt(targetUppIdVal, 10);
        const targetUnit = availableUnitOptions.find(u => parseInt(u.upp_id, 10) === newUppId);
        const selectedCount = selectedPendaftarIds.size;

        // Validasi kuota unit tujuan untuk rombongan
        if (targetUnit && targetUnit.kuota_tersisa !== null && targetUnit.kuota_tersisa < selectedCount) {
            const proceedQuota = await showAdminConfirm(
                `Sisa kuota unit tujuan (${targetUnit.nama_unit || targetUnit.nama}) hanya ${targetUnit.kuota_tersisa} slot, sedangkan rombongan berjumlah ${selectedCount} mahasiswa.\n\nApakah Anda yakin ingin tetap mengajukan pemindahan melebihi sisa kapasitas kuota resmi?`,
                'Peringatan Kapasitas Kuota',
                'warning',
                'Ya, Tetap Lanjutkan',
                'Batal'
            );
            if (!proceedQuota) return;
        }

        // Validasi kesesuaian prodi rombongan
        if (targetUnit && Array.isArray(targetUnit.prodi_ids) && targetUnit.prodi_ids.length > 0) {
            const selectedMhs = currentMhsList.filter(m => selectedPendaftarIds.has(m.id));
            const mismatchedMhs = selectedMhs.filter(m => !targetUnit.prodi_ids.includes(parseInt(m.jurusan_id, 10)));
            if (mismatchedMhs.length > 0) {
                const proceedProdi = await showAdminConfirm(
                    `Terdapat ${mismatchedMhs.length} dari ${selectedCount} mahasiswa yang jurusannya tidak dibuka di unit tujuan (${targetUnit.nama_unit || targetUnit.nama}).\n\nApakah Anda yakin tetap ingin mengajukan pemindahan rombongan?`,
                    'Peringatan Kesesuaian Prodi Rombongan',
                    'warning',
                    'Ya, Tetap Lanjutkan',
                    'Batal'
                );
                if (!proceedProdi) return;
            }
        }

        const alasan = document.getElementById('bulk-relocate-alasan').value.trim();
        const btn = document.getElementById('btn-submit-p-bulk-relocate');
        btn.disabled = true;
        btn.innerText = 'Memproses...';
        try {
            const res = await fetch('/api/perusahaan/pendaftar/bulk_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    action: 'dipindahkan',
                    pendaftaran_ids: Array.from(selectedPendaftarIds),
                    new_unit_pelaksana_periode_id: newUppId,
                    alasan: alasan
                })
            });
            const data = await res.json();
            if (res.ok && data.ok) {
                showAdminToast(data.message || 'Pemindahan masal berhasil diajukan.', 'success');
                document.getElementById('modal-perusahaan-bulk-relocate')?.classList.add('hidden');
                e.target.reset();
                selectedPendaftarIds.clear();
                updateBulkToolbar();
                await loadPendaftarData();
                await loadDashboardSummary();
                if (currentTransferSubtab) loadTransferData(currentTransferSubtab);
            } else {
                showAdminAlert(data.error || 'Gagal memproses pemindahan masal.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Proses Pemindahan Masal';
        }
    });

    // Form Single Approve Submit
    document.getElementById('form-perusahaan-approve')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const pId = parseInt(document.getElementById('perusahaan-approve-id').value, 10);
        const catatan = document.getElementById('perusahaan-approve-catatan').value.trim();
        const btn = document.getElementById('btn-submit-p-approve');
        btn.disabled = true;
        btn.innerText = 'Menyimpan...';
        try {
            const res = await fetch('/api/perusahaan/pendaftar/update_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ pendaftaran_id: pId, status: 'diterima', catatan_admin: catatan })
            });
            const data = await res.json();
            if (res.ok && data.ok) {
                showAdminToast(data.message || 'Pendaftar berhasil diterima.', 'success');
                document.getElementById('modal-perusahaan-approve')?.classList.add('hidden');
                e.target.reset();
                await loadPendaftarData();
                await loadDashboardSummary();
            } else {
                showAdminAlert(data.error || 'Gagal menerima pendaftar.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Ya, Terima Mahasiswa';
        }
    });

    // Form Single Reject Submit
    document.getElementById('form-perusahaan-reject')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const pId = parseInt(document.getElementById('perusahaan-reject-id').value, 10);
        const alasan = document.getElementById('perusahaan-reject-alasan').value.trim();
        const btn = document.getElementById('btn-submit-p-reject');
        btn.disabled = true;
        btn.innerText = 'Menyimpan...';
        try {
            const res = await fetch('/api/perusahaan/pendaftar/update_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ pendaftaran_id: pId, status: 'ditolak', alasan_penolakan: alasan, catatan: alasan, catatan_admin: alasan })
            });
            const data = await res.json();
            if (res.ok && data.ok) {
                showAdminToast(data.message || 'Pendaftar berhasil ditolak.', 'success');
                document.getElementById('modal-perusahaan-reject')?.classList.add('hidden');
                e.target.reset();
                await loadPendaftarData();
                await loadDashboardSummary();
            } else {
                showAdminAlert(data.error || 'Gagal menolak pendaftar.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Konfirmasi Tolak';
        }
    });

    // Form Single Relocate Submit
    document.getElementById('form-perusahaan-relocate')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const pId = parseInt(document.getElementById('perusahaan-relocate-id').value, 10);
        const targetUppIdVal = document.getElementById('perusahaan-relocate-unit').value;
        if (!targetUppIdVal) {
            showAdminAlert('Silakan pilih salah satu unit pelaksana tujuan terlebih dahulu.', 'warning');
            return;
        }

        const newUppId = parseInt(targetUppIdVal, 10);
        const targetUnit = availableUnitOptions.find(u => parseInt(u.upp_id, 10) === newUppId);
        const mhsItem = currentMhsList.find(m => parseInt(m.id, 10) === pId);

        // Validasi kuota unit penuh
        if (targetUnit && targetUnit.kuota_tersisa !== null && targetUnit.kuota_tersisa <= 0) {
            const proceedQuota = await showAdminConfirm(
                `Sisa kuota pada unit tujuan (${targetUnit.nama_unit || targetUnit.nama}) telah penuh (0 slot).\n\nApakah Anda yakin tetap ingin mengajukan pemindahan mahasiswa ke unit ini?`,
                'Peringatan Kuota Penuh',
                'warning',
                'Tetap Ajukan',
                'Batal'
            );
            if (!proceedQuota) return;
        }

        // Validasi kesesuaian prodi individual
        if (targetUnit && mhsItem && Array.isArray(targetUnit.prodi_ids) && targetUnit.prodi_ids.length > 0) {
            const mhsJurId = parseInt(mhsItem.jurusan_id, 10);
            if (!targetUnit.prodi_ids.includes(mhsJurId)) {
                const proceedProdi = await showAdminConfirm(
                    `Unit tujuan (${targetUnit.nama_unit || targetUnit.nama}) tidak membuka alokasi untuk Program Studi ${mhsItem.jurusan_nama || 'mahasiswa ini'}.\n\nApakah Anda yakin tetap ingin mengajukan pemindahan?`,
                    'Peringatan Kesesuaian Prodi',
                    'warning',
                    'Tetap Ajukan',
                    'Batal'
                );
                if (!proceedProdi) return;
            }
        }

        const alasan = document.getElementById('perusahaan-relocate-alasan').value.trim();
        const btn = document.getElementById('btn-submit-p-relocate');
        btn.disabled = true;
        btn.innerText = 'Mengajukan...';
        try {
            const res = await fetch('/api/perusahaan/pendaftar/update_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ 
                    pendaftaran_id: pId, 
                    status: 'dipindahkan', 
                    new_unit_pelaksana_periode_id: newUppId, 
                    alasan_pemindahan: alasan,
                    catatan: alasan,
                    catatan_admin: alasan,
                    alasan: alasan
                })
            });
            const data = await res.json();
            if (res.ok && data.ok) {
                showAdminToast(data.message || 'Pemindahan peserta berhasil diajukan ke unit tujuan.', 'success');
                document.getElementById('modal-perusahaan-relocate')?.classList.add('hidden');
                e.target.reset();
                await loadPendaftarData();
                await loadDashboardSummary();
                if (currentTransferSubtab) loadTransferData(currentTransferSubtab);
            } else {
                showAdminAlert(data.error || 'Gagal mengajukan pemindahan.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Ajukan Pemindahan';
        }
    });
}

function updateBulkToolbar() {
    const toolbar = document.getElementById('bulk-toolbar-perusahaan');
    const countEl = document.getElementById('bulk-selected-count');
    if (!toolbar) return;
    const count = selectedPendaftarIds.size;
    if (count > 0) {
        toolbar.style.display = 'block';
        if (countEl) countEl.innerText = count;
    } else {
        toolbar.style.display = 'none';
    }
}

async function fetchAvailableUnitOptions() {
    if (availableUnitOptions && availableUnitOptions.length > 0) {
        return availableUnitOptions;
    }
    try {
        const res = await fetch(`/api/perusahaan/pendaftar/unit_options.php` + (currentPeriodeId > 0 ? `?periode_id=${currentPeriodeId}` : ''));
        const data = await res.json();
        if (data.ok && Array.isArray(data.units)) {
            availableUnitOptions = data.units;
            return availableUnitOptions;
        }
    } catch (e) {
        console.error('Error fetching unit options:', e);
    }
    return [];
}

async function loadAvailableUnitOptions(selectEl, targetMhs = null) {
    // Fungsi fallback kompatibilitas
    return await fetchAvailableUnitOptions();
}

function openApproveModal(mhs) {
    const modal = document.getElementById('modal-perusahaan-approve');
    if (!modal) return;
    document.getElementById('perusahaan-approve-id').value = mhs.id;
    document.getElementById('perusahaan-approve-mhs').innerText = `${mhs.nama} (${mhs.nim})`;
    document.getElementById('perusahaan-approve-prodi').innerText = `${mhs.jurusan_nama || '-'} / ${mhs.program || '-'}`;
    document.getElementById('perusahaan-approve-catatan').value = '';
    modal.classList.remove('hidden');
}

function openRejectModal(mhs) {
    const modal = document.getElementById('modal-perusahaan-reject');
    if (!modal) return;
    document.getElementById('perusahaan-reject-id').value = mhs.id;
    document.getElementById('perusahaan-reject-mhs').innerText = `${mhs.nama} (${mhs.nim}) - ${mhs.jurusan_nama || '-'}`;
    document.getElementById('perusahaan-reject-alasan').value = '';
    modal.classList.remove('hidden');
}

async function openRelocateModal(mhs) {
    const modal = document.getElementById('modal-perusahaan-relocate');
    if (!modal) return;
    document.getElementById('perusahaan-relocate-id').value = mhs.id;
    document.getElementById('perusahaan-relocate-mhs').innerText = `${mhs.nama} (${mhs.nim}) - ${mhs.jurusan_nama || '-'}`;
    document.getElementById('perusahaan-relocate-alasan').value = '';
    modal.classList.remove('hidden');

    const units = await fetchAvailableUnitOptions();
    setupUnitPicker({
        searchInputId: 'p-relocate-search-input',
        clearBtnId: 'p-relocate-search-clear',
        statsCountId: 'p-relocate-stats-count',
        cardsContainerId: 'p-relocate-cards-container',
        hiddenInputId: 'perusahaan-relocate-unit',
        units: units,
        context: {
            isBulk: false,
            mhs: mhs
        }
    });
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
            if (data.unverified_count !== undefined) {
                updatePendaftarBadge(data.unverified_count);
            }
        }
    } catch (err) {
        console.error('Error loadPendaftarData:', err);
    }
}

function renderPendaftarTable(list) {
    const tbody = document.getElementById('table-live-pendaftar');
    if (!tbody) return;
    tbody.innerHTML = '';

    const checkAll = document.getElementById('check-all-pendaftar');
    if (checkAll) checkAll.checked = false;

    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: #64748b; padding: 32px;">Belum ada pendaftar mahasiswa pada kriteria pencarian ini.</td></tr>';
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
        const isChecked = selectedPendaftarIds.has(mhs.id);

        const tr = document.createElement('tr');
        if (mhs.is_dipindahkan) {
            tr.style.backgroundColor = 'rgba(99, 102, 241, 0.03)';
        }
        tr.innerHTML = `
            <td style="text-align: center;">
                <input type="checkbox" class="chk-mhs-pendaftar" value="${mhs.id}" ${isChecked ? 'checked' : ''} style="width: 17px; height: 17px; accent-color: #0b3d6b; cursor: pointer;">
            </td>
            <td style="text-align: center; color: #64748b;">${rowNum}</td>
            <td>
                <div style="font-weight: 700; color: #0b3d6b;">${escapeHtml(mhs.nama)}</div>
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
            <td style="white-space: nowrap;">
                <div class="action-cell-stack">
                    <div class="action-row-docs">
                        <button type="button" class="btn-doc-pill pill-detail btn-view-mhs" data-id="${mhs.id}" title="Lihat Detail Pendaftar">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <span>Detail</span>
                        </button>
                        ${mhs.transkrip_path ? `
                        <button type="button" class="btn-doc-pill pill-transkrip btn-transkrip-mhs" data-id="${mhs.id}" data-nama="${escapeHtml(mhs.nama)}" data-nim="${escapeHtml(mhs.nim)}" title="Pratinjau Transkrip Nilai (PDF)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            <span>Transkrip</span>
                        </button>` : ''}
                        ${mhs.cv_path ? `
                        <button type="button" class="btn-doc-pill pill-cv btn-cv-mhs" data-id="${mhs.id}" data-nama="${escapeHtml(mhs.nama)}" data-nim="${escapeHtml(mhs.nim)}" title="Pratinjau Curriculum Vitae / CV (PDF)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><circle cx="12" cy="12" r="2.5"/><path d="M8 18c0-1.8 1.8-3 4-3s4 1.2 4 3"/></svg>
                            <span>CV</span>
                        </button>` : ''}
                        ${mhs.porto_path ? `
                        <button type="button" class="btn-doc-pill pill-porto btn-porto-mhs" data-id="${mhs.id}" data-nama="${escapeHtml(mhs.nama)}" data-nim="${escapeHtml(mhs.nim)}" title="Pratinjau Portofolio (PDF)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                            <span>Porto</span>
                        </button>` : ''}
                    </div>
                    <div class="action-row-decision">
                        ${mhs.status !== 'diterima' ? `
                        <button type="button" class="btn-action-pill pill-approve btn-act-approve" data-id="${mhs.id}" title="Terima Mahasiswa">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Terima</span>
                        </button>` : ''}
                        <button type="button" class="btn-action-pill pill-relocate btn-act-relocate" data-id="${mhs.id}" title="Ajukan Pemindahan ke Unit Lain">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                            <span>Pindahkan</span>
                        </button>
                        ${mhs.status !== 'ditolak' ? `
                        <button type="button" class="btn-action-pill pill-reject btn-act-reject" data-id="${mhs.id}" title="Tolak Pendaftaran">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>Tolak</span>
                        </button>` : ''}
                    </div>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    // Checkbox Listeners
    tbody.querySelectorAll('.chk-mhs-pendaftar').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = parseInt(cb.value, 10);
            if (cb.checked) selectedPendaftarIds.add(id);
            else selectedPendaftarIds.delete(id);
            updateBulkToolbar();
        });
    });

    // Single Action Buttons
    tbody.querySelectorAll('.btn-act-approve').forEach(btn => {
        btn.addEventListener('click', () => {
            const mhsId = parseInt(btn.getAttribute('data-id'), 10);
            const mhs = currentMhsList.find(m => m.id === mhsId);
            if (mhs) openApproveModal(mhs);
        });
    });

    tbody.querySelectorAll('.btn-act-relocate').forEach(btn => {
        btn.addEventListener('click', () => {
            const mhsId = parseInt(btn.getAttribute('data-id'), 10);
            const mhs = currentMhsList.find(m => m.id === mhsId);
            if (mhs) openRelocateModal(mhs);
        });
    });

    tbody.querySelectorAll('.btn-act-reject').forEach(btn => {
        btn.addEventListener('click', () => {
            const mhsId = parseInt(btn.getAttribute('data-id'), 10);
            const mhs = currentMhsList.find(m => m.id === mhsId);
            if (mhs) openRejectModal(mhs);
        });
    });

    tbody.querySelectorAll('.btn-transkrip-mhs').forEach(btn => {
        btn.addEventListener('click', () => {
            const mhsId = btn.getAttribute('data-id');
            window.open(`/api/admin/transkrip/download.php?pendaftaran_id=${mhsId}`, '_blank');
        });
    });

    tbody.querySelectorAll('.btn-cv-mhs').forEach(btn => {
        btn.addEventListener('click', () => {
            const mhsId = btn.getAttribute('data-id');
            window.open(`/api/admin/cv/download.php?pendaftaran_id=${mhsId}`, '_blank');
        });
    });

    tbody.querySelectorAll('.btn-porto-mhs').forEach(btn => {
        btn.addEventListener('click', () => {
            const mhsId = btn.getAttribute('data-id');
            window.open(`/api/admin/porto/download.php?pendaftaran_id=${mhsId}`, '_blank');
        });
    });

    tbody.querySelectorAll('.btn-view-mhs').forEach(btn => {
        btn.addEventListener('click', () => {
            const mhsId = parseInt(btn.getAttribute('data-id'));
            const mhs = currentMhsList.find(item => item.id === mhsId);
            if (mhs) showMhsDetailModal(mhs);
        });
    });

    updateBulkToolbar();
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
                    <span>Pendaftar Hasil Pemindahan Unit</span>
                </div>
                <div style="font-size: 0.825rem; color: #4338ca; line-height: 1.4;">
                    Mahasiswa ini awalnya memilih unit lain, namun oleh Administrator BLKA dialokasikan ke unit Anda:
                    <div style="margin-top: 6px;">
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
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #0b3d6b; color: #fff; font-size: 1.4rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 8px;">
                ${escapeHtml(mhs.nama.charAt(0))}
            </div>
            <h3 style="margin: 0; font-size: 1.15rem; color: #0b3d6b; font-weight: 800;">${escapeHtml(mhs.nama)}</h3>
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

        <div class="mhs-detail-item" style="margin-top: 14px; display: flex; flex-direction: column; gap: 8px;">
            <div class="mhs-detail-label" style="font-weight: 700; color: #475569;">Dokumen Terlampir</div>
            
            ${mhs.transkrip_path ? `
            <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 8px;">
                <div>
                    <div style="font-size: 0.725rem; color: #64748b; font-weight: 600;">Transkrip Nilai</div>
                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        <span style="font-weight: 700; font-size: 0.85rem; color: #1e293b; font-family: monospace;">${escapeHtml(mhs.transkrip_filename || (mhs.transkrip_path ? mhs.transkrip_path.split('/').pop() : `${mhs.nim}.pdf`))}</span>
                    </div>
                </div>
                <button type="button" id="btn-modal-transkrip-viewer" class="btn-portal-outline" style="padding: 6px 14px; font-size: 0.8rem; font-weight: 700; color: #0284c7; border-color: #bae6fd; background: #e0f2fe; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span>Pratinjau Transkrip Nilai</span>
                </button>
            </div>` : ''}

            ${mhs.cv_path ? `
            <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 8px;">
                <div>
                    <div style="font-size: 0.725rem; color: #64748b; font-weight: 600;">Curriculum Vitae (CV)</div>
                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><circle cx="12" cy="12" r="2.5"/><path d="M8 18c0-1.8 1.8-3 4-3s4 1.2 4 3"/></svg>
                        <span style="font-weight: 700; font-size: 0.85rem; color: #1e293b; font-family: monospace;">${escapeHtml(mhs.cv_filename || (mhs.cv_path ? mhs.cv_path.split('/').pop() : `${mhs.nim}_CV.pdf`))}</span>
                    </div>
                </div>
                <button type="button" id="btn-modal-cv-viewer" class="btn-portal-outline" style="padding: 6px 14px; font-size: 0.8rem; font-weight: 700; color: #047857; border-color: #a7f3d0; background: #d1fae5; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span>Pratinjau CV</span>
                </button>
            </div>` : ''}

            ${mhs.porto_path ? `
            <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 8px;">
                <div>
                    <div style="font-size: 0.725rem; color: #64748b; font-weight: 600;">Portofolio</div>
                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                        <span style="font-weight: 700; font-size: 0.85rem; color: #1e293b; font-family: monospace;">${escapeHtml(mhs.porto_filename || (mhs.porto_path ? mhs.porto_path.split('/').pop() : `${mhs.nim}_Porto.pdf`))}</span>
                    </div>
                </div>
                <button type="button" id="btn-modal-porto-viewer" class="btn-portal-outline" style="padding: 6px 14px; font-size: 0.8rem; font-weight: 700; color: #6d28d9; border-color: #ddd6fe; background: #ede9fe; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span>Pratinjau Portofolio</span>
                </button>
            </div>` : ''}
        </div>

        <div class="mhs-detail-item" style="margin-top: 14px;">
            <div class="mhs-detail-label">Peminatan yang Dipilih</div>
            <div class="mhs-detail-val">${mhs.peminatan && mhs.peminatan.length > 0 ? escapeHtml(mhs.peminatan.join(', ')) : '-'}</div>
        </div>

        <div class="mhs-detail-item" style="margin-top: 12px;">
            <div class="mhs-detail-label">Domisili / Alamat Mahasiswa</div>
            <div class="mhs-detail-val" style="font-weight: 500; font-size: 0.875rem;">${escapeHtml(mhs.alamat_lengkap)}</div>
        </div>
    `;

    modal.classList.remove('hidden');

    const btnTranskrip = document.getElementById('btn-modal-transkrip-viewer');
    if (btnTranskrip) {
        btnTranskrip.addEventListener('click', () => {
            window.open(`/api/admin/transkrip/download.php?pendaftaran_id=${mhs.id}`, '_blank');
        });
    }

    const btnCv = document.getElementById('btn-modal-cv-viewer');
    if (btnCv) {
        btnCv.addEventListener('click', () => {
            window.open(`/api/admin/cv/download.php?pendaftaran_id=${mhs.id}`, '_blank');
        });
    }

    const btnPorto = document.getElementById('btn-modal-porto-viewer');
    if (btnPorto) {
        btnPorto.addEventListener('click', () => {
            window.open(`/api/admin/porto/download.php?pendaftaran_id=${mhs.id}`, '_blank');
        });
    }
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
            btnPrev.innerText = 'Sebelumnya';
            btnPrev.addEventListener('click', () => { mhsPage--; loadPendaftarData(); });
            btns.appendChild(btnPrev);
        }

        if (pag.page < pag.total_pages) {
            const btnNext = document.createElement('button');
            btnNext.className = 'btn-action-sm btn-action-outline';
            btnNext.innerText = 'Berikutnya';
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
                        <span>Profil Berhasil Disimpan</span>
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
                        <span>Sandi Berhasil Diperbarui</span>
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

// ==========================================
// BADGE NOTIFIKASI HELPER
// ==========================================
function updatePendaftarBadge(count) {
    const badgeDesktop = document.getElementById('badge-pendaftar-pending');
    const badgeMobile = document.getElementById('badge-pendaftar-pending-mobile');
    const statSub = document.getElementById('stat-pendaftar-pending-sub');

    const c = parseInt(count, 10) || 0;
    [badgeDesktop, badgeMobile].forEach(badge => {
        if (!badge) return;
        if (c > 0) {
            badge.innerText = c;
            badge.classList.remove('hidden');
        } else {
            badge.innerText = '0';
            badge.classList.add('hidden');
        }
    });

    if (statSub) {
        if (c > 0) {
            statSub.innerText = `${c} Belum Diverifikasi`;
            statSub.classList.remove('hidden');
        } else {
            statSub.classList.add('hidden');
        }
    }
}

// ==========================================
// TAB 4: PEMINDAHAN PESERTA MODULE
// ==========================================
function updateTransferBadge(count) {
    const badgeDesktop = document.getElementById('badge-transfer-pending');
    const badgeMobile = document.getElementById('badge-transfer-pending-mobile');
    const badgeSubtab = document.getElementById('badge-transfer-masuk-count');

    [badgeDesktop, badgeMobile, badgeSubtab].forEach(badge => {
        if (!badge) return;
        if (count > 0) {
            badge.innerText = count;
            badge.classList.remove('hidden');
        } else {
            badge.innerText = '0';
            badge.classList.add('hidden');
        }
    });
}

function initTransferModule() {
    const btnMasuk = document.getElementById('btn-subtab-transfer-masuk');
    const btnKeluar = document.getElementById('btn-subtab-transfer-keluar');
    const searchInput = document.getElementById('filter-search-transfer');

    if (btnMasuk) {
        btnMasuk.addEventListener('click', () => {
            currentTransferSubtab = 'masuk';
            btnMasuk.classList.add('active');
            btnKeluar?.classList.remove('active');
            const thUnit = document.getElementById('th-transfer-unit');
            const thAction = document.getElementById('th-transfer-action');
            if (thUnit) thUnit.innerText = 'Unit Asal';
            if (thAction) thAction.style.display = '';
            loadTransferData('masuk');
        });
    }

    if (btnKeluar) {
        btnKeluar.addEventListener('click', () => {
            currentTransferSubtab = 'keluar';
            btnKeluar.classList.add('active');
            btnMasuk?.classList.remove('active');
            const thUnit = document.getElementById('th-transfer-unit');
            const thAction = document.getElementById('th-transfer-action');
            if (thUnit) thUnit.innerText = 'Unit Tujuan';
            if (thAction) thAction.style.display = 'none';
            loadTransferData('keluar');
        });
    }

    if (searchInput) {
        let debounceTimer;
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                loadTransferData(currentTransferSubtab);
            }, 300);
        });
    }

    // Form Response Transfer Approval
    const formResp = document.getElementById('form-perusahaan-transfer-response');
    if (formResp) {
        formResp.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pemindahanId = parseInt(document.getElementById('tr-resp-id').value, 10);
            const action = document.getElementById('tr-resp-action').value;
            const catatan = document.getElementById('tr-resp-catatan').value.trim();

            const btnConfirm = document.getElementById('btn-confirm-transfer-resp');
            btnConfirm.disabled = true;
            btnConfirm.innerText = 'Memproses...';

            try {
                const res = await fetch('/api/perusahaan/pemindahan/approval.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        pemindahan_id: pemindahanId,
                        action: action,
                        catatan: catatan
                    })
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    showAdminToast(data.message || 'Respon pemindahan berhasil diproses.', 'success');
                    document.getElementById('modal-perusahaan-transfer-response')?.classList.add('hidden');
                    formResp.reset();
                    await loadTransferData(currentTransferSubtab);
                    await loadDashboardSummary();
                    await loadPendaftarData();
                } else {
                    showAdminAlert(data.error || 'Gagal memproses approval pemindahan.', 'error');
                }
            } catch (err) {
                console.error('Error response transfer:', err);
                showAdminAlert('Terjadi kesalahan jaringan.', 'error');
            } finally {
                btnConfirm.disabled = false;
                btnConfirm.innerText = 'Konfirmasi';
            }
        });
    }
}

async function loadTransferData(subtab = 'masuk') {
    const tbody = document.getElementById('table-transfer-body');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #64748b; padding: 32px;">Memuat data pemindahan...</td></tr>';

    const search = document.getElementById('filter-search-transfer')?.value.trim() || '';
    let url = `/api/perusahaan/pemindahan/list.php?type=${encodeURIComponent(subtab)}`;
    if (currentPeriodeId > 0) url += `&periode_id=${currentPeriodeId}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;

    try {
        const res = await fetch(url);
        const data = await res.json();
        if (data.ok) {
            currentTransferList = data.data || [];
            updateTransferBadge(data.pending_count || 0);
            renderTransferTable(currentTransferList, subtab);
        } else {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #dc2626; padding: 32px;">${escapeHtml(data.error || 'Gagal memuat data pemindahan.')}</td></tr>`;
        }
    } catch (err) {
        console.error('Error loadTransferData:', err);
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #dc2626; padding: 32px;">Terjadi kesalahan jaringan.</td></tr>';
    }
}

function renderTransferTable(list, subtab) {
    const tbody = document.getElementById('table-transfer-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!list || list.length === 0) {
        const emptyMsg = subtab === 'masuk' 
            ? 'Tidak ada data pemindahan peserta yang masuk ke unit Anda.' 
            : 'Belum ada histori pemindahan peserta keluar dari unit Anda.';
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #64748b; padding: 32px;">${emptyMsg}</td></tr>`;
        return;
    }

    list.forEach((item, idx) => {
        const tr = document.createElement('tr');

        // Status Badge
        let statusBadge = '';
        if (item.status_approval === 'menunggu_approval') {
            statusBadge = '<span class="badge-status badge-persiapan" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">Menunggu Approval</span>';
        } else if (item.status_approval === 'disetujui') {
            statusBadge = '<span class="badge-status badge-dibuka" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Disetujui</span>';
        } else if (item.status_approval === 'ditolak') {
            statusBadge = '<span class="badge-status badge-ditutup" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">Ditolak</span>';
        } else if (item.status_approval === 'force_blka') {
            statusBadge = '<span class="badge-status badge-info" style="background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff;">Ditetapkan BLKA</span>';
        }

        const rawDate = item.created_at || item.tanggal_diajukan || item.waktu_pengajuan;
        const dateStr = rawDate ? new Date(rawDate).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-';
        const unitName = subtab === 'masuk' 
            ? (item.unit_asal_nama || 'Unit Lain') 
            : (item.unit_tujuan_nama || 'Unit Lain');
        const jurNama = item.jurusan_nama || item.nama_jurusan || '-';

        let actionHtml = '';
        if (subtab === 'masuk') {
            if (item.status_approval === 'menunggu_approval') {
                actionHtml = `
                    <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: center;">
                        <button type="button" class="btn-portal-primary btn-tr-approve" data-id="${item.id}" style="padding: 5px 12px; font-size: 0.775rem; background: #16a34a; border-color: #16a34a;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Terima</span>
                        </button>
                        <button type="button" class="btn-portal-outline btn-tr-reject" data-id="${item.id}" style="padding: 5px 12px; font-size: 0.775rem; color: #dc2626; border-color: #fecaca; background: #fef2f2;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <span>Tolak</span>
                        </button>
                    </div>
                `;
            } else {
                actionHtml = `<span style="font-size: 0.8rem; color: #64748b;">${item.status_approval === 'disetujui' ? 'Diterima di Unit Anda' : 'Dikembalikan ke Asal'}</span>`;
            }
        }

        tr.innerHTML = `
            <td style="text-align: center; color: #64748b;">${idx + 1}</td>
            <td>
                <div style="font-weight: 700; color: #0b3d6b;">${escapeHtml(item.mahasiswa_nama || item.nama || '-')}</div>
                <div style="font-family: monospace; font-size: 0.8rem; color: #64748b;">NIM: ${escapeHtml(item.nim || '-')}</div>
            </td>
            <td>
                <div style="font-weight: 600; color: #1e293b;">${escapeHtml(jurNama)}</div>
            </td>
            <td>
                <div style="font-weight: 600; color: #0f172a;">${escapeHtml(unitName)}</div>
            </td>
            <td style="font-size: 0.825rem; color: #334155; max-width: 220px;">
                <div>${escapeHtml(item.alasan_pemindahan || '-')}</div>
                ${item.approval_catatan ? `<div style="margin-top: 4px; font-size: 0.775rem; color: #64748b;"><strong>Catatan Respon:</strong> ${escapeHtml(item.approval_catatan)}</div>` : ''}
            </td>
            <td style="text-align: center;">${statusBadge}</td>
            <td style="text-align: center; font-size: 0.8rem; color: #64748b;">${dateStr}</td>
            ${subtab === 'masuk' ? `<td style="text-align: center; white-space: nowrap;">${actionHtml}</td>` : ''}
        `;
        tbody.appendChild(tr);
    });

    if (subtab === 'masuk') {
        tbody.querySelectorAll('.btn-tr-approve').forEach(btn => {
            btn.addEventListener('click', () => {
                const trId = parseInt(btn.getAttribute('data-id'), 10);
                const item = currentTransferList.find(t => t.id === trId);
                if (item) openTransferResponseModal(item, 'terima');
            });
        });

        tbody.querySelectorAll('.btn-tr-reject').forEach(btn => {
            btn.addEventListener('click', () => {
                const trId = parseInt(btn.getAttribute('data-id'), 10);
                const item = currentTransferList.find(t => t.id === trId);
                if (item) openTransferResponseModal(item, 'tolak');
            });
        });
    }
}

function openTransferResponseModal(item, action) {
    const modal = document.getElementById('modal-perusahaan-transfer-response');
    if (!modal) return;

    document.getElementById('tr-resp-id').value = item.id;
    document.getElementById('tr-resp-action').value = action;
    document.getElementById('tr-resp-mhs').innerText = `${item.mahasiswa_nama} (NIM: ${item.nim || '-'})`;
    document.getElementById('tr-resp-prodi').innerText = item.jurusan_nama || '-';
    document.getElementById('tr-resp-unit-asal').innerText = item.unit_asal_nama || 'Unit Lain';
    document.getElementById('tr-resp-alasan').innerText = item.alasan_pemindahan || '-';
    document.getElementById('tr-resp-catatan').value = '';

    const titleEl = document.getElementById('tr-resp-title');
    const alertEl = document.getElementById('tr-resp-alert');
    const btnSubmit = document.getElementById('btn-confirm-transfer-resp');

    if (action === 'terima') {
        titleEl.innerText = 'Setujui Pemindahan Masuk';
        titleEl.style.color = '#16a34a';
        alertEl.className = 'alert alert-info';
        alertEl.innerText = 'Dengan menyetujui pemindahan ini, mahasiswa akan resmi ditempatkan di unit Anda.';
        alertEl.classList.remove('hidden');
        btnSubmit.style.background = '#16a34a';
        btnSubmit.style.borderColor = '#16a34a';
        btnSubmit.innerHTML = '<span>Ya, Terima Mahasiswa</span>';
    } else {
        titleEl.innerText = 'Tolak Pemindahan Masuk';
        titleEl.style.color = '#dc2626';
        alertEl.className = 'alert alert-error';
        alertEl.innerText = 'Jika ditolak, alokasi kuota unit Anda akan dipulihkan (+1) dan mahasiswa akan otomatis dikembalikan ke unit asalnya.';
        alertEl.classList.remove('hidden');
        btnSubmit.style.background = '#dc2626';
        btnSubmit.style.borderColor = '#dc2626';
        btnSubmit.innerHTML = '<span>Tolak Pemindahan</span>';
    }

    modal.classList.remove('hidden');
}

function initModalCloseButtons() {
    document.querySelectorAll('.btn-close-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            if (targetId) {
                document.getElementById(targetId)?.classList.add('hidden');
            }
        });
    });
}
