/**
 * public/js/admin/surat-mahasiswa.js
 * Controller untuk Halaman Pengaturan & Penerbitan Surat Pengantar Mahasiswa Per Unit INTERN ITPLN
 */

import { showAdminAlert, showAdminConfirm } from '/js/admin/common.js';

function escapeHtml(unsafe) {
    if (unsafe === null || unsafe === undefined) return '';
    return String(unsafe)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

let csrfToken = null;
let currentPeriodeId = null;
let allPeriodeList = [];
let currentConfig = null;
let currentUnits = [];
let currentSearchQuery = '';
let currentPageSurat = 1;
const pageSizeSurat = 25;

document.addEventListener('DOMContentLoaded', () => {
    initSuratMahasiswa();
});

async function initSuratMahasiswa() {
    // 1. Ambil CSRF Token & Auth
    try {
        const csrfRes = await fetch('/api/admin/status.php');
        const csrfData = await csrfRes.json();
        if (csrfData && csrfData.csrf_token) {
            csrfToken = csrfData.csrf_token;
        }
    } catch (e) {
        console.warn('[surat-mahasiswa.js] Gagal memuat CSRF token:', e);
    }

    // 2. Event Listeners Form & Aksi
    const btnSave = document.getElementById('btn-save-config');
    if (btnSave) {
        btnSave.addEventListener('click', saveSuratConfig);
    }

    const form = document.getElementById('form-surat-pengantar-config');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            saveSuratConfig();
        });
    }

    // Toggle Form Collapse
    const btnToggle = document.getElementById('btn-toggle-config');
    if (btnToggle) {
        btnToggle.addEventListener('click', toggleConfigForm);
    }

    // Live update preview nomor surat di rumus dan tabel
    const inputPokok = document.getElementById('cfg-nomor-pokok');
    const inputSufiks = document.getElementById('cfg-format-sufiks');
    const inputBulan = document.getElementById('cfg-bulan');
    const inputTahun = document.getElementById('cfg-tahun');

    [inputPokok, inputSufiks, inputBulan, inputTahun].forEach(el => {
        if (el) {
            el.addEventListener('input', updateFormulaAndTablePreview);
        }
    });

    // Upload TTD Pejabat
    initTtdUpload();

    // Quick Search Unit
    const searchInput = document.getElementById('filter-unit-search');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            currentSearchQuery = e.target.value.trim().toLowerCase();
            currentPageSurat = 1;
            renderUnitsTablePage(1);
        });
    }

    // Download Semua Dokumen ZIP
    const btnZip = document.getElementById('btn-download-all-zip');
    if (btnZip) {
        btnZip.addEventListener('click', downloadAllZip);
    }

    // 3. Muat Data Surat Awal
    await loadSuratData();
}

/**
 * Memuat konfigurasi surat dan data unit untuk periode terpilih
 */
async function loadSuratData(periodeId = null) {
    try {
        let url = '/api/admin/surat_pengantar/config.php';
        if (periodeId) {
            url += `?periode_id=${encodeURIComponent(periodeId)}`;
        }

        const res = await fetch(url);
        const data = await res.json();

        if (!data.ok) {
            showAdminAlert(data.error || 'Terjadi kesalahan saat memuat data surat pengantar.', 'error', 'Gagal Memuat Data');
            return;
        }

        allPeriodeList = data.all_periode || [];
        const periodeTerpilih = data.periode_terpilih;
        currentPeriodeId = periodeTerpilih ? periodeTerpilih.id : null;
        currentConfig = data.config;
        currentUnits = data.units || [];

        renderPeriodSelector(allPeriodeList, currentPeriodeId, currentUnits);
        populateConfigForm(currentConfig, currentPeriodeId);
        renderUnitsTable(currentUnits);

    } catch (err) {
        console.error('[surat-mahasiswa.js] Error:', err);
        showAdminAlert('Gagal menghubungi server untuk memuat konfigurasi surat pengantar.', 'error', 'Koneksi Bermasalah');
    }
}

/**
 * Render Minimalist Period Selector Bar
 */
