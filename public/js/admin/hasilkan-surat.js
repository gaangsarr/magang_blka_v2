/**
 * public/js/admin/hasilkan-surat.js
 * Controller untuk Halaman Hasilkan Surat Penempatan Unit REMATE
 */

import { showAdminToast, showAdminAlert, showAdminConfirm } from '/js/admin/common.js';

let currentPeriodeId = null;
let currentConfig = null;
let currentUnits = [];
let allPeriodeList = [];
let csrfToken = null;

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
}

/**
 * Memuat konfigurasi surat dan data unit untuk periode terpilih
 */
async function loadSuratData(periodeId = null) {
    try {
        // Fetch status & CSRF token jika belum ada
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
        currentUnits = data.units || [];

        renderPeriodCards(allPeriodeList, currentPeriodeId);
        populateConfigForm(currentConfig, currentPeriodeId);
        renderUnitsTable(currentUnits, currentConfig);

    } catch (err) {
        console.error('[hasilkan-surat.js] Error:', err);
        showAdminAlert('Gagal menghubungi server untuk memuat konfigurasi surat.', 'error', 'Koneksi Bermasalah');
    }
}

/**
 * Render kartu pemilihan periode
 */
function renderPeriodCards(periodes, selectedId) {
    const container = document.getElementById('period-cards-container');
    const statusInfo = document.getElementById('period-status-info');
    if (!container) return;

    if (!periodes || periodes.length === 0) {
        container.innerHTML = `
            <div style="padding: 24px; text-align: center; color: #64748b; background: #fff; border-radius: 12px; grid-column: 1 / -1;">
                Belum ada data periode magang terdaftar.
            </div>
        `;
        if (statusInfo) statusInfo.innerText = '';
        return;
    }

    let cardsHtml = '';
    let selectedName = '';

    periodes.forEach(p => {
        const isSelected = p.id == selectedId;
        if (isSelected) selectedName = p.nama;

        let badgeBg = '#e2e8f0';
        let badgeColor = '#475569';
        let statusLabel = p.status;

        if (p.status === 'dibuka') {
            badgeBg = '#dcfce7';
            badgeColor = '#166534';
            statusLabel = 'Pendaftaran Dibuka';
        } else if (p.status === 'persiapan') {
            badgeBg = '#fef3c7';
            badgeColor = '#92400e';
            statusLabel = 'Masa Persiapan';
        } else if (p.status === 'ditutup') {
            badgeBg = '#fee2e2';
            badgeColor = '#991b1b';
            statusLabel = 'Pendaftaran Ditutup';
        }

        const progLabel = p.program_1_bulan && p.program_5_bulan ? '1 & 5 Bulan' : (p.program_1_bulan ? '1 Bulan' : '5 Bulan');
        const announcementHtml = p.pengumuman_dibuka == 1
            ? `<span style="display: inline-flex; align-items: center; gap: 5px; color: #059669; font-weight: 600; font-size: 0.8rem;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg> Pengumuman Mahasiswa Dibuka</span>`
            : `<span style="display: inline-flex; align-items: center; gap: 5px; color: #64748b; font-size: 0.8rem;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Pengumuman Mahasiswa Ditutup</span>`;

        cardsHtml += `
            <div class="period-select-card ${isSelected ? 'active' : ''}" data-periode-id="${escapeHtml(p.id)}">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                    <span class="period-card-badge" style="background: ${badgeBg}; color: ${badgeColor};">${escapeHtml(statusLabel)}</span>
                    <span style="font-size: 0.75rem; color: #64748b; font-weight: 600;">Program: ${escapeHtml(progLabel)}</span>
                </div>
                <h4 style="margin: 0 0 6px 0; font-size: 1.05rem; font-weight: 700; color: #0f172a;">${escapeHtml(p.nama)}</h4>
                <p style="margin: 0; font-size: 0.8rem; color: #64748b;">
                    ${announcementHtml}
                </p>
            </div>
        `;
    });

    container.innerHTML = cardsHtml;

    if (statusInfo) {
        statusInfo.innerText = selectedName ? `Periode Terpilih: ${selectedName}` : '';
    }

    // Attach click listener to each period card
    document.querySelectorAll('.period-select-card').forEach(card => {
        card.addEventListener('click', () => {
            const pid = card.getAttribute('data-periode-id');
            if (pid && pid != currentPeriodeId) {
                loadSuratData(pid);
            }
        });
    });
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
 * Render tabel daftar kantor unit
 */
function renderUnitsTable(units, config) {
    const tbody = document.getElementById('units-table-body');
    const infoCount = document.getElementById('units-count-info');
    if (!tbody) return;

    if (!units || units.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 40px; color: #94a3b8;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span style="font-weight: 600; font-size: 0.95rem;">Belum ada mahasiswa berstatus 'diterima' pada unit-unit di periode ini.</span>
                        <span style="font-size: 0.825rem; color: #64748b;">Silakan lakukan penetapan mahasiswa terlebih dahulu pada menu <strong>Penetapan Unit</strong>.</span>
                    </div>
                </td>
            </tr>
        `;
        if (infoCount) infoCount.innerText = '0 Kantor Unit PLN ditemukan';
        return;
    }

    const template = config?.nomor_surat_template || '{nomor}/Srt/1/D0/08/2026';
    const startNum = parseInt(config?.nomor_surat_start || 1, 10);

    let totalMhsAllUnits = 0;
    let rowsHtml = '';

    units.forEach((u, idx) => {
        const seq = startNum + idx;
        const noSurat = formatNomorSuratJs(template, seq);
        const mhsCount = parseInt(u.total_mahasiswa, 10) || 0;
        totalMhsAllUnits += mhsCount;

        const alamat = u.unit_alamat ? escapeHtml(u.unit_alamat) : '<span style="color: #94a3b8; font-style: italic;">Alamat belum diatur di Master Unit</span>';

        rowsHtml += `
            <tr>
                <td style="text-align: center; font-weight: 600; color: #64748b;">${idx + 1}</td>
                <td>
                    <code class="preview-nomor-cell" style="background: #f1f5f9; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.85rem; color: #0f172a;">${escapeHtml(noSurat)}</code>
                </td>
                <td>
                    <strong style="color: #0b3d6b; font-size: 0.925rem;">${escapeHtml(u.unit_nama)}</strong>
                </td>
                <td style="font-size: 0.825rem; color: #475569; max-width: 320px;">
                    ${alamat}
                </td>
                <td style="text-align: center;">
                    <span style="display: inline-block; background: #ecfdf5; color: #065f46; font-weight: 700; padding: 4px 12px; border-radius: 8px; font-size: 0.85rem;">
                        ${mhsCount} Mahasiswa
                    </span>
                </td>
                <td style="text-align: center;">
                    <a href="/api/admin/surat/download.php?periode_id=${encodeURIComponent(currentPeriodeId)}&unit_id=${encodeURIComponent(u.unit_id)}" class="btn-primary" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; font-size: 0.8rem; background: #0b3d6b; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 700; white-space: nowrap; box-shadow: 0 2px 6px rgba(11, 61, 107, 0.15);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <span>Download DOCX</span>
                    </a>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = rowsHtml;
    if (infoCount) {
        infoCount.innerText = `Menampilkan ${units.length} Kantor Unit PLN (${totalMhsAllUnits} Total Mahasiswa Diterima)`;
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

    if (!currentUnits || currentUnits.length === 0) {
        showAdminAlert('Tidak ada unit dengan mahasiswa berstatus diterima pada periode ini.', 'info', 'Belum Ada Surat');
        return;
    }

    window.location.href = `/api/admin/surat/download_all.php?periode_id=${currentPeriodeId}`;
}
