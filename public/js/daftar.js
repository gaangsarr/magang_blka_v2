let csrfToken = null;
let minIpk5Bulan = 3.00;
let minSks5Bulan = 110;
import { initMap, searchLocation, invalidateMapSize } from './map.js';


function escapeHtml(str) {
    if (!str && str !== 0) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


let currentStep = 1;
const maxSteps = 5;

// Data Form State
const formData = {
    periode_id: null,
    reservasi_id: null,
    upp_id: null,
    program: '',
    nama: '',
    jenis_kelamin: '',
    ipk: '',
    jumlah_sks: '',
    no_hp: '',
    lat: '',
    lng: '',
    rt: '',
    rw: '',
    kelurahan: '',
    kecamatan: '',
    kota_kabupaten: '',
    provinsi: '',
    alamat_lengkap: '',
    peminatan: [],
    periode_nama: ''
};

// Elements
const wizardHeader = document.getElementById('wizard-header');
const btnPrev = document.getElementById('btn-prev');
const btnNext = document.getElementById('btn-next');
const btnSubmit = document.getElementById('btn-submit');
const errorBox = document.getElementById('error-box');

// Timer
let reservasiTimerInterval = null;

document.addEventListener('DOMContentLoaded', async () => {
    try {
        // Cek Session & Status
        const statusRes = await fetch('/api/auth/status.php');
        const statusData = await statusRes.json();
        if(statusData.csrf_token) csrfToken = statusData.csrf_token;
        if (!statusRes.ok || !statusData.authenticated) {
            window.location.href = '/login.html';
            return;
        }

        window.statusAuthData = statusData;
        window.isEligibleCohort = (statusData.is_eligible_pendaftaran_aktif !== false && statusData.is_eligible_angkatan !== false);

        window.showIneligibleCohortModal = function() {
            const modal = document.getElementById('modalIneligibleAngkatan');
            const textEligible = document.getElementById('textModalEligibleAngkatan');
            const textMhs = document.getElementById('textModalMhsAngkatan');
            if (textEligible) textEligible.innerText = statusData.angkatan_eligible || '-';
            if (textMhs) textMhs.innerText = statusData.mhs_angkatan || '-';

            if (modal) {
                modal.classList.remove('hidden');
            } else {
                alert(`Mohon maaf, pendaftaran magang periode saat ini hanya dibuka untuk mahasiswa Angkatan (${statusData.angkatan_eligible}).\n\nAngkatan Anda (${statusData.mhs_angkatan}) belum / tidak diizinkan mendaftar.`);
            }
        };

        if (statusData.sudah_mendaftar) {
            window.location.href = '/status.html';
            return;
        }


        // Fill User Profile Data in Profile Dropdown & Header
        if (statusData.user) {
            const user = statusData.user;
            const shortName = user.nama ? user.nama.split(' ')[0] : 'Mahasiswa';
            const initial = user.nama ? user.nama.charAt(0).toUpperCase() : 'M';
            
            const avatarCircle = document.getElementById('profileAvatarCircle');
            const shortNameElem = document.getElementById('profileShortName');
            const cardAvatar = document.getElementById('profileCardAvatar');
            const cardName = document.getElementById('profileCardName');
            const cardNim = document.getElementById('profileCardNim');
            const cardJurusan = document.getElementById('profileCardJurusan');
            const cardAngkatan = document.getElementById('profileCardAngkatan');
            const cardEmail = document.getElementById('profileCardEmail');

            if (avatarCircle) avatarCircle.textContent = initial;
            if (shortNameElem) shortNameElem.textContent = shortName;
            if (cardAvatar) cardAvatar.textContent = initial;
            if (cardName) cardName.textContent = user.nama;
            if (cardNim) cardNim.textContent = user.nim;
            if (cardJurusan) cardJurusan.textContent = user.jurusan;
            if (cardAngkatan) cardAngkatan.textContent = user.angkatan;
            if (cardEmail) cardEmail.textContent = user.email;

            const mobileAvatar = document.getElementById('mobileDrawerAvatar');
            const mobileName = document.getElementById('mobileDrawerUserName');
            const mobileMeta = document.getElementById('mobileDrawerUserMeta');
            if (mobileAvatar) mobileAvatar.textContent = initial;
            if (mobileName) mobileName.textContent = user.nama;
            if (mobileMeta) mobileMeta.textContent = (user.nim || '-') + (user.jurusan ? ' • ' + user.jurusan : '');
        }

        // Ambil Data Profil
        const profilRes = await fetch('/api/mahasiswa/profil.php');
        const profilData = await profilRes.json();
        if (profilRes.ok && profilData.data) {
            document.getElementById('nim').value = profilData.data.nim || '';
            document.getElementById('jurusan').value = profilData.data.jurusan || '';
            document.getElementById('angkatan').value = profilData.data.angkatan || '';
            document.getElementById('email').value = profilData.data.email || '';
            if (profilData.data.nama && !profilData.data.needs_nama) {
                document.getElementById('nama').value = profilData.data.nama;
                document.getElementById('nama').readOnly = true;
            }
        }

        // Ambil Pengaturan Syarat System (Min IPK & Min SKS)
        try {
            const setRes = await fetch('/api/pengaturan/public.php');
            const setData = await setRes.json();
            if (setRes.ok && setData.data) {
                if (setData.data.min_ipk_5bulan) minIpk5Bulan = parseFloat(setData.data.min_ipk_5bulan);
                if (setData.data.min_sks_5bulan) minSks5Bulan = parseInt(setData.data.min_sks_5bulan, 10);
            }
        } catch (e) {
            console.error('Gagal memuat pengaturan syarat:', e);
        }

        // Ambil Periode Aktif
        const periodeRes = await fetch('/api/periode/aktif.php');
        const periodeData = await periodeRes.json();
        if (!periodeRes.ok || periodeData.error) {
            const noPeriodBox = document.getElementById('no-periode-box');
            const wizardForm = document.getElementById('wizard-form');
            const wizardHeaderEl = document.getElementById('wizard-header');
            if (noPeriodBox && wizardForm && wizardHeaderEl) {
                noPeriodBox.classList.remove('hidden');
                wizardForm.classList.add('hidden');
                wizardHeaderEl.classList.add('hidden');
            } else {
                showError(periodeData.error || 'Saat ini tidak ada periode pendaftaran yang dibuka.');
                disableNav();
            }
            return;
        }

        formData.periode_id = periodeData.periode.id;
        formData.periode_nama = periodeData.periode.nama;
        setupProgramOptions(periodeData.periode);
        setupDokumenSyarat(periodeData.periode);

        // Ambil Peminatan
        const peminatanRes = await fetch('/api/peminatan/list.php');
        const peminatanData = await peminatanRes.json();
        if (peminatanRes.ok && peminatanData.data) {
            setupPeminatanOptions(peminatanData.data);
        }

        // Live validation IPK
        const ipkInput = document.getElementById('ipk');
        ipkInput.addEventListener('input', function() {
            let val = parseFloat(this.value);
            if (val > 4) {
                this.value = '4.00';
            }
        });

        // Init Map pada step 3
        initMap('map-domisili', (lat, lng) => {
            document.getElementById('lat').value = lat.toFixed(7);
            document.getElementById('lng').value = lng.toFixed(7);
        });

        // Event Listener Map Search
        document.getElementById('btn-search-lokasi').addEventListener('click', () => {
            const query = document.getElementById('search-lokasi').value;
            searchLocation(query, null, (err) => showToast(err, 'error', 'Pencarian Lokasi'));
        });

        let draftParsed = null;
        const savedDraft = sessionStorage.getItem('magang_draft_form');
        if (savedDraft) {
            try {
                draftParsed = JSON.parse(savedDraft);
            } catch (e) {}
        }
        await initWilayahDropdowns(draftParsed);

        // Real-time numeric-only sanitizer untuk No HP, RT, dan RW
        ['no_hp', 'rt', 'rw'].forEach(id => {
            const inputEl = document.getElementById(id);
            if (inputEl) {
                inputEl.addEventListener('input', (e) => {
                    e.target.value = e.target.value.replace(/\D/g, '');
                });
            }
        });

        // Navigasi Event Listener
        btnNext.addEventListener('click', handleNext);
        btnPrev.addEventListener('click', handlePrev);
        btnSubmit.addEventListener('click', handleSubmit);

        // Cek jika ada reservasi aktif yang belum kadaluarsa (mis. saat refresh halaman)
        if (statusData.active_reservasi && statusData.active_reservasi.reservasi_id) {
            const activeRes = statusData.active_reservasi;
            const expireMs = activeRes.expired_at_ms || new Date(activeRes.expired_at_iso || activeRes.expired_at).getTime();
            if (expireMs > Date.now()) {
                if (draftParsed) {
                    try {
                        Object.assign(formData, draftParsed);
                        if (formData.program) {
                            const radio = document.querySelector(`input[name="program"][value="${formData.program}"]`);
                            if (radio) radio.checked = true;
                        }
                        if (formData.jenis_kelamin) document.getElementById('jenis_kelamin').value = formData.jenis_kelamin;
                        if (formData.ipk) document.getElementById('ipk').value = formData.ipk;
                        if (formData.jumlah_sks) document.getElementById('jumlah_sks').value = formData.jumlah_sks;
                        if (formData.no_hp) document.getElementById('no_hp').value = formData.no_hp;
                        if (formData.lat) document.getElementById('lat').value = formData.lat;
                        if (formData.lng) document.getElementById('lng').value = formData.lng;
                        if (formData.rt) document.getElementById('rt').value = formData.rt;
                        if (formData.rw) document.getElementById('rw').value = formData.rw;
                        if (formData.alamat_lengkap) document.getElementById('alamat_lengkap').value = formData.alamat_lengkap;
                        if (formData.peminatan && Array.isArray(formData.peminatan)) {
                            formData.peminatan.forEach(val => {
                                const cb = document.querySelector(`input[name="peminatan"][value="${val}"]`);
                                if (cb) cb.checked = true;
                            });
                        }
                    } catch (e) {}
                }

                formData.upp_id = activeRes.upp_id;
                formData.reservasi_id = activeRes.reservasi_id;
                formData.nama_unit_dipilih = activeRes.nama_unit;

                startTimer(expireMs);
                currentStep = 5;
                updateUI();
                return;
            }
        }

        updateUI();

    } catch (err) {
        console.error(err);
        showError('Gagal memuat data awal. Periksa koneksi internet.');
    }
});

function setupProgramOptions(periode) {
    const banner = document.getElementById('ineligible-program-banner');
    const tableBody = document.getElementById('program-options-table-body');
    if (!tableBody) return;
    
    tableBody.innerHTML = '';
    
    // 1. Render Banner if Ineligible
    if (!window.isEligibleCohort && window.statusAuthData) {
        if (banner) {
            banner.classList.remove('hidden');
            banner.innerHTML = `
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 14px;">
                    <div style="flex-shrink: 0; color: #d97706; margin-top: 2px;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 0.95rem; color: #92400e; margin-bottom: 4px;">
                            Pendaftaran Periode Ini Dibatasi (Khusus Angkatan ${escapeHtml(window.statusAuthData.angkatan_eligible || '-')})
                        </div>
                        <p style="margin: 0; font-size: 0.85rem; line-height: 1.5; color: #78350f;">
                            Pendaftaran periode <strong>${escapeHtml(periode.nama)}</strong> dibuka khusus untuk mahasiswa <strong>Angkatan ${escapeHtml(window.statusAuthData.angkatan_eligible || '-')}</strong>. Angkatan Anda saat ini (<strong>${window.statusAuthData.mhs_angkatan || '-'}</strong>) tidak dapat mendaftar pada gelombang ini.
                        </p>
                        <div style="margin-top: 10px;">
                            <a href="/status.html" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.825rem; font-weight: 700; color: #0b3d6b; text-decoration: underline;">
                                <span>Lihat Riwayat & Pengumuman Saya</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
            `;
        }
    } else {
        if (banner) {
            banner.classList.add('hidden');
            banner.innerHTML = '';
        }
    }

    // 2. Prepare Programs List
    const programs = [];
    if (periode.program_1_bulan) {
        programs.push({
            id: '1_bulan',
            title: 'Magang 1 Bulan'
        });
    }
    if (periode.program_5_bulan) {
        programs.push({
            id: '5_bulan',
            title: 'Magang 5 Bulan (KRS)'
        });
    }

    if (programs.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="5" style="padding: 24px; text-align: center; color: #94a3b8;">Tidak ada program magang yang tersedia pada periode ini.</td>
            </tr>
        `;
        return;
    }

    const angkatanBadge = window.statusAuthData && window.statusAuthData.angkatan_eligible
        ? `<span style="display: inline-block; font-weight: 700; font-size: 0.775rem; color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd; padding: 3px 8px; border-radius: 6px;">Angkatan ${escapeHtml(window.statusAuthData.angkatan_eligible)}</span>`
        : `<span style="display: inline-block; font-weight: 600; font-size: 0.775rem; color: #047857; background: #e6f4ea; padding: 3px 8px; border-radius: 6px;">Semua Angkatan</span>`;

    const statusBadge = window.isEligibleCohort
        ? `<span style="display: inline-flex; align-items: center; gap: 4px; font-weight: 700; font-size: 0.75rem; color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 4px 10px; border-radius: 20px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Memenuhi Syarat</span>`
        : `<span style="display: inline-flex; align-items: center; gap: 4px; font-weight: 700; font-size: 0.75rem; color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 4px 10px; border-radius: 20px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> Tidak Memenuhi Syarat</span>`;

    programs.forEach(prog => {
        const tr = document.createElement('tr');
        tr.className = window.isEligibleCohort ? 'selectable' : '';
        tr.style.cursor = window.isEligibleCohort ? 'pointer' : 'default';

        tr.innerHTML = `
            <td style="text-align: center; vertical-align: middle;">
                <input type="radio" name="program" value="${prog.id}" class="custom-radio program-radio-btn" ${!window.isEligibleCohort ? 'disabled' : ''} style="width: 18px; height: 18px; cursor: ${window.isEligibleCohort ? 'pointer' : 'not-allowed'}; accent-color: #0b3d6b;">
            </td>
            <td>
                <div style="font-weight: 700; color: #0f172a; font-size: 0.9rem;">${escapeHtml(periode.nama)}</div>
            </td>
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.925rem;">${prog.title}</div>
            </td>
            <td>
                ${angkatanBadge}
            </td>
            <td style="text-align: center;">
                ${statusBadge}
            </td>
        `;


        const radio = tr.querySelector('input[name="program"]');

        tr.addEventListener('click', (e) => {
            if (e.target.tagName.toLowerCase() === 'input') return;
            if (!window.isEligibleCohort) {
                if (typeof window.showIneligibleCohortModal === 'function') window.showIneligibleCohortModal();
                return;
            }
            radio.checked = true;
            radio.dispatchEvent(new Event('change'));
        });

        radio.addEventListener('click', (e) => {
            if (!window.isEligibleCohort) {
                e.preventDefault();
                radio.checked = false;
                if (typeof window.showIneligibleCohortModal === 'function') window.showIneligibleCohortModal();
                return false;
            }
        });

        radio.addEventListener('change', () => {
            if (!window.isEligibleCohort) {
                radio.checked = false;
                if (typeof window.showIneligibleCohortModal === 'function') window.showIneligibleCohortModal();
                return;
            }
            tableBody.querySelectorAll('tr').forEach(r => r.classList.remove('selected'));
            tr.classList.add('selected');
        });

        tableBody.appendChild(tr);
    });
}



function setupPeminatanOptions(peminatanList) {
    const container = document.getElementById('peminatan-options');
    container.innerHTML = '';
    peminatanList.forEach(p => {
        container.innerHTML += `
            <label class="checkbox-item" style="padding: 12px 16px; border: 1px solid #E5E7EB; border-radius: 10px; transition: border-color 0.2s; user-select: none;">
                <input type="checkbox" name="peminatan" value="${p.id}" class="custom-cb"> 
                <span>${p.nama}</span>
            </label>`;
    });

    // Instant validation max 3 peminatan
    const checkboxes = container.querySelectorAll('input[name="peminatan"]');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            // Update border style for parent label
            cb.parentElement.style.borderColor = cb.checked ? 'var(--clr-biru-grid)' : '#E5E7EB';
            cb.parentElement.style.backgroundColor = cb.checked ? 'rgba(11, 61, 107, 0.03)' : 'transparent';
            
            const checkedCount = container.querySelectorAll('input[name="peminatan"]:checked').length;
            checkboxes.forEach(otherCb => {
                if (!otherCb.checked) {
                    otherCb.disabled = checkedCount >= 3;
                    otherCb.parentElement.style.opacity = (checkedCount >= 3) ? '0.5' : '1';
                    otherCb.parentElement.style.cursor = (checkedCount >= 3) ? 'not-allowed' : 'pointer';
                }
            });
        });
    });
}