function renderPeriodSelector(periodes, selectedId, units = []) {
    const selectorContainer = document.getElementById('period-selector-container');
    const metaContainer = document.getElementById('period-meta-badges');
    if (!selectorContainer) return;

    if (!periodes || periodes.length === 0) {
        selectorContainer.innerHTML = `<span style="font-size: 0.85rem; color: #94a3b8; font-style: italic;">Belum ada data periode magang terdaftar.</span>`;
        if (metaContainer) metaContainer.innerHTML = '';
        return;
    }

    const selectedPeriod = periodes.find(p => p.id == selectedId) || periodes[0];
    const activeId = selectedPeriod ? selectedPeriod.id : null;

    const totalMhsAccepted = (units || []).reduce((acc, u) => acc + (parseInt(u.total_mahasiswa, 10) || 0), 0);
    const totalUnitsCount = (units || []).length;

    if (periodes.length <= 4) {
        let pillsHtml = `<div class="period-pills-list">`;
        periodes.forEach(p => {
            const isSelected = p.id == activeId;
            let dotStatus = 'ditutup';
            if (p.status === 'dibuka') dotStatus = 'dibuka';
            else if (p.status === 'persiapan') dotStatus = 'persiapan';

            pillsHtml += `
                <button type="button" class="period-pill-btn ${isSelected ? 'active' : ''}" data-periode-id="${escapeHtml(p.id)}">
                    <span class="status-indicator-dot ${dotStatus}"></span>
                    <span>${escapeHtml(p.nama)}</span>
                </button>
            `;
        });
        pillsHtml += `</div>`;
        selectorContainer.innerHTML = pillsHtml;

        selectorContainer.querySelectorAll('.period-pill-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const pid = btn.getAttribute('data-periode-id');
                if (pid && pid != currentPeriodeId) {
                    loadSuratData(pid);
                }
            });
        });
    } else {
        let selectHtml = `<select id="select-periode-surat" class="period-select-dropdown">`;
        periodes.forEach(p => {
            const isSelected = p.id == activeId;
            let statusText = p.status === 'dibuka' ? 'Aktif' : (p.status === 'persiapan' ? 'Persiapan' : 'Ditutup');
            selectHtml += `
                <option value="${escapeHtml(p.id)}" ${isSelected ? 'selected' : ''}>
                    ${escapeHtml(p.nama)} (${statusText})
                </option>
            `;
        });
        selectHtml += `</select>`;
        selectorContainer.innerHTML = selectHtml;

        const selectEl = document.getElementById('select-periode-surat');
        if (selectEl) {
            selectEl.addEventListener('change', (e) => {
                const pid = e.target.value;
                if (pid && pid != currentPeriodeId) {
                    loadSuratData(pid);
                }
            });
        }
    }

    if (metaContainer && selectedPeriod) {
        let statusBadge = '';
        if (selectedPeriod.status === 'dibuka') {
            statusBadge = `<span class="meta-chip meta-chip-success">Periode Dibuka</span>`;
        } else if (selectedPeriod.status === 'persiapan') {
            statusBadge = `<span class="meta-chip meta-chip-warning">Periode Persiapan</span>`;
        } else {
            statusBadge = `<span class="meta-chip meta-chip-danger">Periode Ditutup</span>`;
        }

        const isAnnounceOpen = selectedPeriod.pengumuman_dibuka == 1;
        const announceBadge = isAnnounceOpen
            ? `<span class="meta-chip meta-chip-announcement-open">Pengumuman: Terbuka</span>`
            : `<span class="meta-chip meta-chip-announcement-closed">Pengumuman: Tertutup (Draft)</span>`;

        const statBadge = `<span class="meta-chip meta-chip-info">${totalUnitsCount} Unit PLN (${totalMhsAccepted} Mahasiswa Diterima)</span>`;

        metaContainer.innerHTML = `${statusBadge} ${announceBadge} ${statBadge}`;
    }
}

/**
 * Toggle Collapse Form Konfigurasi
 */
