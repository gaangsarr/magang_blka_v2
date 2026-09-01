/**
 * public/js/admin/hasilkan-surat.js
 * Controller untuk Halaman Hasilkan Surat Penempatan Unit REMATE
 */

import { showAdminToast, showAdminAlert, showAdminConfirm } from '/js/admin/common.js';

let currentPeriodeId = null;
let currentConfig = null;
let currentDocuments = [];
let allPeriodeList = [];
let csrfToken = null;
let currentSearchQuery = '';

export function escapeHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', () => {
    initEvents();
    loadSuratData();
});

function initEvents() {
    // Form submit / save button
    const btnSave = document.getElementById('btn-save-config');
    if (btnSave) {
        btnSave.addEventListener('click', saveSuratConfig);
    }

    const form = document.getElementById('form-surat-config');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            saveSuratConfig();
        });
    }

    // Toggle Section 2 Configuration Form Body
    const btnToggleConfig = document.getElementById('btn-toggle-config');
    const configBody = document.getElementById('config-form-body');
    const toggleText = document.getElementById('toggle-config-text');
    const toggleIcon = document.getElementById('toggle-config-icon');
    if (btnToggleConfig && configBody) {
        btnToggleConfig.addEventListener('click', () => {
            const isCollapsed = configBody.classList.toggle('collapsed');
            if (toggleText) {
                toggleText.innerText = isCollapsed ? 'Buka Formulir' : 'Tutup Formulir';
            }
            if (toggleIcon) {
                toggleIcon.style.transform = isCollapsed ? 'rotate(180deg)' : 'rotate(0deg)';
            }
        });
    }

    // Live update preview nomor surat di tabel saat input nomor template / start berubah
    const inputTemplate = document.getElementById('cfg-nomor-template');
    const inputStart = document.getElementById('cfg-nomor-start');
    if (inputTemplate && inputStart) {
        inputTemplate.addEventListener('input', updateTableNomorSuratPreview);
        inputStart.addEventListener('input', updateTableNomorSuratPreview);
    }

    // Copy link publik button
    const btnCopyLink = document.getElementById('btn-copy-link');
    if (btnCopyLink) {
        btnCopyLink.addEventListener('click', copyPublicLink);
    }

    // Download All ZIP button
    const btnZip = document.getElementById('btn-download-all-zip');
    if (btnZip) {
        btnZip.addEventListener('click', downloadAllZip);
    }

    // Filter search documents in real-time
    const filterUnitSearch = document.getElementById('filter-unit-search');
    if (filterUnitSearch) {
        filterUnitSearch.addEventListener('input', (e) => {
            currentSearchQuery = e.target.value.trim().toLowerCase();
            renderDocumentsTable(currentDocuments, currentConfig, currentSearchQuery);
        });
    }
}

/**
 * Memuat konfigurasi surat dan daftar dokumen untuk periode terpilih
 */
async function loadSuratData(periodeId = null) {
    try {
        if (!csrfToken) {
            try {
                const statusRes = await fetch('/api/admin/status.php');
                const statusData = await statusRes.json();
                if (statusData && statusData.csrf_token) {
                    csrfToken = statusData.csrf_token;
                }
            } catch (e) {
                console.warn('[hasilkan-surat.js] Gagal memuat CSRF token:', e);
            }
        }

        let url = '/api/admin/surat/config.php';
        if (periodeId) {
            url += `?periode_id=${encodeURIComponent(periodeId)}`;
        }

        const res = await fetch(url);
        const data = await res.json();

        if (!data.ok) {
            showAdminAlert(data.error || 'Terjadi kesalahan saat memuat data surat.', 'error', 'Gagal Memuat Data');
            return;
        }

        allPeriodeList = data.all_periode || [];
        const periodeTerpilih = data.periode_terpilih;
        currentPeriodeId = periodeTerpilih ? periodeTerpilih.id : null;
        currentConfig = data.config;
        currentDocuments = data.documents || [];

        renderPeriodSelector(allPeriodeList, currentPeriodeId, currentDocuments);
        populateConfigForm(currentConfig, currentPeriodeId);
        renderDocumentsTable(currentDocuments, currentConfig, currentSearchQuery);

    } catch (err) {
        console.error('[hasilkan-surat.js] Error:', err);
        showAdminAlert('Gagal menghubungi server untuk memuat konfigurasi surat.', 'error', 'Koneksi Bermasalah');
    }
}

/**
 * Render Minimalist Period Selector Bar
 */