// Pure SVG Icons (No Emoticons)
const ICONS = {
    error: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
    warning: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    success: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`,
    info: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`,
    close: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`
};

let activeToastTimeout = null;

function showToast(msg, type = 'error', title = null) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    // Clear previous toasts
    container.innerHTML = '';
    if (activeToastTimeout) clearTimeout(activeToastTimeout);

    const defaultTitles = {
        error: 'Peringatan Formulir',
        warning: 'Perhatian',
        success: 'Berhasil',
        info: 'Informasi'
    };

    const toast = document.createElement('div');
    toast.className = `toast-item toast-${type}`;
    toast.setAttribute('role', 'alert');

    toast.innerHTML = `
        <div class="toast-icon">
            ${ICONS[type] || ICONS.info}
        </div>
        <div class="toast-body">
            <div class="toast-title">${title || defaultTitles[type]}</div>
            <div class="toast-message">${msg}</div>
        </div>
        <button type="button" class="toast-close" aria-label="Tutup Peringatan">
            ${ICONS.close}
        </button>
        <div class="toast-progress"></div>
    `;

    const closeBtn = toast.querySelector('.toast-close');
    const dismiss = () => {
        toast.classList.add('toast-hiding');
        setTimeout(() => {
            if (toast.parentNode === container) {
                container.removeChild(toast);
            }
        }, 300);
    };

    closeBtn.addEventListener('click', dismiss);
    container.appendChild(toast);

    // Auto dismiss after 5 seconds
    activeToastTimeout = setTimeout(dismiss, 5000);
}

function scrollToElement(el) {
    if (!el) return;
    const yOffset = -120; // Safe clearance below sticky navbar
    const y = el.getBoundingClientRect().top + window.pageYOffset + yOffset;
    window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
}

function highlightInvalidField(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('input-invalid');
    scrollToElement(el);
    if (typeof el.focus === 'function') {
        el.focus({ preventScroll: true });
    }

    const clearHandler = () => {
        el.classList.remove('input-invalid');
        el.removeEventListener('input', clearHandler);
        el.removeEventListener('change', clearHandler);
    };
    el.addEventListener('input', clearHandler);
    el.addEventListener('change', clearHandler);
}

function showError(msg, targetId = null, title = null) {
    if (!msg) {
        errorBox.classList.add('hidden');
        errorBox.innerHTML = '';
        return;
    }

    // In-Card Alert Box with Clean SVG
    errorBox.className = 'alert alert-error';
    errorBox.innerHTML = `
        <div class="alert-icon">${ICONS.error}</div>
        <div class="alert-body">
            <div class="alert-title">${title || 'Periksa Kembali Formulir'}</div>
            <div style="font-size: 0.9rem;">${msg}</div>
        </div>
    `;
    errorBox.classList.remove('hidden');

    // Floating Toast Notification
    showToast(msg, 'error', title || 'Peringatan Formulir');

    // Smart Scroll & Highlight
    if (targetId) {
        highlightInvalidField(targetId);
    } else {
        scrollToElement(errorBox);
    }
}

function disableNav() {
    btnNext.disabled = true;
    btnPrev.disabled = true;
    btnSubmit.disabled = true;
}

// ----------------------------------------------------
// Navigation Logic
// ----------------------------------------------------

async function handleNext() {
    showError(null);

    // Validasi Form saat ini
    if (!validateStep(currentStep)) {
        return;
    }

    if (currentStep === 3) {
        // Dari Step 3 ke Step 4, simpan data dan load Unit
        saveFormData();
        const ok = await loadUnits();
        if (!ok) return;
    }

    if (currentStep === 4) {
        // Tidak bisa lanjut dengan tombol Next biasa jika dari Step 4, 
        // harus lewat tombol 'Pilih' di tabel unit.
        showError('Pilih salah satu unit pelaksana dari tabel untuk melanjutkan.', null, 'Pilih Unit Pelaksana');
        return;
    }

    currentStep++;
    updateUI();
}

function handlePrev() {
    showError(null);
    if (currentStep === 5 && formData.reservasi_id) {
        showModal('Batalkan Pilihan', 'Jika Anda kembali, pilihan unit Anda akan dibatalkan dan kuota akan dilepas. Yakin ingin kembali?', async () => {
            try {
                await fetch('/api/mahasiswa/batal_reservasi.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ reservasi_id: formData.reservasi_id })
                });
                
                if (reservasiTimerInterval) clearInterval(reservasiTimerInterval);
                document.getElementById('reservasi-timer').innerText = '00:00';
                formData.reservasi_id = null;
                formData.upp_id = null;
                formData.nama_unit_dipilih = null;
                
                currentStep--;
                updateUI();
                loadUnits();
            } catch (err) {
                showError('Gagal membatalkan reservasi.', null, 'Gagal Pembatalan');
            }
        });
        return;
    }

    if (currentStep > 1) {
        currentStep--;
        updateUI();
    }
}

function validateStep(step) {
    if (step === 1) {
        if (!window.isEligibleCohort) {
            if (typeof window.showIneligibleCohortModal === 'function') window.showIneligibleCohortModal();
            return false;
        }
        const prog = document.querySelector('input[name="program"]:checked');
        if (!prog) {
            showError('Silakan pilih salah satu program magang yang tersedia pada tabel.', 'program-options-table-body', 'Program Belum Dipilih');
            return false;
        }

    } else if (step === 2) {

        const fields = [
            { id: 'nama', label: 'Nama Lengkap' },
            { id: 'jenis_kelamin', label: 'Jenis Kelamin' },
            { id: 'no_hp', label: 'Nomor Handphone / WhatsApp' },
            { id: 'ipk', label: 'IPK Terakhir' },
            { id: 'jumlah_sks', label: 'Jumlah SKS' }
        ];

        for (let f of fields) {
            const el = document.getElementById(f.id);
            if (!el || !el.value.trim()) {
                showError(`Mohon lengkapi field ${f.label} pada Data Diri.`, f.id, `${f.label} Wajib Diisi`);
                return false;
            }
        }

        // Validasi Format No. HP / WhatsApp (Hanya angka, 10–14 digit, diawali 08 atau 628)
        const noHpVal = (document.getElementById('no_hp')?.value || '').trim();
        if (!/^[0-9]+$/.test(noHpVal)) {
            showError('Nomor Handphone / WhatsApp hanya boleh berisi angka (tanpa huruf atau simbol).', 'no_hp', 'Format Nomor HP Tidak Valid');
            return false;
        }
        if (noHpVal.length < 10 || noHpVal.length > 14) {
            showError('Nomor Handphone / WhatsApp harus terdiri dari 10 hingga 14 digit angka.', 'no_hp', 'Panjang Nomor HP Tidak Sesuai');
            return false;
        }
        if (!noHpVal.startsWith('08') && !noHpVal.startsWith('628')) {
            showError('Nomor Handphone / WhatsApp harus diawali dengan 08 (contoh: 081234567890).', 'no_hp', 'Awalan Nomor HP Tidak Sesuai');
            return false;
        }

        // Syarat Program 5 Bulan
        const selectedProg = document.querySelector('input[name="program"]:checked')?.value || formData.program;
        if (selectedProg === '5_bulan') {
            const ipkVal = parseFloat(document.getElementById('ipk').value);
            const sksVal = parseInt(document.getElementById('jumlah_sks').value, 10);
            if (isNaN(ipkVal) || ipkVal < minIpk5Bulan) {
                showError(`Program Magang 5 Bulan (KRS) mensyaratkan IPK minimal ${minIpk5Bulan.toFixed(2)}.`, 'ipk', 'Syarat IPK Belum Terpenuhi');
                return false;
            }
            if (isNaN(sksVal) || sksVal < minSks5Bulan) {
                showError(`Program Magang 5 Bulan (KRS) mensyaratkan minimal ${minSks5Bulan} SKS yang sudah ditempuh.`, 'jumlah_sks', 'Syarat SKS Belum Terpenuhi');
                return false;
            }
        }
        
        const checkedPem = document.querySelectorAll('input[name="peminatan"]:checked');
        if (checkedPem.length > 3) {
            showError('Maksimal memilih 3 bidang peminatan magang.', 'peminatan-options', 'Batas Peminatan');
            return false;
        }

        // Validasi Dokumen Persyaratan Wajib Sesuai Periode Aktif
        if (window.syaratTranskrip && !window.transkripUploadedPath) {
            showError('Berkas transkrip nilai (PDF) wajib diunggah sebelum melanjutkan ke tahap domisili.', 'transkrip-drop-zone', 'Transkrip Belum Diunggah');
            return false;
        }

        if (window.syaratCv && !window.cvUploadedPath) {
            showError('Berkas Curriculum Vitae / CV (PDF) wajib diunggah sebelum melanjutkan ke tahap domisili.', 'cv-drop-zone', 'CV Belum Diunggah');
            return false;
        }

        if (window.syaratPorto && !window.portoUploadedPath) {
            showError('Berkas Portofolio (PDF) wajib diunggah sebelum melanjutkan ke tahap domisili.', 'porto-drop-zone', 'Portofolio Belum Diunggah');
            return false;
        }
    } else if (step === 3) {
        if (!document.getElementById('lat').value.trim() || !document.getElementById('lng').value.trim()) {
            showError('Silakan tentukan titik koordinat tempat tinggal Anda pada peta domisili.', 'map-domisili', 'Titik Lokasi Wajib Ditandai');
            return false;
        }

        if (!document.getElementById('alamat_lengkap').value.trim()) {
            showError('Mohon lengkapi alamat lengkap tempat tinggal Anda.', 'alamat_lengkap', 'Alamat Wajib Diisi');
            return false;
        }

        const rtVal = (document.getElementById('rt')?.value || '').trim();
        if (!rtVal) {
            showError('Mohon isi nomor RT tempat tinggal Anda.', 'rt', 'RT Wajib Diisi');
            return false;
        }
        if (!/^[0-9]+$/.test(rtVal)) {
            showError('Nomor RT hanya boleh berisi angka (contoh: 01 atau 005).', 'rt', 'Format RT Tidak Valid');
            return false;
        }

        const rwVal = (document.getElementById('rw')?.value || '').trim();
        if (!rwVal) {
            showError('Mohon isi nomor RW tempat tinggal Anda.', 'rw', 'RW Wajib Diisi');
            return false;
        }
        if (!/^[0-9]+$/.test(rwVal)) {
            showError('Nomor RW hanya boleh berisi angka (contoh: 01 atau 005).', 'rw', 'Format RW Tidak Valid');
            return false;
        }

        const wilayahFields = [
            { id: 'provinsi', label: 'Provinsi' },
            { id: 'kota_kabupaten', label: 'Kota/Kabupaten' },
            { id: 'kecamatan', label: 'Kecamatan' },
            { id: 'kelurahan', label: 'Kelurahan' }
        ];

        for (let w of wilayahFields) {
            const val = document.getElementById(w.id)?.value.trim();
            if (!val) {
                showError(`Mohon pilih ${w.label} domisili Anda.`, w.id, `${w.label} Belum Dipilih`);
                return false;
            }
        }
    }
    return true;
}

function saveFormData() {
    const progRadio = document.querySelector('input[name="program"]:checked');
    if (progRadio) formData.program = progRadio.value;
    formData.nama = document.getElementById('nama').value;
    formData.jenis_kelamin = document.getElementById('jenis_kelamin').value;
    formData.ipk = document.getElementById('ipk').value;
    formData.jumlah_sks = document.getElementById('jumlah_sks').value;
    formData.no_hp = document.getElementById('no_hp').value;
    
    formData.lat = document.getElementById('lat').value;
    formData.lng = document.getElementById('lng').value;
    formData.rt = document.getElementById('rt').value;
    formData.rw = document.getElementById('rw').value;
    formData.kelurahan = document.getElementById('kelurahan').value;
    formData.kecamatan = document.getElementById('kecamatan').value;
    formData.kota_kabupaten = document.getElementById('kota_kabupaten').value;
    formData.provinsi = document.getElementById('provinsi').value;
    formData.alamat_lengkap = document.getElementById('alamat_lengkap').value;
    
    formData.peminatan = Array.from(document.querySelectorAll('input[name="peminatan"]:checked')).map(el => el.value);
    
    try {
        sessionStorage.setItem('magang_draft_form', JSON.stringify(formData));
    } catch (e) {}
}

function updateUI() {
    // Labels
    document.querySelectorAll('.step-indicator').forEach(el => {
        const s = parseInt(el.dataset.step);
        el.classList.remove('active', 'completed');
        if (s === currentStep) el.classList.add('active');
        else if (s < currentStep) el.classList.add('completed');
    });

    // Contents
    document.querySelectorAll('.step-content').forEach(el => {
        el.classList.remove('active');
    });
    document.getElementById(`step-${currentStep}`).classList.add('active');

    // Buttons
    btnPrev.classList.toggle('hidden', currentStep === 1);
    
    if (currentStep === 4) {
        // Step 4 next is triggered by table button
        btnNext.classList.add('hidden');
        btnSubmit.classList.add('hidden');
    } else if (currentStep === 5) {
        btnNext.classList.add('hidden');
        btnSubmit.classList.remove('hidden');
        populateResume();
    } else {
        btnNext.classList.remove('hidden');
        btnSubmit.classList.add('hidden');
        if (currentStep === 3) btnNext.innerText = 'Cari Unit Pelaksana';
        else btnNext.innerText = 'Lanjut';
    }

    if (currentStep === 3) {
        invalidateMapSize();
    }
}

let currentUnitsData = [];
let filteredUnitsData = [];
let unitCurrentPage = 1;
const unitPerPage = 10;

function renderUnitsTable(page = 1) {
    const tbody = document.getElementById('tbody-unit');
    const infoEl = document.getElementById('unit-page-info');
    const badgeEl = document.getElementById('unit-page-indicator');
    const prevBtn = document.getElementById('btn-unit-prev');
    const nextBtn = document.getElementById('btn-unit-next');

    if (!tbody) return;
    tbody.innerHTML = '';
    
    const total = filteredUnitsData ? filteredUnitsData.length : 0;
    const totalPages = Math.max(1, Math.ceil(total / unitPerPage));
    unitCurrentPage = Math.min(Math.max(1, page), totalPages);

    if (!filteredUnitsData || total === 0) {
        const mhsJur = (window.statusAuthData && window.statusAuthData.user && window.statusAuthData.user.jurusan) ? window.statusAuthData.user.jurusan : '';
        const jurMsg = mhsJur ? ` untuk Program Studi <strong>${escapeHtml(mhsJur)}</strong>` : '';
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 36px 20px; color: #64748b;">
                    <div style="max-width: 420px; margin: 0 auto;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 8px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <div style="font-weight: 700; color: #1e293b; font-size: 0.95rem; margin-bottom: 4px;">Tidak Ada Unit yang Cocok</div>
                        <div style="font-size: 0.825rem; color: #64748b; line-height: 1.4;">Belum ada unit/kantor magang yang membuka alokasi kuota${jurMsg} pada periode ini.</div>
                    </div>
                </td>
            </tr>
        `;
        if (infoEl) infoEl.innerText = 'Menampilkan 0 unit pelaksana';
        if (badgeEl) badgeEl.innerText = 'Hal 1 / 1';
        if (prevBtn) prevBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = true;
        return;
    }

    const offset = (unitCurrentPage - 1) * unitPerPage;
    const pageUnits = filteredUnitsData.slice(offset, offset + unitPerPage);

    pageUnits.forEach((u, idx) => {
        const tr = document.createElement('tr');
        const isFull = u.kuota_tersisa <= 0;
        if (isFull) tr.classList.add('row-full');
        
        let actionHtml = '';
        if (isFull) {
            actionHtml = `<span class="text-abu" style="font-weight: 600;">Penuh</span>`;
        } else {
            actionHtml = `<button type="button" class="btn btn-primary" style="padding: 0.35rem 0.9rem; font-size: 0.85rem; font-weight: 600;" onclick="window.pilihUnit(${u.upp_id}, '${escapeHtml(u.nama_unit).replace(/'/g, "\\'")}')">Pilih</button>`;
        }

        const noUrut = offset + idx + 1;

        tr.innerHTML = `
            <td data-label="No" style="text-align: center; font-weight: 600; color: #64748b;">${noUrut}</td>
            <td data-label="Unit Magang">
                <div>
                    <strong style="color: #0b3d6b;">${escapeHtml(u.nama_unit)}</strong>
                    ${u.nama_parent ? `<div style="font-size: 0.75rem; color: #64748b; font-weight: 600; margin-top: 1px;">Induk: ${escapeHtml(u.nama_parent)}</div>` : ''}
                    <span class="text-sm text-abu" style="display: block; margin-top: 2px;">${escapeHtml(u.alamat || '-')}</span>
                </div>
            </td>
            <td data-label="Jarak">${u.jarak !== null ? u.jarak + ' km' : '-'}</td>
            <td data-label="Peminatan"><span class="badge badge-gold">${u.kecocokan} Sesuai</span></td>
            <td data-label="Kuota Tersisa" class="table-monospace ${isFull ? 'text-abu' : 'text-hijau'}" style="text-align: right; font-weight: 700;">
                ${String(u.kuota_tersisa).padStart(2, '0')} / ${String(u.kuota_total).padStart(2, '0')}
            </td>
            <td data-label="Aksi" style="text-align: center;">${actionHtml}</td>
        `;
        tbody.appendChild(tr);
    });

    const start = offset + 1;
    const end = Math.min(offset + unitPerPage, total);

    if (infoEl) infoEl.innerText = `Menampilkan ${start}–${end} dari ${total} unit magang`;
    if (badgeEl) badgeEl.innerText = `Hal ${unitCurrentPage} / ${totalPages}`;
    if (prevBtn) prevBtn.disabled = (unitCurrentPage <= 1);
    if (nextBtn) nextBtn.disabled = (unitCurrentPage >= totalPages);
}