function toggleConfigForm() {
    const formBody = document.getElementById('config-form-body');
    const toggleIcon = document.getElementById('toggle-config-icon');
    const toggleText = document.getElementById('toggle-config-text');
    if (!formBody) return;

    if (formBody.classList.contains('collapsed')) {
        formBody.classList.remove('collapsed');
        if (toggleText) toggleText.innerText = 'Tutup Formulir';
        if (toggleIcon) toggleIcon.style.transform = 'rotate(0deg)';
    } else {
        formBody.classList.add('collapsed');
        if (toggleText) toggleText.innerText = 'Buka Formulir';
        if (toggleIcon) toggleIcon.style.transform = 'rotate(180deg)';
    }
}

/**
 * Isi form konfigurasi surat
 */
function populateConfigForm(config, periodeId) {
    if (!config) return;

    document.getElementById('cfg-periode-id').value = periodeId || '';
    document.getElementById('cfg-nomor-pokok').value = config.nomor_pokok || '';
    document.getElementById('cfg-format-sufiks').value = config.format_sufiks || '/Kt/2/D06';
    document.getElementById('cfg-bulan').value = config.bulan || '';
    document.getElementById('cfg-tahun').value = config.tahun || '';
    document.getElementById('cfg-tanggal-surat').value = config.tanggal_surat || '';
    document.getElementById('cfg-tahun-akademik').value = config.tahun_akademik || '';
    document.getElementById('cfg-perihal').value = config.perihal || '';
    document.getElementById('cfg-jabatan-ttd').value = config.jabatan_penandatangan || '';
    document.getElementById('cfg-nama-ttd').value = config.nama_penandatangan || '';
    document.getElementById('cfg-nip-ttd').value = config.nip_penandatangan || '';

    // Preview TTD jika sudah ada
    const ttdPreviewContainer = document.getElementById('ttd-preview-container');
    const imgTtdPreview = document.getElementById('img-ttd-preview');
    if (config.ttd_image_path) {
        if (imgTtdPreview) imgTtdPreview.src = '/' + config.ttd_image_path.replace(/^\/+/, '');
        if (ttdPreviewContainer) ttdPreviewContainer.style.display = 'block';
    } else {
        if (ttdPreviewContainer) ttdPreviewContainer.style.display = 'none';
    }

    updateFormulaAndTablePreview();
}

/**
 * Format string nomor surat dengan nomor sequence unit
 */
function formatNomorSuratJs(nomorPokok, unitSeq, sufiks, bulan, tahun) {
    let cleanPokok = (nomorPokok || '').trim();
    if (!cleanPokok) {
        cleanPokok = '[Belum Diset]';
    }

    const kodeUnit = String(unitSeq).padStart(2, '0');
    let cleanSufiks = (sufiks || '/Kt/2/D06').trim();
    if (!cleanSufiks.startsWith('/')) {
        cleanSufiks = '/' + cleanSufiks;
    }
    cleanSufiks = cleanSufiks.replace(/\/+$/, '');

    const now = new Date();
    const b = (bulan || '').trim() ? String(bulan).trim().padStart(2, '0') : String(now.getMonth() + 1).padStart(2, '0');
    const t = (tahun || '').trim() ? String(tahun).trim() : String(now.getFullYear());

    return `${cleanPokok}.${kodeUnit}${cleanSufiks}/${b}/${t}`;
}

/**
 * Update preview rumus nomor surat dan tabel
 */
function updateFormulaAndTablePreview() {
    const pokok = document.getElementById('cfg-nomor-pokok')?.value || '';
    const sufiks = document.getElementById('cfg-format-sufiks')?.value || '/Kt/2/D06';
    const bulan = document.getElementById('cfg-bulan')?.value || '';
    const tahun = document.getElementById('cfg-tahun')?.value || '';

    const formulaEl = document.getElementById('live-formula-preview');
    if (formulaEl) {
        formulaEl.innerText = formatNomorSuratJs(pokok, 1, sufiks, bulan, tahun);
    }

    // Update baris-baris tabel
    const rows = document.querySelectorAll('#units-table-body tr[data-unit-seq]');
    rows.forEach(tr => {
        const seq = parseInt(tr.getAttribute('data-unit-seq'), 10) || 1;
        const cell = tr.querySelector('.cell-nomor-surat');
        if (cell) {
            cell.innerText = formatNomorSuratJs(pokok, seq, sufiks, bulan, tahun);
        }
    });
}