function renderPeriodSelector(periodes, selectedId, documents = []) {
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

    // Hitung total mahasiswa diterima pada dokumen
    const totalMhsAccepted = (documents || []).reduce((acc, d) => acc + (parseInt(d.total_mahasiswa, 10) || 0), 0);
    const totalDocsCount = (documents || []).length;

    if (periodes.length <= 4) {
        let pillsHtml = `<div class="period-pills-list">`;
        periodes.forEach(p => {
            const isSelected = p.id == activeId;
            let dotStatus = 'ditutup';
            if (p.status === 'dibuka') {
                dotStatus = 'dibuka';
            } else if (p.status === 'persiapan') {
                dotStatus = 'persiapan';
            }

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
            let statusShort = p.status === 'dibuka' ? 'Dibuka' : (p.status === 'persiapan' ? 'Persiapan' : 'Ditutup');
            selectHtml += `
                <option value="${escapeHtml(p.id)}" ${isSelected ? 'selected' : ''}>
                    ${escapeHtml(p.nama)} (${statusShort})
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
        let statusBadgeClass = 'meta-chip-neutral';
        let statusDotClass = 'ditutup';
        let statusText = 'Pendaftaran Ditutup';
        if (selectedPeriod.status === 'dibuka') {
            statusBadgeClass = 'meta-chip-success';
            statusDotClass = 'dibuka';
            statusText = 'Pendaftaran Dibuka';
        } else if (selectedPeriod.status === 'persiapan') {
            statusBadgeClass = 'meta-chip-warning';
            statusDotClass = 'persiapan';
            statusText = 'Masa Persiapan';
        }

        const progLabel = selectedPeriod.program_1_bulan && selectedPeriod.program_5_bulan 
            ? '1 & 5 Bulan' 
            : (selectedPeriod.program_1_bulan ? '1 Bulan' : '5 Bulan');

        const isAnnounced = selectedPeriod.pengumuman_dibuka == 1;
        const announcementChipClass = isAnnounced ? 'meta-chip-announcement-open' : 'meta-chip-announcement-closed';
        const announcementText = isAnnounced ? 'Pengumuman Dibuka' : 'Pengumuman Ditutup';
        const announcementIcon = isAnnounced
            ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>`
            : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`;

        metaContainer.innerHTML = `
            <span class="meta-chip ${statusBadgeClass}">
                <span class="status-indicator-dot ${statusDotClass}"></span>
                <span>${escapeHtml(statusText)}</span>
            </span>
            <span class="meta-chip meta-chip-info">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg>
                <span>Program: ${escapeHtml(progLabel)}</span>
            </span>
            <span class="meta-chip ${announcementChipClass}">
                ${announcementIcon}
                <span>${escapeHtml(announcementText)}</span>
            </span>
            <span class="meta-chip meta-chip-stat">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/></svg>
                <span>${totalDocsCount} Dokumen (${totalMhsAccepted} Mahasiswa)</span>
            </span>
        `;
    }
}

/**
 * Isi data ke dalam formulir kustomisasi
 */
function populateConfigForm(config, periodeId) {
    if (!config) return;

    document.getElementById('cfg-periode-id').value = periodeId || '';
    document.getElementById('cfg-nomor-template').value = config.nomor_surat_template || '{nomor}/Srt/1/D0/08/2026';
    document.getElementById('cfg-nomor-start').value = config.nomor_surat_start || 1;
    document.getElementById('cfg-tanggal-surat').value = config.tanggal_surat || '';
    document.getElementById('cfg-tahun-akademik').value = config.tahun_akademik || '';
    document.getElementById('cfg-perihal').value = config.perihal || '';
    document.getElementById('cfg-jabatan-ttd').value = config.jabatan_penandatangan || '';
    document.getElementById('cfg-nama-ttd').value = config.nama_penandatangan || '';
    document.getElementById('cfg-tembusan').value = config.tembusan || '1.  HTD PLN Pusat';

    const linkInput = document.getElementById('cfg-link-publik');
    if (linkInput) {
        linkInput.value = config.link_data_publik || '';
    }

    const openLinkBtn = document.getElementById('btn-open-link');
    if (openLinkBtn) {
        openLinkBtn.href = config.link_data_publik || '#';
    }
}

/**
 * Format string nomor surat dengan nomor urut
 */
function formatNomorSuratJs(template, number) {
    if (!template) return String(number);
    const padded = String(number).padStart(3, '0');
    if (template.includes('{nomor}')) {
        return template.replace('{nomor}', padded);
    }
    if (template.includes('{00x}')) {
        return template.replace('{00x}', padded);
    }
    if (template.includes('{no}')) {
        return template.replace('{no}', String(number));
    }
    return `${padded}/${template.replace(/^\/+/, '')}`;
}

/**
 * Update preview nomor surat di tabel saat input berubah
 */
function updateTableNomorSuratPreview() {
    const template = document.getElementById('cfg-nomor-template').value.trim() || '{nomor}/Srt/1/D0/08/2026';
    const startNum = parseInt(document.getElementById('cfg-nomor-start').value, 10) || 1;

    document.querySelectorAll('.preview-nomor-cell').forEach((cell, idx) => {
        const curNum = startNum + idx;
        cell.innerText = formatNomorSuratJs(template, curNum);
    });
}

/**
 * Render tabel daftar dokumen dengan dukungan pencarian real-time
 */
function renderDocumentsTable(documents, config, filterText = '') {
    const tbody = document.getElementById('units-table-body');
    const infoCount = document.getElementById('units-count-info');
    if (!tbody) return;

    if (!documents || documents.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 40px; color: #94a3b8;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span style="font-weight: 600; font-size: 0.95rem;">Belum ada mahasiswa berstatus 'diterima' pada periode ini.</span>
                        <span style="font-size: 0.825rem; color: #64748b;">Silakan lakukan verifikasi dan penetapan mahasiswa terlebih dahulu.</span>
                    </div>
                </td>
            </tr>
        `;
        if (infoCount) infoCount.innerText = '0 Dokumen Surat Pengantar ditemukan';
        return;
    }

    const template = config?.nomor_surat_template || '{nomor}/Srt/1/D0/08/2026';
    const startNum = parseInt(config?.nomor_surat_start || 1, 10);

    let displayDocs = documents;
    if (filterText) {
        displayDocs = documents.filter(d => {
            const nama = (d.nama_dokumen || '').toLowerCase();
            const penerima = (d.penerima || '').toLowerCase();
            return nama.includes(filterText) || penerima.includes(filterText);
        });
    }

    if (displayDocs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 32px; color: #64748b;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 6px;">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.8"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <span style="font-weight: 600;">Tidak ditemukan dokumen yang cocok dengan pencarian "${escapeHtml(filterText)}"</span>
                        <span style="font-size: 0.8rem; color: #94a3b8;">Coba gunakan kata kunci lain.</span>
                    </div>
                </td>
            </tr>
        `;
        if (infoCount) {
            infoCount.innerText = `Menampilkan 0 dari ${documents.length} Dokumen Surat Pengantar`;
        }
        return;
    }

    let totalMhsDisplay = 0;
    let rowsHtml = '';

    displayDocs.forEach((doc, idx) => {
        const origIdx = documents.findIndex(orig => orig.type === doc.type && orig.target_id === doc.target_id);
        const seq = startNum + (origIdx >= 0 ? origIdx : idx);
        const noSurat = formatNomorSuratJs(template, seq);
        const mhsCount = parseInt(doc.total_mahasiswa, 10) || 0;
        totalMhsDisplay += mhsCount;

        const downloadUrl = `/api/admin/surat/download.php?periode_id=${encodeURIComponent(currentPeriodeId)}&target_type=${encodeURIComponent(doc.type)}&target_id=${encodeURIComponent(doc.target_id)}`;

        let badgeType = '<span style="display: inline-block; background: #dbeafe; color: #1e40af; font-size: 0.725rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-left: 6px;">PLN Pusat</span>';
        if (doc.type === 'subholding') {
            badgeType = '<span style="display: inline-block; background: #fef3c7; color: #92400e; font-size: 0.725rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-left: 6px;">Subholding</span>';
        } else if (doc.type === 'anak_perusahaan') {
            badgeType = '<span style="display: inline-block; background: #f3e8ff; color: #6b21a8; font-size: 0.725rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-left: 6px;">Anak Perusahaan</span>';
        }

        rowsHtml += `
            <tr>
                <td style="text-align: center; font-weight: 600; color: #64748b;">${idx + 1}</td>
                <td>
                    <code class="preview-nomor-cell" style="background: #f1f5f9; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.85rem; color: #0f172a;">${escapeHtml(noSurat)}</code>
                </td>
                <td>
                    <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px;">
                        <strong style="color: #0b3d6b; font-size: 0.925rem;">${escapeHtml(doc.nama_dokumen)}</strong>
                        ${badgeType}
                    </div>
                </td>
                <td style="font-size: 0.825rem; color: #475569; max-width: 300px;">
                    ${escapeHtml(doc.penerima)}
                </td>
                <td style="text-align: center;">
                    <span style="display: inline-block; background: #ecfdf5; color: #065f46; font-weight: 700; padding: 4px 12px; border-radius: 8px; font-size: 0.85rem;">
                        ${mhsCount} Mahasiswa
                    </span>
                </td>
                <td style="text-align: center;">
                    <a href="${downloadUrl}" class="btn-primary" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; font-size: 0.8rem; background: #0b3d6b; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 700; white-space: nowrap; box-shadow: 0 2px 6px rgba(11, 61, 107, 0.15);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <span>Download DOCX</span>
                    </a>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = rowsHtml;
    if (infoCount) {
        if (filterText) {
            infoCount.innerText = `Menampilkan ${displayDocs.length} dari ${documents.length} Dokumen Surat Pengantar (${totalMhsDisplay} Mahasiswa)`;
        } else {
            infoCount.innerText = `Menampilkan ${documents.length} Dokumen Surat Pengantar (${totalMhsDisplay} Total Mahasiswa)`;
        }
    }
}

/**
 * Simpan konfigurasi surat ke server
 */
async function saveSuratConfig() {
    if (!currentPeriodeId) {
        showAdminToast('Silakan pilih periode magang terlebih dahulu.', 'warning');
        return;
    }

    const payload = {
        periode_id: currentPeriodeId,
        nomor_surat_template: document.getElementById('cfg-nomor-template').value.trim(),
        nomor_surat_start: parseInt(document.getElementById('cfg-nomor-start').value, 10) || 1,
        tanggal_surat: document.getElementById('cfg-tanggal-surat').value.trim(),
        tahun_akademik: document.getElementById('cfg-tahun-akademik').value.trim(),
        perihal: document.getElementById('cfg-perihal').value.trim(),
        jabatan_penandatangan: document.getElementById('cfg-jabatan-ttd').value.trim(),
        nama_penandatangan: document.getElementById('cfg-nama-ttd').value.trim(),
        tembusan: document.getElementById('cfg-tembusan').value.trim(),
        link_data_publik: document.getElementById('cfg-link-publik').value.trim()
    };

    if (!payload.nomor_surat_template || !payload.tanggal_surat || !payload.perihal || !payload.jabatan_penandatangan || !payload.nama_penandatangan) {
        showAdminToast('Mohon lengkapi seluruh isian bertanda bintang (*).', 'warning');
        return;
    }

    const btnSave = document.getElementById('btn-save-config');
    const originalText = btnSave.innerHTML;
    btnSave.disabled = true;
    btnSave.innerHTML = `<span>Menyimpan...</span>`;

    try {
        const headers = { 'Content-Type': 'application/json' };
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        const res = await fetch('/api/admin/surat/save_config.php', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (data.ok) {
            showAdminToast('Konfigurasi surat berhasil disimpan ke database!', 'success');
            currentConfig = data.config;
            updateTableNomorSuratPreview();
            
            const openLinkBtn = document.getElementById('btn-open-link');
            if (openLinkBtn) {
                openLinkBtn.href = payload.link_data_publik;
            }
        } else {
            showAdminAlert(data.error || 'Terjadi kesalahan saat menyimpan konfigurasi surat.', 'error', 'Gagal Menyimpan');
        }
    } catch (err) {
        console.error('[hasilkan-surat.js] Error saving config:', err);
        showAdminAlert('Gagal terhubung ke server untuk menyimpan konfigurasi.', 'error', 'Kesalahan Sistem');
    } finally {
        btnSave.disabled = false;
        btnSave.innerHTML = originalText;
    }
}

/**
 * Salin link data publik ke clipboard
 */
function copyPublicLink() {
    const linkInput = document.getElementById('cfg-link-publik');
    if (!linkInput || !linkInput.value) {
        showAdminToast('Link publik belum tersedia.', 'warning');
        return;
    }

    navigator.clipboard.writeText(linkInput.value).then(() => {
        showAdminToast('Tautan publik berhasil disalin ke clipboard!', 'success');
    }).catch(() => {
        linkInput.select();
        document.execCommand('copy');
        showAdminToast('Tautan publik disalin!', 'success');
    });
}

/**
 * Download semua surat dalam satu file ZIP
 */
function downloadAllZip() {
    if (!currentPeriodeId) {
        showAdminToast('Pilih periode magang terlebih dahulu.', 'warning');
        return;
    }

    if (!currentDocuments || currentDocuments.length === 0) {
        showAdminAlert('Tidak ada mahasiswa berstatus diterima pada periode ini untuk dibuatkan surat.', 'info', 'Belum Ada Surat');
        return;
    }

    window.location.href = `/api/admin/surat/download_all.php?periode_id=${currentPeriodeId}`;
}