// ----------------------------------------------------
// Load Units (Step 4)
// ----------------------------------------------------
async function loadUnits() {
    const tbody = document.getElementById('tbody-unit');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px;">Memuat unit... <div class="spinner"></div></td></tr>';
    }
    
    try {
        const res = await fetch('/api/unit/list.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                lat: formData.lat,
                lng: formData.lng,
                periode_id: formData.periode_id,
                peminatan_ids: formData.peminatan
            })
        });
        
        const data = await res.json();
        if (!res.ok) {
            showError(data.error || 'Gagal memuat data unit pelaksana.', null, 'Gagal Memuat Unit');
            return false;
        }
        
        currentUnitsData = data.data || [];
        filteredUnitsData = currentUnitsData;
        unitCurrentPage = 1;
        const searchInput = document.getElementById('search-unit-input');
        if (searchInput) searchInput.value = '';
        
        renderUnitsTable(1);
        return true;
    } catch (err) {
        console.error(err);
        showError('Gagal memuat unit pelaksana. Periksa jaringan Anda.', null, 'Kesalahan Jaringan');
        return false;
    }
}

// Listener Pencarian Live Unit & Pagination Buttons
document.addEventListener('input', (e) => {
    if (e.target && e.target.id === 'search-unit-input') {
        const q = (e.target.value || '').toLowerCase().trim();
        if (!q) {
            filteredUnitsData = currentUnitsData;
        } else {
            filteredUnitsData = currentUnitsData.filter(u => 
                (u.nama_unit && u.nama_unit.toLowerCase().includes(q)) || 
                (u.alamat && u.alamat.toLowerCase().includes(q))
            );
        }
        unitCurrentPage = 1;
        renderUnitsTable(1);
    }
});