/**
 * Inisialisasi Upload File Tanda Tangan
 */
function initTtdUpload() {
    const dropZone = document.getElementById('ttd-drop-zone');
    const fileInput = document.getElementById('file-ttd-input');
    if (!dropZone || !fileInput) return;

    dropZone.addEventListener('click', () => {
        fileInput.click();
    });

    fileInput.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        await uploadTtdFile(file);
    });

    // Drag & Drop
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#0b3d6b';
        dropZone.style.background = '#e2e8f0';
    });

    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = '#f8fafc';
    });

    dropZone.addEventListener('drop', async (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = '#f8fafc';
        const file = e.dataTransfer.files[0];
        if (file) {
            await uploadTtdFile(file);
        }
    });
}

/**
 * Kirim file TTD ke server
 */
async function uploadTtdFile(file) {
    if (!currentPeriodeId) {
        showAdminAlert('Pilih periode magang terlebih dahulu.', 'warning', 'Periode Belum Dipilih');
        return;
    }

    const formData = new FormData();
    formData.append('ttd_file', file);
    formData.append('periode_id', currentPeriodeId);
    if (csrfToken) {
        formData.append('csrf_token', csrfToken);
    }

    try {
        const res = await fetch('/api/admin/surat_pengantar/upload_ttd.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (!data.ok) {
            showAdminAlert(data.error || 'Gagal mengunggah file tanda tangan.', 'error', 'Upload Gagal');
            return;
        }

        const ttdPreviewContainer = document.getElementById('ttd-preview-container');
        const imgTtdPreview = document.getElementById('img-ttd-preview');
        if (imgTtdPreview && data.preview_base64) {
            imgTtdPreview.src = data.preview_base64;
        } else if (imgTtdPreview && data.file_path) {
            imgTtdPreview.src = '/' + data.file_path.replace(/^\/+/, '');
        }

        if (ttdPreviewContainer) {
            ttdPreviewContainer.style.display = 'block';
        }

        showAdminAlert('Tanda tangan pejabat berhasil diunggah dan disimpan.', 'success', 'Berhasil Diunggah');
    } catch (err) {
        console.error('[surat-mahasiswa.js] Upload error:', err);
        showAdminAlert('Terjadi gangguan jaringan saat mengunggah tanda tangan.', 'error', 'Koneksi Bermasalah');
    }
}

/**
 * Simpan Konfigurasi Surat
 */
async function saveSuratConfig() {
    if (!currentPeriodeId) {
        showAdminAlert('Tidak ada periode aktif yang dipilih.', 'error', 'Validasi Gagal');
        return;
    }

    const nomorPokok = document.getElementById('cfg-nomor-pokok')?.value.trim();
    if (!nomorPokok) {
        showAdminAlert('Nomor Pokok Surat wajib diisi agar mahasiswa dapat mengunduh surat saat pengumuman dibuka.', 'warning', 'Nomor Pokok Kosong');
        document.getElementById('cfg-nomor-pokok')?.focus();
        return;
    }

    const payload = {
        periode_id: currentPeriodeId,
        nomor_pokok: nomorPokok,
        format_sufiks: document.getElementById('cfg-format-sufiks')?.value.trim() || '/Kt/2/D06',
        bulan: document.getElementById('cfg-bulan')?.value.trim() || '',
        tahun: document.getElementById('cfg-tahun')?.value.trim() || '',
        tanggal_surat: document.getElementById('cfg-tanggal-surat')?.value.trim() || '',
        tahun_akademik: document.getElementById('cfg-tahun-akademik')?.value.trim() || '',
        perihal: document.getElementById('cfg-perihal')?.value.trim() || '',
        jabatan_penandatangan: document.getElementById('cfg-jabatan-ttd')?.value.trim() || '',
        nama_penandatangan: document.getElementById('cfg-nama-ttd')?.value.trim() || '',
        nip_penandatangan: document.getElementById('cfg-nip-ttd')?.value.trim() || ''
    };

    const btnSave = document.getElementById('btn-save-config');
    const originalText = btnSave ? btnSave.innerHTML : '';
    if (btnSave) {
        btnSave.disabled = true;
        btnSave.innerHTML = `Menyimpan...`;
    }

    try {
        const res = await fetch('/api/admin/surat_pengantar/save_config.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (!data.ok) {
            showAdminAlert(data.error || 'Gagal menyimpan pengaturan surat.', 'error', 'Gagal');
            return;
        }

        currentConfig = data.config;
        showAdminAlert('Pengaturan Surat Pengantar Mahasiswa berhasil disimpan!', 'success', 'Pengaturan Tersimpan');
        updateFormulaAndTablePreview();

    } catch (err) {
        console.error('[surat-mahasiswa.js] Save error:', err);
        showAdminAlert('Terjadi gangguan koneksi saat menyimpan pengaturan.', 'error', 'Koneksi Bermasalah');
    } finally {
        if (btnSave) {
            btnSave.disabled = false;
            btnSave.innerHTML = originalText;
        }
    }
}

