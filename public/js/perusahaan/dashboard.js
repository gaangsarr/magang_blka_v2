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
    initRosterModule();
    initSideDrawer();
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

    document.querySelectorAll('.btn-nav-to-tab, .btn-quick-nav-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetTab = btn.getAttribute('data-target') || btn.getAttribute('data-tab');
            if (targetTab) switchTab(targetTab);
        });
    });

    // Wire Phase Stepper items
    document.querySelectorAll('.phase-step-item').forEach(stepItem => {
        stepItem.addEventListener('click', () => {
            const targetTab = stepItem.getAttribute('data-target-tab');
            if (targetTab) switchTab(targetTab);
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

    // Scroll halus ke atas saat ganti tab agar konten tab baru mulai dari paling atas
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Sync Phase Stepper Highlight
    updatePhaseStepperHighlight(tabId);

    // Trigger tab-specific refresh if needed
    if (tabId === 'tab-kuota') {
        loadKuotaData(currentPeriodeId);
    } else if (tabId === 'tab-pendaftar') {
        loadPendaftarData();
    } else if (tabId === 'tab-pemindahan') {
        loadTransferData(currentTransferSubtab);
    } else if (tabId === 'tab-profil') {
        loadProfilData();
    } else if (tabId === 'tab-roster') {
        loadRosterData();
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
            updateExecutiveContextAndChecklist(data);

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
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><circle cx="12" cy="18" r="1" fill="currentColor"/></svg>
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

let toastDismissTimeout = null;

function renderNotificationBanner(notif) {
    const bannerContainer = document.getElementById('banner-container');
    if (!bannerContainer) return;
    bannerContainer.innerHTML = '';
    if (toastDismissTimeout) {
        clearTimeout(toastDismissTimeout);
        toastDismissTimeout = null;
    }

    if (!notif) return;

    let bannerClass = 'banner-info';
    let iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r="1.2" fill="currentColor" stroke="none"/></svg>`;

    if (notif.type === 'success') {
        bannerClass = 'banner-success';
        iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`;
    } else if (notif.type === 'warning') {
        bannerClass = 'banner-warning';
        iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><circle cx="12" cy="17" r="1.2" fill="currentColor" stroke="none"/></svg>`;
    }

    const toastEl = document.createElement('div');
    toastEl.className = `notification-banner ${bannerClass}`;
    toastEl.innerHTML = `
        <div class="banner-body">
            <div class="banner-icon-box">${iconSvg}</div>
            <div class="banner-text-box">
                <h4 class="banner-title">${escapeHtml(notif.title)}</h4>
                <p class="banner-desc">${escapeHtml(notif.message)}</p>
                ${notif.can_edit ? `
                    <div class="banner-action-wrap">
                        <button type="button" class="btn-toast-action btn-nav-to-tab" data-target="tab-kuota">
                            <span>Atur Kuota</span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>
                ` : ''}
            </div>
            <button type="button" class="btn-toast-close" title="Tutup" aria-label="Tutup">&times;</button>
        </div>
    `;

    bannerContainer.appendChild(toastEl);

    const closeToast = () => {
        toastEl.classList.add('toast-hiding');
        setTimeout(() => {
            if (toastEl.parentNode) toastEl.parentNode.removeChild(toastEl);
        }, 300);
    };

    const btnClose = toastEl.querySelector('.btn-toast-close');
    if (btnClose) btnClose.addEventListener('click', closeToast);

    toastEl.querySelectorAll('.btn-nav-to-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            switchTab(btn.getAttribute('data-target'));
            closeToast();
        });
    });

    // Auto dismiss after 9 seconds
    toastDismissTimeout = setTimeout(() => {
        closeToast();
    }, 9000);
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
        card.className = `custom-checkbox-card peminatan-compact-card ${checkedClass}`;

        // Cari program studi yang terhubung dan relevan dengan pilihan di Langkah 2
        let matchedJurusan = (pem.jurusan_ids || [])
            .filter(jid => selectedJids.includes(jid))
            .map(jid => wizardJurusanData.find(j => j.id === jid))
            .filter(Boolean);

        if (matchedJurusan.length === 0) {
            matchedJurusan = (pem.jurusan_ids || [])
                .map(jid => wizardJurusanData.find(j => j.id === jid))
                .filter(Boolean);
        }

        const prodiTagsHtml = matchedJurusan.map(j => {
            const jenjangPrefix = j.jenjang && !j.nama_jurusan.startsWith(j.jenjang) ? `${j.jenjang} ` : '';
            const prodiLabel = `${jenjangPrefix}${j.nama_jurusan}`;
            return `<span class="peminatan-prodi-tag">${escapeHtml(prodiLabel)}</span>`;
        }).join('');

        card.innerHTML = `
            <div class="peminatan-header-row">
                <input type="checkbox" class="chk-peminatan" value="${pem.id}" ${pem.is_selected ? 'checked' : ''}>
                <span class="peminatan-name">${escapeHtml(pem.nama_peminatan)}</span>
            </div>
            ${prodiTagsHtml ? `<div class="peminatan-prodi-tags">${prodiTagsHtml}</div>` : ''}
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
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r="1.2" fill="currentColor" stroke="none"/></svg>
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
        tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; color: #64748b; padding: 28px;">Belum ada pendaftar mahasiswa pada kriteria pencarian ini.</td></tr>';
        return;
    }

    list.forEach((mhs, idx) => {
        let statusBadge = `<span class="badge-status badge-${mhs.status}">${mhs.status.toUpperCase()}</span>`;
        if (mhs.is_dipindahkan) {
            statusBadge += `<div style="font-size: 0.675rem; color: #4338ca; font-weight: 700; margin-top: 3px; display: inline-flex; align-items: center; gap: 3px;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/></svg>
                <span>Dipindahkan</span>
            </div>`;
        }

        // Peminatan Pilihan (1, 2, 3) dalam bentuk Tag Stack Rapi
        let peminatanHtml = '<span style="font-size: 0.72rem; color: #94a3b8; font-style: italic;">-</span>';
        if (Array.isArray(mhs.peminatan) && mhs.peminatan.length > 0) {
            peminatanHtml = `
                <div class="mhs-peminatan-stack">
                    ${mhs.peminatan.map((pem, pIdx) => `
                        <span class="p-peminatan-tag" title="Pilihan ${pIdx + 1}: ${escapeHtml(pem)}">
                            <span class="p-peminatan-num">${pIdx + 1}</span>
                            <span class="p-peminatan-text">${escapeHtml(pem)}</span>
                        </span>
                    `).join('')}
                </div>
            `;
        }

        const rowNum = ((mhsPage - 1) * mhsPerPage) + (idx + 1);
        const isChecked = selectedPendaftarIds.has(mhs.id);

        const tr = document.createElement('tr');
        if (mhs.is_dipindahkan) {
            tr.style.backgroundColor = 'rgba(99, 102, 241, 0.03)';
        }
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', (e) => {
            // Jangan buka drawer jika klik checkbox atau tombol aksi
            if (e.target.closest('input[type="checkbox"]') || e.target.closest('button') || e.target.closest('a')) {
                return;
            }
            openCandidateDrawer(mhs);
        });

        // Berkas Dokumen Upload (Transkrip, CV, Porto)
        let docsHtml = '';
        if (mhs.transkrip_path) {
            docsHtml += `
                <button type="button" class="btn-doc-pill pill-transkrip btn-transkrip-mhs" data-id="${mhs.id}" data-nama="${escapeHtml(mhs.nama)}" data-nim="${escapeHtml(mhs.nim)}" title="Pratinjau Transkrip Nilai (PDF)">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Transkrip</span>
                </button>
            `;
        }
        if (mhs.cv_path) {
            docsHtml += `
                <button type="button" class="btn-doc-pill pill-cv btn-cv-mhs" data-id="${mhs.id}" data-nama="${escapeHtml(mhs.nama)}" data-nim="${escapeHtml(mhs.nim)}" title="Pratinjau Curriculum Vitae / CV (PDF)">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><circle cx="12" cy="12" r="2.5"/></svg>
                    <span>CV</span>
                </button>
            `;
        }
        if (mhs.porto_path) {
            docsHtml += `
                <button type="button" class="btn-doc-pill pill-porto btn-porto-mhs" data-id="${mhs.id}" data-nama="${escapeHtml(mhs.nama)}" data-nim="${escapeHtml(mhs.nim)}" title="Pratinjau Portofolio Karya (PDF)">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    <span>Portofolio</span>
                </button>
            `;
        }
        if (!docsHtml) {
            docsHtml = '<span style="font-size: 0.72rem; color: #94a3b8; font-style: italic;">-</span>';
        }

        tr.innerHTML = `
            <td style="text-align: center;">
                <input type="checkbox" class="chk-mhs-pendaftar" value="${mhs.id}" ${isChecked ? 'checked' : ''} style="width: 16px; height: 16px; accent-color: #0b3d6b; cursor: pointer;">
            </td>
            <td style="text-align: center; color: #64748b; font-size: 0.75rem;">${rowNum}</td>
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.8125rem; line-height: 1.25;">${escapeHtml(mhs.nama)}</div>
                <div style="font-family: monospace; font-size: 0.72rem; color: #64748b; margin-top: 2px;">NIM: ${escapeHtml(mhs.nim)} (${escapeHtml(mhs.jenis_kelamin || '-')})</div>
            </td>
            <td>
                <div style="font-weight: 600; color: #1e293b; font-size: 0.775rem; line-height: 1.25;">${escapeHtml(mhs.jurusan_nama)}</div>
                <div style="font-size: 0.72rem; color: #64748b; margin-top: 2px;">Angkatan: ${escapeHtml(mhs.angkatan)}</div>
            </td>
            <td style="text-align: center;">
                <span style="font-size: 0.71rem; font-weight: 600; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 2px 6px; border-radius: 4px; white-space: nowrap;">${escapeHtml(mhs.program)}</span>
            </td>
            <td style="text-align: center; font-size: 0.775rem; font-variant-numeric: tabular-nums;">
                <strong>${escapeHtml(mhs.ipk)}</strong> / ${escapeHtml(mhs.jumlah_sks)} SKS
            </td>
            <td>
                ${peminatanHtml}
            </td>
            <td>
                <div style="font-size: 0.75rem; color: #1e293b; display: flex; align-items: center; gap: 4px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><circle cx="12" cy="18" r="1" fill="currentColor"/></svg>
                    <span style="font-variant-numeric: tabular-nums;">${escapeHtml(mhs.no_hp)}</span>
                </div>
                <div style="font-size: 0.72rem; color: #64748b; display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <span style="overflow: hidden; text-overflow: ellipsis; max-width: 145px; white-space: nowrap;">${escapeHtml(mhs.email)}</span>
                </div>
            </td>
            <td style="text-align: center; vertical-align: middle;">
                <div class="mhs-docs-stack">
                    ${docsHtml}
                </div>
            </td>
            <td style="text-align: center; vertical-align: middle;">${statusBadge}</td>
            <td style="white-space: nowrap; text-align: center; vertical-align: middle;">
                <div class="pendaftar-action-stack">
                    <button type="button" class="btn-doc-pill pill-detail btn-view-mhs" data-id="${mhs.id}" title="Lihat Profil & Drawer Berkas">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span>Detail</span>
                    </button>
                    <div class="action-row-decision">
                        ${mhs.status !== 'diterima' ? `
                        <button type="button" class="btn-action-pill pill-approve btn-act-approve" data-id="${mhs.id}" title="Terima Mahasiswa">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Terima</span>
                        </button>` : ''}
                        <button type="button" class="btn-action-pill pill-relocate btn-act-relocate" data-id="${mhs.id}" title="Ajukan Pemindahan ke Unit Lain">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/></svg>
                            <span>Pindah</span>
                        </button>
                        ${mhs.status !== 'ditolak' ? `
                        <button type="button" class="btn-action-pill pill-reject btn-act-reject" data-id="${mhs.id}" title="Tolak Pendaftaran">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
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
            if (mhs) openCandidateDrawer(mhs);
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
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/></svg>
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
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><circle cx="12" cy="18" r="1" fill="currentColor"/></svg>
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
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Terima</span>
                        </button>
                        <button type="button" class="btn-portal-outline btn-tr-reject" data-id="${item.id}" style="padding: 5px 12px; font-size: 0.775rem; color: #dc2626; border-color: #fecaca; background: #fef2f2;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
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


// ==========================================
// PHASE STEPPER & SMART ACTION SYNC
// ==========================================
function updatePhaseLifecycle(summary) {
    if (!summary) return;
    const periode = summary.periode;
    const stats = summary.stats || {};
    const unverifiedCount = stats.belum_dicek !== undefined ? stats.belum_dicek : (stats.total || 0);
    const acceptedCount = stats.diterima || 0;
    const isConfigured = Boolean(periode?.is_configured && periode?.upp);

    const s1 = document.getElementById('stepper-fase-1');
    const s2 = document.getElementById('stepper-fase-2');
    const s3 = document.getElementById('stepper-fase-3');
    const badge1 = document.getElementById('badge-icon-fase-1');
    const badge2 = document.getElementById('badge-icon-fase-2');
    const badge3 = document.getElementById('badge-icon-fase-3');
    const chip1 = document.getElementById('chip-fase-1-status');
    const chip2 = document.getElementById('chip-fase-2-status');
    const chip3 = document.getElementById('chip-fase-3-status');
    const heroFase = document.getElementById('hero-fase-status-text');

    // Reset base classes
    [s1, s2, s3].forEach(el => el?.classList.remove('is-completed', 'is-active', 'is-upcoming', 'is-urgent'));

    if (!periode) {
        s1?.classList.add('is-upcoming');
        s2?.classList.add('is-upcoming');
        s3?.classList.add('is-upcoming');
        if (heroFase) heroFase.innerText = 'Periode Belum Aktif';
        return;
    }

    // KONDISI 1: Unit BELUM mengatur kuota pada periode ini
    if (!isConfigured) {
        s1?.classList.add('is-active', 'is-urgent');
        if (badge1) badge1.innerHTML = '1';
        if (chip1) {
            chip1.innerText = 'Perlu Diatur';
            chip1.style.background = '#fef2f2';
            chip1.style.color = '#dc2626';
            chip1.style.borderColor = '#fecaca';
        }

        s2?.classList.add('is-upcoming');
        if (badge2) badge2.innerHTML = '2';
        if (chip2) {
            chip2.innerText = 'Menunggu Kuota Unit';
            chip2.style.background = '#f8fafc';
            chip2.style.color = '#64748b';
            chip2.style.borderColor = '#e2e8f0';
        }

        s3?.classList.add('is-upcoming');
        if (badge3) badge3.innerHTML = '3';
        if (chip3) {
            chip3.innerText = 'Menunggu Penetapan';
            chip3.style.background = '#f8fafc';
            chip3.style.color = '#64748b';
            chip3.style.borderColor = '#e2e8f0';
        }

        if (heroFase) heroFase.innerText = 'Fase 1: Setup Kuota Unit (Belum Diatur)';
        return;
    }

    // KONDISI 2: Unit SUDAH mengatur kuota
    // Fase 1 SELESAI (Centang Hijau)
    s1?.classList.add('is-completed');
    if (badge1) badge1.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    if (chip1) {
        chip1.innerText = 'Terkonfirmasi';
        chip1.style.background = '#ecfdf5';
        chip1.style.color = '#065f46';
        chip1.style.borderColor = '#a7f3d0';
    }

    const pStatus = (periode.status || '').toLowerCase();

    if (pStatus === 'persiapan') {
        s2?.classList.add('is-upcoming');
        if (badge2) badge2.innerHTML = '2';
        if (chip2) {
            chip2.innerText = 'Menunggu Pendaftaran';
            chip2.style.background = '#f8fafc';
            chip2.style.color = '#64748b';
            chip2.style.borderColor = '#e2e8f0';
        }

        s3?.classList.add('is-upcoming');
        if (badge3) badge3.innerHTML = '3';
        if (chip3) {
            chip3.innerText = 'Menunggu Penetapan';
            chip3.style.background = '#f8fafc';
            chip3.style.color = '#64748b';
            chip3.style.borderColor = '#e2e8f0';
        }

        if (heroFase) heroFase.innerText = 'Fase 1: Setup Kuota Selesai';
    } else if (pStatus === 'dibuka' || pStatus === 'buka') {
        s2?.classList.add('is-active');
        if (badge2) badge2.innerHTML = '2';
        if (chip2) {
            chip2.innerText = unverifiedCount > 0 ? `${unverifiedCount} Menunggu Review` : 'Semua Berkas Direview';
            chip2.style.background = '#e0f7fa';
            chip2.style.color = '#006064';
            chip2.style.borderColor = '#80deea';
        }

        s3?.classList.add('is-upcoming');
        if (badge3) badge3.innerHTML = '3';
        if (chip3) {
            chip3.innerText = acceptedCount > 0 ? `${acceptedCount} Terpilih` : 'Menunggu Penetapan';
            chip3.style.background = '#f8fafc';
            chip3.style.color = '#64748b';
            chip3.style.borderColor = '#e2e8f0';
        }

        if (heroFase) heroFase.innerText = 'Fase 2: Seleksi & Verifikasi Berkas';
    } else if (pStatus === 'tutup' || pStatus === 'selesai') {
        s2?.classList.add('is-completed');
        if (badge2) badge2.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        if (chip2) {
            chip2.innerText = 'Seleksi Ditutup';
            chip2.style.background = '#ecfdf5';
            chip2.style.color = '#065f46';
            chip2.style.borderColor = '#a7f3d0';
        }

        s3?.classList.add('is-active');
        if (badge3) badge3.innerHTML = '3';
        if (chip3) {
            chip3.innerText = `${acceptedCount} Mahasiswa Sah`;
            chip3.style.background = '#ecfdf5';
            chip3.style.color = '#065f46';
            chip3.style.borderColor = '#a7f3d0';
        }

        if (heroFase) heroFase.innerText = 'Fase 3: Roster Mahasiswa Sah';
    }
}

function updatePhaseStepperHighlight(activeTab) {
    const s1 = document.getElementById('stepper-fase-1');
    const s2 = document.getElementById('stepper-fase-2');
    const s3 = document.getElementById('stepper-fase-3');

    [s1, s2, s3].forEach(el => el?.classList.remove('is-tab-focused'));

    if (activeTab === 'tab-kuota') {
        s1?.classList.add('is-tab-focused');
    } else if (activeTab === 'tab-pendaftar' || activeTab === 'tab-pemindahan') {
        s2?.classList.add('is-tab-focused');
    } else if (activeTab === 'tab-roster') {
        s3?.classList.add('is-tab-focused');
    }
}

function updateExecutiveContextAndChecklist(summary) {
    if (!summary) return;
    const entitas = summary.entitas;
    const periode = summary.periode;
    const stats = summary.stats || {};
    const unverifiedCount = stats.belum_dicek !== undefined ? stats.belum_dicek : 0;
    const transferCount = summary.pending_transfer_count || 0;
    const acceptedCount = stats.diterima || 0;

    // 1. Context Hero Bar
    const heroUnit = document.getElementById('hero-unit-name');
    if (heroUnit) heroUnit.innerText = entitas?.nama || 'Portal Mitra Unit PLN';

    const heroPic = document.getElementById('hero-pic-info');
    if (heroPic) {
        const pNama = entitas?.pic?.nama || entitas?.pic_nama || '';
        const pJabatan = entitas?.pic?.jabatan || entitas?.pic_jabatan || '';
        const picNama = pNama ? `PIC: ${pNama}` : 'PIC Belum Dilengkapi';
        const picJabatan = pJabatan ? ` (${pJabatan})` : '';
        heroPic.innerText = `${picNama}${picJabatan} • ${entitas?.alamat || 'Unit PLN'}`;
    }

    const heroPeriode = document.getElementById('hero-periode-text');
    if (heroPeriode) {
        heroPeriode.innerText = periode?.nama ? `${periode.nama} (${(periode.status || '').toUpperCase()})` : 'Tidak Ada Periode Aktif';
    }

    // 2. Lifecycle Stepper Sync
    updatePhaseLifecycle(summary);

    // 3. Action Checklist
    const actPendaftarCount = document.getElementById('act-pendaftar-count');
    if (actPendaftarCount) actPendaftarCount.innerText = unverifiedCount;

    const actTransferCount = document.getElementById('act-transfer-count');
    if (actTransferCount) actTransferCount.innerText = transferCount;

    const rowVerifikasi = document.getElementById('row-act-verifikasi');
    if (rowVerifikasi) {
        if (unverifiedCount === 0) {
            rowVerifikasi.style.opacity = '0.6';
        } else {
            rowVerifikasi.style.opacity = '1';
        }
    }

    const rowTransfer = document.getElementById('row-act-transfer');
    if (rowTransfer) {
        if (transferCount === 0) {
            rowTransfer.style.display = 'none';
        } else {
            rowTransfer.style.display = 'flex';
        }
    }

    const actKuotaSummary = document.getElementById('act-kuota-summary');
    if (actKuotaSummary && periode?.upp) {
        const totalK = parseInt(periode.upp.kuota_total || 0, 10);
        const sisaK = parseInt(periode.upp.kuota_tersisa || 0, 10);
        actKuotaSummary.innerText = `Kuota Terisi: ${totalK - sisaK} / ${totalK} Kursi Mahasiswa`;
    }

    const actionCounter = document.getElementById('action-checklist-counter');
    if (actionCounter) {
        const totalUrgent = unverifiedCount + transferCount;
        actionCounter.innerText = totalUrgent > 0 ? `${totalUrgent} Aksi Mendesak Perlu Tindakan` : 'Semua Tugas Selesai ✓';
    }

    const badgeRoster = document.getElementById('badge-roster-count');
    if (badgeRoster) {
        if (acceptedCount > 0) {
            badgeRoster.innerText = acceptedCount;
            badgeRoster.classList.remove('hidden');
        } else {
            badgeRoster.classList.add('hidden');
        }
    }
}

// ==========================================
// CANDIDATE SLIDING SIDE DRAWER
// ==========================================
let currentDrawerMhs = null;

function initSideDrawer() {
    const backdrop = document.getElementById('drawer-backdrop');
    const btnClose = document.getElementById('btn-close-drawer');

    if (backdrop) backdrop.addEventListener('click', closeCandidateDrawer);
    if (btnClose) btnClose.addEventListener('click', closeCandidateDrawer);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeCandidateDrawer();
    });
}

function openCandidateDrawer(mhs) {
    currentDrawerMhs = mhs;
    const drawer = document.getElementById('drawer-candidate');
    const backdrop = document.getElementById('drawer-backdrop');
    if (!drawer || !backdrop) return;

    // Populate Data
    const namaEl = document.getElementById('drawer-mhs-nama');
    const nimEl = document.getElementById('drawer-mhs-nim');
    const avatarEl = document.getElementById('drawer-avatar');
    const statusBadgeEl = document.getElementById('drawer-status-badge');
    const durasiEl = document.getElementById('drawer-durasi');
    const ipkSksEl = document.getElementById('drawer-ipk-sks');
    const peminatanEl = document.getElementById('drawer-peminatan');
    const kontakEl = document.getElementById('drawer-kontak');
    const docsContainer = document.getElementById('drawer-docs-container');
    const notesEl = document.getElementById('drawer-notes');

    if (namaEl) namaEl.innerText = mhs.nama || '-';
    if (nimEl) nimEl.innerText = `NIM: ${mhs.nim} • ${mhs.jurusan_nama || '-'} (${mhs.angkatan || '-'})`;
    if (avatarEl) avatarEl.innerText = (mhs.nama || 'M').charAt(0).toUpperCase();
    if (statusBadgeEl) {
        statusBadgeEl.innerHTML = `<span class="badge-status badge-${mhs.status}">${(mhs.status || '').toUpperCase()}</span>`;
    }
    if (durasiEl) durasiEl.innerText = mhs.program || 'Magang Reguler';
    if (ipkSksEl) ipkSksEl.innerText = `IPK: ${mhs.ipk || '-'} | SKS: ${mhs.jumlah_sks || '-'} SKS`;
    if (peminatanEl) {
        const pemText = mhs.peminatan && mhs.peminatan.length > 0 ? mhs.peminatan.join(', ') : 'Umum / Tidak Spesifik';
        peminatanEl.innerText = pemText;
    }
    if (kontakEl) {
        kontakEl.innerHTML = `
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <span>WhatsApp: <strong>${escapeHtml(mhs.no_hp || '-')}</strong></span>
                <span>•</span>
                <span>Email: <strong>${escapeHtml(mhs.email || '-')}</strong></span>
            </div>
        `;
    }

    // Populate Docs
    if (docsContainer) {
        docsContainer.innerHTML = '';
        let hasAnyDoc = false;

        if (mhs.transkrip_path) {
            hasAnyDoc = true;
            docsContainer.innerHTML += `
                <div class="drawer-doc-card">
                    <div class="drawer-doc-info">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #0284c7;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span>Transkrip Nilai Akademik Resmi</span>
                    </div>
                    <a href="/api/admin/transkrip/download.php?pendaftaran_id=${mhs.id}" target="_blank" class="drawer-doc-btn">
                        <span>Lihat PDF</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                </div>
            `;
        }

        if (mhs.cv_path) {
            hasAnyDoc = true;
            docsContainer.innerHTML += `
                <div class="drawer-doc-card">
                    <div class="drawer-doc-info">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #10b981;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><circle cx="12" cy="12" r="2.5"/><path d="M8 18c0-1.8 1.8-3 4-3s4 1.2 4 3"/></svg>
                        <span>Curriculum Vitae (CV) Kandidat</span>
                    </div>
                    <a href="/api/admin/cv/download.php?pendaftaran_id=${mhs.id}" target="_blank" class="drawer-doc-btn">
                        <span>Lihat PDF</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                </div>
            `;
        }

        if (mhs.porto_path) {
            hasAnyDoc = true;
            docsContainer.innerHTML += `
                <div class="drawer-doc-card">
                    <div class="drawer-doc-info">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #7c3aed;"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                        <span>Portofolio Hasil Karya</span>
                    </div>
                    <a href="/api/admin/porto/download.php?pendaftaran_id=${mhs.id}" target="_blank" class="drawer-doc-btn">
                        <span>Lihat PDF</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                </div>
            `;
        }

        if (!hasAnyDoc) {
            docsContainer.innerHTML = '<span style="font-size: 0.825rem; color: #94a3b8; font-style: italic;">Mahasiswa belum mengunggah dokumen opsional.</span>';
        }
    }

    if (notesEl) {
        notesEl.innerText = mhs.catatan_admin || 'Belum ada catatan internal unit untuk kandidat ini.';
    }

    // Slide in
    backdrop.classList.add('is-open');
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
}

function closeCandidateDrawer() {
    const drawer = document.getElementById('drawer-candidate');
    const backdrop = document.getElementById('drawer-backdrop');
    if (drawer) {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
    }
    if (backdrop) backdrop.classList.remove('is-open');
    currentDrawerMhs = null;
}

// ==========================================
// FASE 3: ROSTER MAHASISWA SAH
// ==========================================
function initRosterModule() {
    const btnExportRoster = document.getElementById('btn-export-roster');
    if (btnExportRoster) {
        btnExportRoster.addEventListener('click', () => {
            const originalHtml = btnExportRoster.innerHTML;
            btnExportRoster.disabled = true;
            btnExportRoster.style.opacity = '0.75';
            btnExportRoster.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span>Menyiapkan Arsip ZIP...</span>
            `;

            const params = new URLSearchParams();
            params.set('status', 'diterima');
            if (currentPeriodeId > 0) {
                params.set('periode_id', currentPeriodeId);
            }
            window.location.href = `/api/perusahaan/pendaftar/export.php?${params.toString()}`;

            setTimeout(() => {
                btnExportRoster.disabled = false;
                btnExportRoster.style.opacity = '1';
                btnExportRoster.innerHTML = originalHtml;
            }, 3500);
        });
    }
}

async function loadRosterData() {
    const container = document.getElementById('roster-grid-container');
    if (!container) return;

    try {
        let url = `/api/perusahaan/pendaftar/list.php?per_page=100&filter_status=diterima`;
        if (currentPeriodeId > 0) url += `&periode_id=${currentPeriodeId}`;
        const res = await fetch(url);
        const data = await res.json();

        if (data.ok && Array.isArray(data.data) && data.data.length > 0) {
            container.innerHTML = '';
            data.data.forEach(mhs => {
                const card = document.createElement('div');
                card.className = 'roster-card';
                const cleanPhone = (mhs.no_hp || '').replace(/[^0-9]/g, '');
                const waNumber = cleanPhone.startsWith('0') ? '62' + cleanPhone.slice(1) : cleanPhone;
                
                card.innerHTML = `
                    <div class="roster-card-header">
                        <div class="roster-card-avatar">${escapeHtml((mhs.nama || 'M').charAt(0).toUpperCase())}</div>
                        <div class="roster-card-info">
                            <span class="roster-card-name">${escapeHtml(mhs.nama)}</span>
                            <span class="roster-card-nim">NIM: ${escapeHtml(mhs.nim)} • ${escapeHtml(mhs.jurusan_nama)}</span>
                        </div>
                    </div>
                    <div style="font-size: 0.8rem; color: #475569; display: flex; flex-direction: column; gap: 4px;">
                        <div><strong>Program:</strong> ${escapeHtml(mhs.program || '-')}</div>
                        <div><strong>IPK:</strong> ${escapeHtml(mhs.ipk || '-')} (SKS: ${escapeHtml(mhs.jumlah_sks || '-')})</div>
                        <div><strong>Peminatan:</strong> ${escapeHtml((mhs.peminatan && mhs.peminatan.length > 0) ? mhs.peminatan.join(', ') : 'Umum')}</div>
                    </div>
                    <div class="roster-contact-actions">
                        ${waNumber ? `
                        <a href="https://wa.me/${waNumber}" target="_blank" class="btn-roster-contact btn-roster-wa">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            <span>Hubungi WA</span>
                        </a>` : ''}
                        ${mhs.email ? `
                        <a href="mailto:${escapeHtml(mhs.email)}" class="btn-roster-contact btn-roster-email">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            <span>Email</span>
                        </a>` : ''}
                    </div>
                `;
                container.appendChild(card);
            });
        } else {
            container.innerHTML = `
                <div class="admin-card" style="grid-column: 1 / -1; padding: 40px 24px; text-align: center; color: var(--brand-muted);">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px; color: #94a3b8;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    <div style="font-weight: 700; font-size: 1rem; color: var(--brand-navy); margin-bottom: 4px;">Belum Ada Mahasiswa Berstatus Diterima</div>
                    <p style="font-size: 0.85rem; margin: 0;">Silakan lakukan verifikasi penerimaan di tab <strong>Verifikasi Peserta</strong> terlebih dahulu.</p>
                </div>
            `;
        }
    } catch (e) {
        console.error('Error loadRosterData:', e);
    }
}