document.addEventListener('click', (e) => {
    if (e.target && (e.target.id === 'btn-unit-prev' || e.target.closest('#btn-unit-prev'))) {
        if (unitCurrentPage > 1) {
            renderUnitsTable(unitCurrentPage - 1);
        }
    } else if (e.target && (e.target.id === 'btn-unit-next' || e.target.closest('#btn-unit-next'))) {
        const total = filteredUnitsData ? filteredUnitsData.length : 0;
        const totalPages = Math.ceil(total / unitPerPage) || 1;
        if (unitCurrentPage < totalPages) {
            renderUnitsTable(unitCurrentPage + 1);
        }
    }
});

// Terpaksa di scope global karena dipanggil dari onclick atribut HTML string
window.pilihUnit = function(upp_id, nama_unit) {
    showModal('Konfirmasi Pilihan', `Anda akan memilih unit ${nama_unit}. Kuota akan ditahan selama batas waktu reservasi. Lanjutkan?`, async () => {
        showError(null);
        try {
            const res = await fetch('/api/mahasiswa/reservasi.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ upp_id: upp_id })
            });
            
            const data = await res.json();
            if (!res.ok) {
                showError(data.error || 'Gagal melakukan reservasi unit.', null, 'Reservasi Unit Gagal');
                loadUnits(); 
                return;
            }

            // Berhasil reservasi
            formData.upp_id = upp_id;
            formData.reservasi_id = data.data.reservasi_id;
            formData.nama_unit_dipilih = nama_unit;
            
            try {
                sessionStorage.setItem('magang_draft_form', JSON.stringify(formData));
            } catch (e) {}

            const expireMs = data.data.expired_at_ms || new Date(data.data.expired_at).getTime();
            startTimer(expireMs);
            
            // Pindah ke step 5
            currentStep = 5;
            updateUI();

        } catch (err) {
            showError('Terjadi kesalahan jaringan saat reservasi unit.', null, 'Kesalahan Jaringan');
        }
    });
};