/**
 * Filter unit sesuai query search
 */
function getFilteredUnits() {
    if (!currentSearchQuery) return currentUnits;
    return currentUnits.filter(u => {
        const name = (u.unit_nama || '').toLowerCase();
        const addr = (u.unit_alamat || '').toLowerCase();
        return name.includes(currentSearchQuery) || addr.includes(currentSearchQuery);
    });
}

/**
 * Render Tabel Unit
 */
function renderUnitsTable(units) {
    currentPageSurat = 1;
    renderUnitsTablePage(1);
}

/**
 * Render Halaman Tabel Unit
 */
function renderUnitsTablePage(page) {
    const tbody = document.getElementById('units-table-body');
    const countInfo = document.getElementById('units-count-info');
    if (!tbody) return;

    currentPageSurat = page;
    const filtered = getFilteredUnits();

    if (countInfo) {
        countInfo.innerText = `Menampilkan ${filtered.length} kantor unit PLN dengan mahasiswa diterima`;
    }

    if (filtered.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; padding: 40px; color: #94a3b8;">
                    ${currentSearchQuery ? 'Tidak ada unit PLN yang cocok dengan pencarian.' : 'Belum ada unit yang menerima mahasiswa pada periode ini.'}
                </td>
            </tr>
        `;
        renderPaginationNav(0, 1);
        return;
    }

    const totalPages = Math.ceil(filtered.length / pageSizeSurat);
    const startIdx = (page - 1) * pageSizeSurat;
    const pageUnits = filtered.slice(startIdx, startIdx + pageSizeSurat);

    const nomorPokok = document.getElementById('cfg-nomor-pokok')?.value.trim() || currentConfig?.nomor_pokok || '';
    const sufiks = document.getElementById('cfg-format-sufiks')?.value.trim() || currentConfig?.format_sufiks || '/Kt/2/D06';
    const bulan = document.getElementById('cfg-bulan')?.value.trim() || currentConfig?.bulan || '';
    const tahun = document.getElementById('cfg-tahun')?.value.trim() || currentConfig?.tahun || '';

    let html = '';
    pageUnits.forEach((u, i) => {
        const originalIndex = currentUnits.findIndex(orig => orig.unit_id === u.unit_id);
        const seq = (originalIndex >= 0 ? originalIndex : (startIdx + i)) + 1;
        const kodeUnitStr = '.' + String(seq).padStart(2, '0');
        const formattedNomor = formatNomorSuratJs(nomorPokok, seq, sufiks, bulan, tahun);

        html += `
            <tr data-unit-seq="${seq}">
                <td style="text-align: center; font-weight: 600; color: #64748b;">${startIdx + i + 1}</td>
                <td style="text-align: center;">
                    <span style="display: inline-block; padding: 3px 8px; border-radius: 6px; background: #e0f2fe; color: #0369a1; font-weight: 700; font-family: monospace; font-size: 0.85rem;">
                        ${kodeUnitStr}
                    </span>
                </td>
                <td>
                    <span class="cell-nomor-surat" style="font-family: monospace; font-weight: 700; font-size: 0.875rem; color: #0b3d6b;">
                        ${escapeHtml(formattedNomor)}
                    </span>
                </td>
                <td>
                    <strong style="color: #0f172a; font-size: 0.9rem;">${escapeHtml(u.unit_nama)}</strong>
                </td>
                <td>
                    <span style="font-size: 0.8rem; color: #64748b;">${escapeHtml(u.unit_alamat || '-')}</span>
                </td>
                <td style="text-align: center;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; padding: 4px 10px; border-radius: 9999px; background: #dcfce7; color: #166534; font-weight: 700; font-size: 0.825rem;">
                        ${u.total_mahasiswa} Orang
                    </span>
                </td>
                <td style="text-align: center;">
                    <a href="/api/admin/surat_pengantar/download.php?periode_id=${encodeURIComponent(currentPeriodeId)}&unit_id=${encodeURIComponent(u.unit_id)}" target="_blank" class="btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-size: 0.8rem; text-decoration: none; border-radius: 8px; font-weight: 600; background: #f8fafc; border: 1px solid #cbd5e1; color: #0b3d6b; transition: all 0.2s ease;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <span>Unduh PDF</span>
                    </a>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    renderPaginationNav(filtered.length, page);
}

/**
 * Render Navigasi Paginasi
 */
function renderPaginationNav(totalItems, currentPage) {
    const nav = document.getElementById('pagination-nav-surat');
    const info = document.getElementById('pagination-info-surat');
    if (!nav || !info) return;

    const totalPages = Math.ceil(totalItems / pageSizeSurat) || 1;
    const startItem = totalItems === 0 ? 0 : (currentPage - 1) * pageSizeSurat + 1;
    const endItem = Math.min(currentPage * pageSizeSurat, totalItems);

    info.innerText = `Menampilkan ${startItem}-${endItem} dari total ${totalItems} unit`;

    if (totalPages <= 1) {
        nav.innerHTML = '';
        return;
    }

    let btns = '';
    btns += `<button type="button" class="btn-pagination" ${currentPage <= 1 ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} data-page="${currentPage - 1}">‹</button>`;

    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= currentPage - 1 && p <= currentPage + 1)) {
            btns += `<button type="button" class="btn-pagination ${p === currentPage ? 'active' : ''}" style="${p === currentPage ? 'background:#0b3d6b; color:#fff; font-weight:700;' : ''}" data-page="${p}">${p}</button>`;
        } else if (p === currentPage - 2 || p === currentPage + 2) {
            btns += `<span style="padding: 0 4px; color: #94a3b8;">...</span>`;
        }
    }

    btns += `<button type="button" class="btn-pagination" ${currentPage >= totalPages ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} data-page="${currentPage + 1}">›</button>`;
    nav.innerHTML = btns;

    nav.querySelectorAll('.btn-pagination:not([disabled])').forEach(b => {
        b.addEventListener('click', () => {
            const pageNum = parseInt(b.getAttribute('data-page'), 10);
            if (pageNum) renderUnitsTablePage(pageNum);
        });
    });
}

/**
 * Download Semua Surat Unit (.ZIP)
 */
async function downloadAllZip() {
    if (!currentPeriodeId) {
        showAdminAlert('Pilih periode terlebih dahulu.', 'warning', 'Periode Kosong');
        return;
    }

    const nomorPokok = document.getElementById('cfg-nomor-pokok')?.value.trim();
    if (!nomorPokok) {
        showAdminAlert('Harap isi dan simpan Nomor Pokok Surat terlebih dahulu sebelum mengunduh seluruh arsip surat.', 'warning', 'Nomor Pokok Belum Diisi');
        return;
    }

    const btnZip = document.getElementById('btn-download-all-zip');
    const origHtml = btnZip ? btnZip.innerHTML : '';
    if (btnZip) {
        btnZip.disabled = true;
        btnZip.innerHTML = `Memproses ZIP...`;
    }

    // Arahkan browser untuk trigger download file ZIP
    const zipUrl = `/api/admin/surat_pengantar/download_all.php?periode_id=${encodeURIComponent(currentPeriodeId)}`;
    window.location.href = zipUrl;

    setTimeout(() => {
        if (btnZip) {
            btnZip.disabled = false;
            btnZip.innerHTML = origHtml;
        }
    }, 3000);
}
