let csrfToken = null;
let minIpk5Bulan = 3.00;
let minSks5Bulan = 110;
import { initMap, searchLocation, invalidateMapSize } from './map.js';

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

        if (statusData.is_eligible_angkatan === false) {
            const modal = document.getElementById('modalIneligibleAngkatan');
            const textEligible = document.getElementById('textModalEligibleAngkatan');
            const textMhs = document.getElementById('textModalMhsAngkatan');
            if (textEligible) textEligible.innerText = statusData.angkatan_eligible || '-';
            if (textMhs) textMhs.innerText = statusData.mhs_angkatan || '-';

            if (modal) {
                modal.classList.remove('hidden');
            } else {
                alert(`Mohon maaf, pendaftaran magang periode saat ini hanya dibuka untuk mahasiswa Angkatan (${statusData.angkatan_eligible}).\n\nAngkatan Anda (${statusData.mhs_angkatan}) belum / tidak diizinkan mendaftar.`);
                window.location.href = '/index.html';
            }
            return;
        }

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
            showError(periodeData.error || 'Gagal memuat periode pendaftaran.');
            disableNav();
            return;
        }
        formData.periode_id = periodeData.periode.id;
        formData.periode_nama = periodeData.periode.nama;
        setupProgramOptions(periodeData.periode);

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

        // Navigasi Event Listener
        btnNext.addEventListener('click', handleNext);
        btnPrev.addEventListener('click', handlePrev);
        btnSubmit.addEventListener('click', handleSubmit);

        // Cek jika ada reservasi aktif yang belum kadaluarsa (mis. saat refresh halaman)
        if (statusData.active_reservasi && statusData.active_reservasi.reservasi_id) {
            const activeRes = statusData.active_reservasi;
            const expireMs = new Date(activeRes.expired_at).getTime();
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
    const container = document.getElementById('program-options');
    container.innerHTML = '';
    
    const getOptionHTML = (val, label) => `
        <label class="checkbox-item program-radio" style="padding: 16px; border: 1px solid #E5E7EB; border-radius: 12px; transition: all 0.2s; user-select: none; display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
            <input type="radio" name="program" value="${val}" class="custom-radio" required> 
            <span style="font-weight: 600; color: #111827;">${label}</span>
        </label>`;

    if (periode.program_1_bulan) {
        container.innerHTML += getOptionHTML("1_bulan", `Magang 1 Bulan (${periode.nama})`);
    }
    if (periode.program_5_bulan) {
        container.innerHTML += getOptionHTML("5_bulan", `Magang 5 Bulan / KRS (${periode.nama})`);
    }

    // Add visual toggling
    container.querySelectorAll('input[name="program"]').forEach(radio => {
        radio.addEventListener('change', () => {
            container.querySelectorAll('.program-radio').forEach(lbl => {
                lbl.style.borderColor = '#E5E7EB';
                lbl.style.backgroundColor = 'transparent';
                lbl.style.boxShadow = 'none';
            });
            if (radio.checked) {
                radio.parentElement.style.borderColor = 'var(--clr-biru-grid)';
                radio.parentElement.style.backgroundColor = 'rgba(11, 61, 107, 0.03)';
                radio.parentElement.style.boxShadow = '0 2px 8px rgba(11, 61, 107, 0.05)';
            }
        });
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
        const prog = document.querySelector('input[name="program"]:checked');
        if (!prog) {
            showError('Silakan pilih salah satu program magang yang tersedia.', 'program-options', 'Program Belum Dipilih');
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
    } else if (step === 3) {
        if (!document.getElementById('lat').value.trim() || !document.getElementById('lng').value.trim()) {
            showError('Silakan tentukan titik koordinat tempat tinggal Anda pada peta domisili.', 'map-domisili', 'Titik Lokasi Wajib Ditandai');
            return false;
        }

        if (!document.getElementById('alamat_lengkap').value.trim()) {
            showError('Mohon lengkapi alamat lengkap tempat tinggal Anda.', 'alamat_lengkap', 'Alamat Wajib Diisi');
            return false;
        }

        if (!document.getElementById('rt').value.trim()) {
            showError('Mohon isi nomor RT tempat tinggal Anda.', 'rt', 'RT Wajib Diisi');
            return false;
        }

        if (!document.getElementById('rw').value.trim()) {
            showError('Mohon isi nomor RW tempat tinggal Anda.', 'rw', 'RW Wajib Diisi');
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

function renderUnitsTable(units) {
    const tbody = document.getElementById('tbody-unit');
    if (!tbody) return;
    tbody.innerHTML = '';
    
    if (!units || units.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 24px; color: #6B7280;">Tidak ada unit pelaksana yang cocok.</td></tr>';
        return;
    }

    units.forEach(u => {
        const tr = document.createElement('tr');
        const isFull = u.kuota_tersisa <= 0;
        if (isFull) tr.classList.add('row-full');
        
        let actionHtml = '';
        if (isFull) {
            actionHtml = `<span class="text-abu">Penuh</span>`;
        } else {
            actionHtml = `<button type="button" class="btn btn-primary" style="padding: 0.3rem 0.8rem; font-size: 0.875rem;" onclick="window.pilihUnit(${u.upp_id}, '${u.nama_unit.replace(/'/g, "\\'")}')">Pilih</button>`;
        }

        tr.innerHTML = `
            <td data-label="Unit Pelaksana">
                <div>
                    <strong>${u.nama_unit}</strong><br>
                    <span class="text-sm text-abu">${u.alamat || '-'}</span>
                </div>
            </td>
            <td data-label="Jarak">${u.jarak !== null ? u.jarak + ' km' : '-'}</td>
            <td data-label="Peminatan"><span class="badge badge-gold">${u.kecocokan} Sesuai</span></td>
            <td data-label="Kuota Tersisa" class="table-monospace ${isFull ? 'text-abu' : 'text-hijau'}">
                ${String(u.kuota_tersisa).padStart(2, '0')} / ${String(u.kuota_total).padStart(2, '0')}
            </td>
            <td data-label="Aksi">${actionHtml}</td>
        `;
        tbody.appendChild(tr);
    });
}

// ----------------------------------------------------
// Load Units (Step 4)
// ----------------------------------------------------
async function loadUnits() {
    const tbody = document.getElementById('tbody-unit');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 20px;">Memuat unit... <div class="spinner"></div></td></tr>';
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
        const searchInput = document.getElementById('search-unit-input');
        if (searchInput) searchInput.value = '';
        
        renderUnitsTable(currentUnitsData);
        return true;
    } catch (err) {
        console.error(err);
        showError('Gagal memuat unit pelaksana. Periksa jaringan Anda.', null, 'Kesalahan Jaringan');
        return false;
    }
}

// Listener Pencarian Live Unit
document.addEventListener('input', (e) => {
    if (e.target && e.target.id === 'search-unit-input') {
        const q = (e.target.value || '').toLowerCase().trim();
        if (!q) {
            renderUnitsTable(currentUnitsData);
            return;
        }
        const filtered = currentUnitsData.filter(u => 
            (u.nama_unit && u.nama_unit.toLowerCase().includes(q)) || 
            (u.alamat && u.alamat.toLowerCase().includes(q))
        );
        renderUnitsTable(filtered);
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

            startTimer(new Date(data.data.expired_at).getTime());
            
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

function handleExpiredReservation() {
    showToast('Batas waktu reservasi unit telah habis. Silakan pilih kembali unit yang tersedia.', 'warning', 'Waktu Reservasi Habis');
    showError('Batas waktu konfirmasi reservasi unit telah habis. Silakan pilih kembali unit pelaksana.', null, 'Waktu Reservasi Habis');
    currentStep = 4;
    formData.reservasi_id = null;
    formData.upp_id = null;
    formData.nama_unit_dipilih = null;
    try { sessionStorage.removeItem('magang_draft_form'); } catch(e){}
    loadUnits();
    updateUI();
}

async function handleSubmit() {
    showError(null);
    btnSubmit.disabled = true;
    btnSubmit.innerText = 'Menyimpan...';

    try {
        const res = await fetch('/api/mahasiswa/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(formData)
        });
        
        const data = await res.json();
        
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