// ----------------------------------------------------
// Timer & Resume (Step 5)
// ----------------------------------------------------
function populateResume() {
    document.getElementById('resume-unit').innerText = formData.nama_unit_dipilih || '-';
    const progText = formData.program === '1_bulan' ? 'Magang 1 Bulan' : 'Magang 5 Bulan';
    document.getElementById('resume-program').innerText = `${progText} (${formData.periode_nama})`;
    document.getElementById('resume-nama').innerText = formData.nama;
    document.getElementById('resume-nim').innerText = document.getElementById('nim').value;
    document.getElementById('resume-hp').innerText = formData.no_hp;
    document.getElementById('resume-alamat').innerText = formData.alamat_lengkap + `, RT ${formData.rt}/RW ${formData.rw}, ${formData.kelurahan}, ${formData.kecamatan}, ${formData.kota_kabupaten}, ${formData.provinsi}`;
    
    const resumeTranskripEl = document.getElementById('resume-transkrip-name');
    if (resumeTranskripEl) {
        resumeTranskripEl.innerText = window.transkripUploadedFileName || 'Dokumen PDF Terverifikasi';
    }
    const resumeCvEl = document.getElementById('resume-cv-name');
    if (resumeCvEl) {
        resumeCvEl.innerText = window.cvUploadedFileName || 'Dokumen PDF Terverifikasi';
    }
    const resumePortoEl = document.getElementById('resume-porto-name');
    if (resumePortoEl) {
        resumePortoEl.innerText = window.portoUploadedFileName || 'Dokumen PDF Terverifikasi';
    }
}

function startTimer(expireTimeMs) {
    if (reservasiTimerInterval) clearInterval(reservasiTimerInterval);
    
    const el = document.getElementById('reservasi-timer');
    
    reservasiTimerInterval = setInterval(() => {
        const now = new Date().getTime();
        const diff = expireTimeMs - now;
        
        if (diff <= 0) {
            clearInterval(reservasiTimerInterval);
            el.innerText = '00:00';
            handleExpiredReservation();
        } else {
            const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const s = Math.floor((diff % (1000 * 60)) / 1000);
            el.innerText = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        }
    }, 1000);
}

async function handleExpiredReservation() {
    const expiredResId = formData.reservasi_id;
    if (reservasiTimerInterval) clearInterval(reservasiTimerInterval);
    
    formData.reservasi_id = null;
    formData.upp_id = null;
    formData.nama_unit_dipilih = null;
    try { sessionStorage.removeItem('magang_draft_form'); } catch(e){}

    if (expiredResId) {
        try {
            await fetch('/api/mahasiswa/batal_reservasi.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ reservasi_id: expiredResId })
            });
        } catch (e) {
            console.warn('[daftar.js] Gagal auto-cancel expired reservation:', e);
        }
    }

    showToast('Batas waktu reservasi unit telah habis. Silakan pilih kembali unit yang tersedia.', 'warning', 'Waktu Reservasi Habis');
    showError('Batas waktu konfirmasi reservasi unit telah habis. Silakan pilih kembali unit pelaksana.', null, 'Waktu Reservasi Habis');
    currentStep = 4;
    loadUnits();
    updateUI();
}

async function handleSubmit() {
    showError(null);

    if (window.syaratTranskrip && !window.transkripUploadedPath) {
        showError('Berkas transkrip nilai belum diunggah. Silakan kembali ke tahap Data Diri.', null, 'Transkrip Wajib Diunggah');
        btnSubmit.disabled = false;
        return;
    }

    if (window.syaratCv && !window.cvUploadedPath) {
        showError('Berkas CV belum diunggah. Silakan kembali ke tahap Data Diri.', null, 'CV Wajib Diunggah');
        btnSubmit.disabled = false;
        return;
    }

    if (window.syaratPorto && !window.portoUploadedPath) {
        showError('Berkas Portofolio belum diunggah. Silakan kembali ke tahap Data Diri.', null, 'Portofolio Wajib Diunggah');
        btnSubmit.disabled = false;
        return;
    }

    btnSubmit.disabled = true;
    btnSubmit.innerText = 'Menyimpan...';

    try {
        const payload = {
            ...formData,
            transkrip_path: window.transkripUploadedPath || null,
            cv_path: window.cvUploadedPath || null,
            porto_path: window.portoUploadedPath || null
        };

        const res = await fetch('/api/mahasiswa/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(payload)
        });
        
        let data;
        const resText = await res.text();
        try {
            data = JSON.parse(resText);
        } catch (jsonErr) {
            console.error('[submit.php] Non-JSON response:', resText);
            showError('Terjadi kesalahan pada respons server. Silakan coba beberapa saat lagi.', null, 'Kesalahan Server');
            btnSubmit.disabled = false;
            btnSubmit.innerText = 'Kirim Pendaftaran';
            return;
        }
        
        if (!res.ok) {
            showError(data.error || 'Gagal mengirim pendaftaran.', null, 'Pengiriman Gagal');
            btnSubmit.disabled = false;
            btnSubmit.innerText = 'Kirim Pendaftaran';
            return;
        }

        // Sukses
        if (reservasiTimerInterval) clearInterval(reservasiTimerInterval);
        try { sessionStorage.removeItem('magang_draft_form'); } catch(e){}
        window.location.href = '/status.html';
        
    } catch (err) {
        console.error(err);
        showError('Gagal mengirim pendaftaran. Periksa koneksi internet.', null, 'Kesalahan Jaringan');
        btnSubmit.disabled = false;
        btnSubmit.innerText = 'Kirim Pendaftaran';
    }
}

// ----------------------------------------------------
// Setup Dokumen Persyaratan Berdasarkan Periode Aktif
// ----------------------------------------------------
function setupDokumenSyarat(periode) {
    if (!periode) return;
    window.syaratTranskrip = (periode.syarat_transkrip !== false && periode.syarat_transkrip != 0);
    window.syaratCv = (periode.syarat_cv === true || periode.syarat_cv == 1);
    window.syaratPorto = (periode.syarat_porto === true || periode.syarat_porto == 1);

    const wrapTranskrip = document.getElementById('wrapper-upload-transkrip');
    const wrapCv = document.getElementById('wrapper-upload-cv');
    const wrapPorto = document.getElementById('wrapper-upload-porto');

    const resTranskrip = document.getElementById('resume-group-transkrip');
    const resCv = document.getElementById('resume-group-cv');
    const resPorto = document.getElementById('resume-group-porto');

    if (wrapTranskrip) wrapTranskrip.classList.toggle('hidden', !window.syaratTranskrip);
    if (wrapCv) wrapCv.classList.toggle('hidden', !window.syaratCv);
    if (wrapPorto) wrapPorto.classList.toggle('hidden', !window.syaratPorto);

    if (resTranskrip) resTranskrip.classList.toggle('hidden', !window.syaratTranskrip);
    if (resCv) resCv.classList.toggle('hidden', !window.syaratCv);
    if (resPorto) resPorto.classList.toggle('hidden', !window.syaratPorto);

    if (window.syaratTranskrip) initTranskripUpload();
    if (window.syaratCv) initCvUpload();
    if (window.syaratPorto) initPortoUpload();
}

// ----------------------------------------------------
// Transkrip Nilai Upload Handler (Step 2)
// ----------------------------------------------------
function initTranskripUpload() {
    const fileInput = document.getElementById('transkrip-file-input');
    const dropZone = document.getElementById('transkrip-drop-zone');
    const stateIdle = document.getElementById('transkrip-state-idle');
    const stateUploading = document.getElementById('transkrip-state-uploading');
    const stateSuccess = document.getElementById('transkrip-state-success');
    const stateError = document.getElementById('transkrip-state-error');
    const successFilename = document.getElementById('transkrip-success-filename');
    const successSize = document.getElementById('transkrip-success-size');
    const errorText = document.getElementById('transkrip-error-text');
    const btnGanti = document.getElementById('btn-ganti-transkrip');
    const btnRetry = document.getElementById('btn-retry-transkrip');

    if (!fileInput || !dropZone) return;

    function setTranskripState(state) {
        [stateIdle, stateUploading, stateSuccess, stateError].forEach(el => {
            if (el) el.classList.add('hidden');
        });
        if (state === 'idle' && stateIdle) stateIdle.classList.remove('hidden');
        else if (state === 'uploading' && stateUploading) stateUploading.classList.remove('hidden');
        else if (state === 'success' && stateSuccess) stateSuccess.classList.remove('hidden');
        else if (state === 'error' && stateError) stateError.classList.remove('hidden');
    }

    async function handleFileUpload(file) {
        if (!file) return;

        // Validasi cepat client-side
        if (!file.name.toLowerCase().endsWith('.pdf') && file.type !== 'application/pdf') {
            if (errorText) errorText.innerText = 'Format berkas harus PDF (.pdf).';
            setTranskripState('error');
            return;
        }

        const maxBytes = 512 * 1024;
        if (file.size > maxBytes) {
            if (errorText) errorText.innerText = `Ukuran berkas (${(file.size / 1024).toFixed(0)} KB) melebihi batas 512 KB.`;
            setTranskripState('error');
            return;
        }

        setTranskripState('uploading');

        const uploadFormData = new FormData();
        uploadFormData.append('transkrip', file);

        try {
            const res = await fetch('/api/mahasiswa/transkrip/upload.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: uploadFormData
            });

            const data = await res.json();

            if (!res.ok || !data.ok) {
                if (errorText) errorText.innerText = data.error || 'Gagal mengunggah transkrip nilai.';
                setTranskripState('error');
                window.transkripUploadedPath = null;
                window.transkripUploadedFileName = null;
                return;
            }

            window.transkripUploadedPath = data.path;
            window.transkripUploadedFileName = file.name;

            if (successFilename) successFilename.innerText = file.name;
            if (successSize) successSize.innerText = `${(file.size / 1024).toFixed(0)} KB — Dokumen Valid`;
            setTranskripState('success');

        } catch (err) {
            console.error('[transkrip-upload] Network error:', err);
            if (errorText) errorText.innerText = 'Koneksi internet bermasalah saat mengunggah berkas.';
            setTranskripState('error');
            window.transkripUploadedPath = null;
            window.transkripUploadedFileName = null;
        }
    }

    // Native file input change
    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) {
            handleFileUpload(fileInput.files[0]);
        }
    });

    // Drag & Drop
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#0284c7';
            stateIdle.style.background = '#e0f2fe';
        }
    });

    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#cbd5e1';
            stateIdle.style.background = '#f8fafc';
        }
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#cbd5e1';
            stateIdle.style.background = '#f8fafc';
        }
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
            handleFileUpload(e.dataTransfer.files[0]);
        }
    });

    // Ganti File & Coba Lagi
    if (btnGanti) {
        btnGanti.addEventListener('click', () => {
            fileInput.value = '';
            window.transkripUploadedPath = null;
            window.transkripUploadedFileName = null;
            setTranskripState('idle');
        });
    }

    if (btnRetry) {
        btnRetry.addEventListener('click', () => {
            fileInput.value = '';
            setTranskripState('idle');
        });
    }

    setTranskripState('idle');
}

// ----------------------------------------------------
// CV Upload Handler (Step 2 - Maks 2 MB)
// ----------------------------------------------------
function initCvUpload() {
    const fileInput = document.getElementById('cv-file-input');
    const dropZone = document.getElementById('cv-drop-zone');
    const stateIdle = document.getElementById('cv-state-idle');
    const stateUploading = document.getElementById('cv-state-uploading');
    const stateSuccess = document.getElementById('cv-state-success');
    const stateError = document.getElementById('cv-state-error');
    const successFilename = document.getElementById('cv-success-filename');
    const successSize = document.getElementById('cv-success-size');
    const errorText = document.getElementById('cv-error-text');
    const btnGanti = document.getElementById('btn-ganti-cv');
    const btnRetry = document.getElementById('btn-retry-cv');

    if (!fileInput || !dropZone) return;

    function setCvState(state) {
        [stateIdle, stateUploading, stateSuccess, stateError].forEach(el => {
            if (el) el.classList.add('hidden');
        });
        if (state === 'idle' && stateIdle) stateIdle.classList.remove('hidden');
        else if (state === 'uploading' && stateUploading) stateUploading.classList.remove('hidden');
        else if (state === 'success' && stateSuccess) stateSuccess.classList.remove('hidden');
        else if (state === 'error' && stateError) stateError.classList.remove('hidden');
    }

    async function handleFileUpload(file) {
        if (!file) return;

        if (!file.name.toLowerCase().endsWith('.pdf') && file.type !== 'application/pdf') {
            if (errorText) errorText.innerText = 'Format berkas harus PDF (.pdf).';
            setCvState('error');
            return;
        }

        const maxBytes = 2 * 1024 * 1024; // 2 MB
        if (file.size > maxBytes) {
            if (errorText) errorText.innerText = `Ukuran berkas (${(file.size / (1024 * 1024)).toFixed(1)} MB) melebihi batas 2 MB.`;
            setCvState('error');
            return;
        }

        setCvState('uploading');

        const uploadFormData = new FormData();
        uploadFormData.append('cv', file);

        try {
            const res = await fetch('/api/mahasiswa/cv/upload.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: uploadFormData
            });

            const data = await res.json();

            if (!res.ok || !data.ok) {
                if (errorText) errorText.innerText = data.error || 'Gagal mengunggah CV.';
                setCvState('error');
                window.cvUploadedPath = null;
                window.cvUploadedFileName = null;
                return;
            }

            window.cvUploadedPath = data.path;
            window.cvUploadedFileName = file.name;

            if (successFilename) successFilename.innerText = file.name;
            if (successSize) successSize.innerText = `${(file.size / 1024).toFixed(0)} KB — Dokumen Valid`;
            setCvState('success');

        } catch (err) {
            console.error('[cv-upload] Network error:', err);
            if (errorText) errorText.innerText = 'Koneksi internet bermasalah saat mengunggah CV.';
            setCvState('error');
            window.cvUploadedPath = null;
            window.cvUploadedFileName = null;
        }
    }

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) {
            handleFileUpload(fileInput.files[0]);
        }
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#16a34a';
            stateIdle.style.background = '#dcfce7';
        }
    });

    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#cbd5e1';
            stateIdle.style.background = '#f8fafc';
        }
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#cbd5e1';
            stateIdle.style.background = '#f8fafc';
        }
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
            handleFileUpload(e.dataTransfer.files[0]);
        }
    });

    if (btnGanti) {
        btnGanti.addEventListener('click', () => {
            fileInput.value = '';
            window.cvUploadedPath = null;
            window.cvUploadedFileName = null;
            setCvState('idle');
        });
    }

    if (btnRetry) {
        btnRetry.addEventListener('click', () => {
            fileInput.value = '';
            setCvState('idle');
        });
    }

    setCvState('idle');
}

// ----------------------------------------------------
// Portofolio Upload Handler (Step 2 - Maks 5 MB)
// ----------------------------------------------------
function initPortoUpload() {
    const fileInput = document.getElementById('porto-file-input');
    const dropZone = document.getElementById('porto-drop-zone');
    const stateIdle = document.getElementById('porto-state-idle');
    const stateUploading = document.getElementById('porto-state-uploading');
    const stateSuccess = document.getElementById('porto-state-success');
    const stateError = document.getElementById('porto-state-error');
    const successFilename = document.getElementById('porto-success-filename');
    const successSize = document.getElementById('porto-success-size');
    const errorText = document.getElementById('porto-error-text');
    const btnGanti = document.getElementById('btn-ganti-porto');
    const btnRetry = document.getElementById('btn-retry-porto');

    if (!fileInput || !dropZone) return;

    function setPortoState(state) {
        [stateIdle, stateUploading, stateSuccess, stateError].forEach(el => {
            if (el) el.classList.add('hidden');
        });
        if (state === 'idle' && stateIdle) stateIdle.classList.remove('hidden');
        else if (state === 'uploading' && stateUploading) stateUploading.classList.remove('hidden');
        else if (state === 'success' && stateSuccess) stateSuccess.classList.remove('hidden');
        else if (state === 'error' && stateError) stateError.classList.remove('hidden');
    }

    async function handleFileUpload(file) {
        if (!file) return;

        if (!file.name.toLowerCase().endsWith('.pdf') && file.type !== 'application/pdf') {
            if (errorText) errorText.innerText = 'Format berkas harus PDF (.pdf).';
            setPortoState('error');
            return;
        }

        const maxBytes = 5 * 1024 * 1024; // 5 MB
        if (file.size > maxBytes) {
            if (errorText) errorText.innerText = `Ukuran berkas (${(file.size / (1024 * 1024)).toFixed(1)} MB) melebihi batas 5 MB.`;
            setPortoState('error');
            return;
        }

        setPortoState('uploading');

        const uploadFormData = new FormData();
        uploadFormData.append('porto', file);

        try {
            const res = await fetch('/api/mahasiswa/porto/upload.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: uploadFormData
            });

            const data = await res.json();

            if (!res.ok || !data.ok) {
                if (errorText) errorText.innerText = data.error || 'Gagal mengunggah Portofolio.';
                setPortoState('error');
                window.portoUploadedPath = null;
                window.portoUploadedFileName = null;
                return;
            }

            window.portoUploadedPath = data.path;
            window.portoUploadedFileName = file.name;

            if (successFilename) successFilename.innerText = file.name;
            if (successSize) successSize.innerText = `${(file.size / 1024).toFixed(0)} KB — Dokumen Valid`;
            setPortoState('success');

        } catch (err) {
            console.error('[porto-upload] Network error:', err);
            if (errorText) errorText.innerText = 'Koneksi internet bermasalah saat mengunggah Portofolio.';
            setPortoState('error');
            window.portoUploadedPath = null;
            window.portoUploadedFileName = null;
        }
    }

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) {
            handleFileUpload(fileInput.files[0]);
        }
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#ca8a04';
            stateIdle.style.background = '#fef9c3';
        }
    });

    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#cbd5e1';
            stateIdle.style.background = '#f8fafc';
        }
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (stateIdle) {
            stateIdle.style.borderColor = '#cbd5e1';
            stateIdle.style.background = '#f8fafc';
        }
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
            handleFileUpload(e.dataTransfer.files[0]);
        }
    });

    if (btnGanti) {
        btnGanti.addEventListener('click', () => {
            fileInput.value = '';
            window.portoUploadedPath = null;
            window.portoUploadedFileName = null;
            setPortoState('idle');
        });
    }

    if (btnRetry) {
        btnRetry.addEventListener('click', () => {
            fileInput.value = '';
            setPortoState('idle');
        });
    }

    setPortoState('idle');
}


// ----------------------------------------------------
// Modal Utility
// ----------------------------------------------------
function showModal(title, desc, onConfirm) {
    const modal = document.getElementById('confirm-modal');
    document.getElementById('modal-title').innerText = title;
    document.getElementById('modal-desc').innerText = desc;
    modal.classList.remove('hidden');

    const btnCancel = document.getElementById('modal-btn-cancel');
    const btnConfirm = document.getElementById('modal-btn-confirm');
    
    // Clean up old listeners by replacing nodes
    const newCancel = btnCancel.cloneNode(true);
    const newConfirm = btnConfirm.cloneNode(true);
    btnCancel.parentNode.replaceChild(newCancel, btnCancel);
    btnConfirm.parentNode.replaceChild(newConfirm, btnConfirm);

    newCancel.addEventListener('click', () => {
        modal.classList.add('hidden');
    });

    newConfirm.addEventListener('click', async () => {
        const oldText = newConfirm.innerText;
        newConfirm.innerText = 'Memproses...';
        newConfirm.disabled = true;
        
        await onConfirm();
        
        modal.classList.add('hidden');
        newConfirm.innerText = oldText;
        newConfirm.disabled = false;
    });
}

// Global real logout function
window.logout = async function() {
    try {
        await fetch('/api/auth/logout.php', { method: 'POST', credentials: 'same-origin' });
    } catch (err) {
        console.error('Logout error:', err);
    } finally {
        sessionStorage.clear();
        localStorage.clear();
        window.location.replace('/login.html');
    }
};

// --- API Wilayah Indonesia 2025 (Kepmendagri) Dropdowns ---
async function initWilayahDropdowns(prefillData = null) {
    const provSelect = document.getElementById('provinsi');
    const kotaSelect = document.getElementById('kota_kabupaten');
    const kecSelect = document.getElementById('kecamatan');
    const kelSelect = document.getElementById('kelurahan');

    if (!provSelect) return;

    // Load Provinces
    try {
        const res = await fetch('/api/wilayah.php?endpoint=provinces&limit=100');
        const json = await res.json();
        if (json.status === 'success' && json.data) {
            provSelect.innerHTML = '<option value="">Pilih Provinsi...</option>';
            json.data.sort((a, b) => a.name.localeCompare(b.name)).forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.name;
                opt.dataset.code = p.code;
                opt.innerText = p.name;
                provSelect.appendChild(opt);
            });

            // If we have prefill data, select and trigger change cascade
            if (prefillData && prefillData.provinsi) {
                provSelect.value = prefillData.provinsi;
                await triggerProvinceChange(prefillData);
            }
        }
    } catch (e) {
        console.error('Gagal memuat data provinsi:', e);
    }

    async function triggerProvinceChange(prefill = null) {
        const selectedOpt = provSelect.options[provSelect.selectedIndex];
        const code = selectedOpt ? selectedOpt.dataset.code : '';

        kotaSelect.innerHTML = '<option value="">Pilih Kota/Kabupaten...</option>';
        kotaSelect.disabled = true;
        kecSelect.innerHTML = '<option value="">Pilih Kecamatan...</option>';
        kecSelect.disabled = true;
        kelSelect.innerHTML = '<option value="">Pilih Kelurahan...</option>';
        kelSelect.disabled = true;

        if (!code) return;

        try {
            const res = await fetch(`/api/wilayah.php?endpoint=regencies&param=${code}&limit=200`);
            const json = await res.json();
            if (json.status === 'success' && json.data) {
                kotaSelect.disabled = false;
                json.data.sort((a, b) => a.name.localeCompare(b.name)).forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.name;
                    opt.dataset.code = r.code;
                    opt.innerText = r.name;
                    kotaSelect.appendChild(opt);
                });

                if (prefill && prefill.kota_kabupaten) {
                    kotaSelect.value = prefill.kota_kabupaten;
                    await triggerKotaChange(prefill);
                }
            }
        } catch (e) {
            console.error('Gagal memuat data kota:', e);
        }
    }

    async function triggerKotaChange(prefill = null) {
        const selectedOpt = kotaSelect.options[kotaSelect.selectedIndex];
        const code = selectedOpt ? selectedOpt.dataset.code : '';

        kecSelect.innerHTML = '<option value="">Pilih Kecamatan...</option>';
        kecSelect.disabled = true;
        kelSelect.innerHTML = '<option value="">Pilih Kelurahan...</option>';
        kelSelect.disabled = true;

        if (!code) return;

        try {
            const res = await fetch(`/api/wilayah.php?endpoint=districts&param=${code}&limit=200`);
            const json = await res.json();
            if (json.status === 'success' && json.data) {
                kecSelect.disabled = false;
                json.data.sort((a, b) => a.name.localeCompare(b.name)).forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d.name;
                    opt.dataset.code = d.code;
                    opt.innerText = d.name;
                    kecSelect.appendChild(opt);
                });

                if (prefill && prefill.kecamatan) {
                    kecSelect.value = prefill.kecamatan;
                    await triggerKecamatanChange(prefill);
                }
            }
        } catch (e) {
            console.error('Gagal memuat data kecamatan:', e);
        }
    }

    async function triggerKecamatanChange(prefill = null) {
        const selectedOpt = kecSelect.options[kecSelect.selectedIndex];
        const code = selectedOpt ? selectedOpt.dataset.code : '';

        kelSelect.innerHTML = '<option value="">Pilih Kelurahan...</option>';
        kelSelect.disabled = true;

        if (!code) return;

        try {
            const res = await fetch(`/api/wilayah.php?endpoint=villages&param=${code}&limit=300`);
            const json = await res.json();
            if (json.status === 'success' && json.data) {
                kelSelect.disabled = false;
                json.data.sort((a, b) => a.name.localeCompare(b.name)).forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = v.name;
                    opt.innerText = v.name;
                    kelSelect.appendChild(opt);
                });

                if (prefill && prefill.kelurahan) {
                    kelSelect.value = prefill.kelurahan;
                }
            }
        } catch (e) {
            console.error('Gagal memuat data kelurahan:', e);
        }
    }

    provSelect.addEventListener('change', () => triggerProvinceChange());
    kotaSelect.addEventListener('change', () => triggerKotaChange());
    kecSelect.addEventListener('change', () => triggerKecamatanChange());
}
