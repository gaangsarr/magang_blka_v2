/**
 * public/js/admin/penetapan.js
 * Manajemen Penetapan Unit, Pemindahan Paksa, & Publikasi Pengumuman Mahasiswa
 */

import { showAdminAlert, showAdminConfirm, showAdminToast } from './common.js';
import { openPdfPreviewModal } from '/js/pdf-modal.js';

let csrfToken = null;
let allPenetapanData = [];
let unitList = [];
let jurusanList = [];
let selectedIds = new Set();
let currentPeriodeData = null;
let isPeriodeSelectPopulated = false;

let currentPage = 1;
let perPage = 25;
let totalPages = 1;
let totalRecords = 0;
let searchDebounceTimer = null;
let selectedPeriodeId = null;

window.showModal = function(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
};

window.hideModal = function(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
};

function escapeHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', async () => {
    // 1. Cek Admin Auth & CSRF
    try {
        const statusRes = await fetch('/api/admin/status.php');
        const statusData = await statusRes.json();
        if (statusData.csrf_token) csrfToken = statusData.csrf_token;
        if (!statusRes.ok || !statusData.authenticated) {
            window.location.href = '/admin/login.html';
            return;
        }

        if (statusData.admin && statusData.admin.nama) {
            const nameElem = document.getElementById('admin-name');
            if (nameElem) nameElem.innerText = statusData.admin.nama;
        }
    } catch (err) {
        window.location.href = '/admin/login.html';
        return;
    }

    // 2. Load Unit Options for Relocate Modals
    await loadUnitList();

    // 3. Load Penetapan Data
    await loadPenetapanData(null, 1);

    // 4. Multi-Filter Event Listeners with Server-Side Trigger
    document.getElementById('filter-search')?.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
            loadPenetapanData(selectedPeriodeId, 1);
        }, 300);
    });

    document.getElementById('filter-jurusan')?.addEventListener('change', () => loadPenetapanData(selectedPeriodeId, 1));
    document.getElementById('filter-unit')?.addEventListener('change', () => loadPenetapanData(selectedPeriodeId, 1));
    document.getElementById('filter-program')?.addEventListener('change', () => loadPenetapanData(selectedPeriodeId, 1));
    document.getElementById('filter-status')?.addEventListener('change', () => loadPenetapanData(selectedPeriodeId, 1));

    // Pagination Button Listeners
    document.getElementById('btn-prev-penetapan')?.addEventListener('click', () => {
        if (currentPage > 1) {
            loadPenetapanData(selectedPeriodeId, currentPage - 1);
        }
    });

    document.getElementById('btn-next-penetapan')?.addEventListener('click', () => {
        if (currentPage < totalPages) {
            loadPenetapanData(selectedPeriodeId, currentPage + 1);
        }
    });

    // 5. Toggle Pengumuman Button Listener
    const btnTogglePengumuman = document.getElementById('btn-toggle-pengumuman');
    if (btnTogglePengumuman) {
        btnTogglePengumuman.addEventListener('click', handleTogglePengumuman);
    }

    // 6. Check All Toggle
    document.getElementById('check-all')?.addEventListener('change', (e) => {
        const isChecked = e.target.checked;
        selectedIds.clear();
        document.querySelectorAll('.chk-mhs').forEach(chk => {
            chk.checked = isChecked;
            if (isChecked) {
                selectedIds.add(parseInt(chk.value));
            }
        });
        updateBulkToolbar();
    });

    // 7. Bulk Action Buttons Listeners
    document.getElementById('btn-bulk-approve')?.addEventListener('click', handleBulkApprove);
    document.getElementById('btn-bulk-relocate')?.addEventListener('click', handleBulkRelocateOpen);
    document.getElementById('btn-bulk-reject')?.addEventListener('click', handleBulkReject);

    // Form Bulk Relocate Submit
    document.getElementById('form-bulk-relocate')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-bulk-relocate');
        btn.disabled = true;
        btn.innerText = 'Memproses...';

        const payload = {
            pendaftaran_ids: Array.from(selectedIds),
            status: 'dipindahkan',
            new_unit_pelaksana_periode_id: parseInt(document.getElementById('bulk-relocate-new-unit').value),
            catatan_admin: document.getElementById('bulk-relocate-catatan').value
        };

        try {
            const res = await fetch('/api/admin/penetapan/bulk_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                showAdminToast(data.message || 'Berhasil memindahkan mahasiswa terpilih.', 'success');
                hideModal('modal-bulk-relocate');
                selectedIds.clear();
                updateBulkToolbar();
                loadPenetapanData(selectedPeriodeId, currentPage);
            } else {
                showAdminAlert(data.error || 'Gagal memproses pemindahan massal.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        }

        btn.disabled = false;
        btn.innerText = 'Proses Pemindahan Massal';
    });

    // 8. Individual Modals Submit Handlers
    document.getElementById('form-relocate')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const pendaftaranId = parseInt(document.getElementById('relocate-pendaftaran-id').value);
        const targetUppId = parseInt(document.getElementById('relocate-new-unit').value);
        const targetUnit = unitList.find(u => parseInt(u.upp_id) === targetUppId);
        const mhsItem = allPenetapanData.find(item => parseInt(item.pendaftaran_id) === pendaftaranId);

        if (targetUnit && mhsItem && Array.isArray(targetUnit.prodi_ids) && targetUnit.prodi_ids.length > 0) {
            const mhsJurId = parseInt(mhsItem.jurusan_id);
            if (!targetUnit.prodi_ids.includes(mhsJurId)) {
                const proceed = await showAdminConfirm(
                    `Unit tujuan (${targetUnit.nama}) tidak membuka alokasi untuk Program Studi ${mhsItem.jurusan_nama || 'mahasiswa ini'}. Apakah Anda yakin tetap ingin memindahkan paksa?`,
                    'Peringatan Kesesuaian Prodi',
                    'warning',
                    'Ya, Tetap Pindahkan',
                    'Batal'
                );
                if (!proceed) return;
            }
        }

        const btn = document.getElementById('btn-submit-relocate');
        btn.disabled = true;
        btn.innerText = 'Memproses...';

        const payload = {
            pendaftaran_id: pendaftaranId,
            status: 'dipindahkan',
            new_unit_pelaksana_periode_id: targetUppId,
            catatan_admin: document.getElementById('relocate-catatan').value
        };

        try {
            const res = await fetch('/api/admin/penetapan/update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                showAdminToast(data.message || 'Mahasiswa berhasil dipindahkan ke unit baru.', 'success');
                hideModal('modal-relocate');
                e.target.reset();
                loadPenetapanData(selectedPeriodeId, currentPage);
            } else {
                showAdminAlert(data.error || 'Gagal memindahkan unit.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        }

        btn.disabled = false;
        btn.innerText = 'Pindahkan Paksa Unit';
    });

    document.getElementById('form-approve')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-approve');
        btn.disabled = true;
        btn.innerText = 'Memproses...';

        const payload = {
            pendaftaran_id: parseInt(document.getElementById('approve-pendaftaran-id').value),
            status: 'diterima',
            catatan_admin: document.getElementById('approve-catatan').value
        };

        try {
            const res = await fetch('/api/admin/penetapan/update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                showAdminToast(data.message || 'Penetapan mahasiswa berhasil disetujui.', 'success');
                hideModal('modal-approve');
                e.target.reset();
                loadPenetapanData(selectedPeriodeId, currentPage);
            } else {
                showAdminAlert(data.error || 'Gagal menyetujui penetapan.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        }

        btn.disabled = false;
        btn.innerText = 'Setujui Penetapan';
    });

    document.getElementById('form-reject')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-reject');
        btn.disabled = true;
        btn.innerText = 'Memproses...';

        const payload = {
            pendaftaran_id: parseInt(document.getElementById('reject-pendaftaran-id').value),
            status: 'ditolak',
            catatan_admin: document.getElementById('reject-catatan').value
        };

        try {
            const res = await fetch('/api/admin/penetapan/update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                showAdminToast(data.message || 'Pendaftaran ditolak.', 'success');
                hideModal('modal-reject');
                e.target.reset();
                loadPenetapanData(selectedPeriodeId, currentPage);
            } else {
                showAdminAlert(data.error || 'Gagal menolak pendaftaran.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        }

        btn.disabled = false;
        btn.innerText = 'Tolak Pendaftaran';
    });
});

async function loadUnitList() {
    try {
        const res = await fetch('/api/admin/unit/list.php');
        const data = await res.json();
        if (data.ok && data.data) {
            unitList = data.data;

            // Populate relocate selects
            const selectElem = document.getElementById('relocate-new-unit');
            const bulkSelect = document.getElementById('bulk-relocate-new-unit');
            const filterUnit = document.getElementById('filter-unit');

            if (selectElem) selectElem.innerHTML = '<option value="">-- Pilih Unit Pelaksana Tujuan --</option>';
            if (bulkSelect) bulkSelect.innerHTML = '<option value="">-- Pilih Unit Pelaksana Tujuan --</option>';
            if (filterUnit) filterUnit.innerHTML = '<option value="">Semua Unit Penempatan</option>';

            unitList.forEach(u => {
                const sisa = u.kuota_tersisa !== null ? u.kuota_tersisa : 0;
                const optHtml = `<option value="${u.upp_id}" ${sisa <= 0 ? 'disabled' : ''}>${escapeHtml(u.nama)} (Sisa Kuota: ${sisa})</option>`;
                if (selectElem) selectElem.innerHTML += optHtml;
                if (bulkSelect) bulkSelect.innerHTML += optHtml;
                if (filterUnit && u.upp_id) filterUnit.innerHTML += `<option value="${u.upp_id}">${escapeHtml(u.nama)}</option>`;
            });
        }
    } catch (e) {
        console.error('Gagal memuat unit:', e);
    }
}

async function loadPenetapanData(targetPeriodeId = null, page = 1) {
    const tbody = document.getElementById('table-penetapan');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #64748b; padding: 24px;">Memuat data pendaftaran & penetapan...</td></tr>';
    }
    
    const selectEl = document.getElementById('select-periode-penetapan');
    if (targetPeriodeId) selectedPeriodeId = targetPeriodeId;
    currentPage = page;

    // Baca filter saat ini
    const searchVal = document.getElementById('filter-search')?.value.trim() || '';
    const jurusanVal = document.getElementById('filter-jurusan')?.value || '';
    const unitVal = document.getElementById('filter-unit')?.value || '';
    const programVal = document.getElementById('filter-program')?.value || '';
    const statusVal = document.getElementById('filter-status')?.value || '';

    try {
        const params = new URLSearchParams();
        if (selectedPeriodeId) params.set('periode_id', selectedPeriodeId);
        params.set('page', currentPage);
        params.set('per_page', perPage);
        if (searchVal) params.set('search', searchVal);
        if (jurusanVal) params.set('filter_jurusan_id', jurusanVal);
        if (unitVal) params.set('filter_unit_id', unitVal);
        if (programVal) params.set('filter_program', programVal);
        if (statusVal) params.set('filter_status', statusVal);

        const url = `/api/admin/penetapan/list.php?${params.toString()}`;
        const res = await fetch(url);
        const data = await res.json();

        if (data.ok) {
            currentPeriodeData = data.periode_terpilih;

            // Populate period select dropdown
            if (data.all_periode && selectEl && !isPeriodeSelectPopulated) {
                selectEl.innerHTML = '';
                data.all_periode.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.innerText = `${p.nama} (${(p.status || 'DRAFT').toUpperCase()})`;
                    if (currentPeriodeData && parseInt(p.id) === parseInt(currentPeriodeData.id)) {
                        opt.selected = true;
                        selectedPeriodeId = p.id;
                    }
                    selectEl.appendChild(opt);
                });

                isPeriodeSelectPopulated = true;
                selectEl.addEventListener('change', (e) => {
                    selectedPeriodeId = e.target.value;
                    loadPenetapanData(selectedPeriodeId, 1);
                });
            }

            // Update Info Periode Text cleanly
            const infoEl = document.getElementById('info-periode');
            const btnExport = document.getElementById('btn-export-penetapan');
            const p = currentPeriodeData;

            if (btnExport && p) {
                btnExport.href = `/api/admin/penetapan/export.php?periode_id=${p.id}`;
            }

            if (!p) {
                if (infoEl) infoEl.innerHTML = '<span style="color: #ef4444; font-weight: 600;">Belum ada periode magang yang dibuat.</span>';
            } else {
                const st = (p.status || '').toLowerCase().trim();
                if (infoEl) {
                    if (st === 'dibuka') {
                        infoEl.innerHTML = `<span style="color: #059669; font-weight: 700;">● Periode Aktif: ${escapeHtml(p.nama)} (DIBUKA)</span>`;
                    } else if (st === 'persiapan') {
                        infoEl.innerHTML = `<span style="color: #d97706; font-weight: 700;">● Periode Persiapan: ${escapeHtml(p.nama)} (PERSIAPAN - SETTING KUOTA)</span>`;
                    } else {
                        infoEl.innerHTML = `<span style="color: #64748b; font-weight: 600;">(Tidak Ada Periode Aktif) Periode Terpilih: ${escapeHtml(p.nama)} [STATUS: ${st.toUpperCase()}]</span>`;
                    }
                }
            }

            // Update Announcement Banner UI
            updateAnnouncementBanner(currentPeriodeData);

            if (data.jurusan_list && jurusanList.length === 0) {
                jurusanList = data.jurusan_list;
                const selectJurusan = document.getElementById('filter-jurusan');
                if (selectJurusan) {
                    selectJurusan.innerHTML = '<option value="">Semua Jurusan</option>';
                    jurusanList.forEach(j => {
                        selectJurusan.innerHTML += `<option value="${j.id}">${escapeHtml(j.nama)}</option>`;
                    });
                }
            }

            allPenetapanData = data.data || [];

            // Update Pagination
            if (data.pagination) {
                totalRecords = data.pagination.total;
                totalPages = data.pagination.total_pages;
                currentPage = data.pagination.page;
                updatePenetapanPagination();
            }

            const badgeElem = document.getElementById('filtered-count-badge');
            if (badgeElem) badgeElem.innerText = `${allPenetapanData.length} Baris (${totalRecords} Total)`;

            renderTable(allPenetapanData);
        }
    } catch (err) {
        console.error(err);
        if (tbody) tbody.innerHTML = '<tr><td colspan="8" style="color: #ef4444; text-align: center; padding: 24px;">Gagal memuat data penetapan.</td></tr>';
    }
}

function updatePenetapanPagination() {
    const infoEl = document.getElementById('pagination-penetapan-info');
    const pageNumEl = document.getElementById('pagination-penetapan-num');
    const btnPrev = document.getElementById('btn-prev-penetapan');
    const btnNext = document.getElementById('btn-next-penetapan');

    if (!infoEl || !btnPrev || !btnNext) return;

    const start = totalRecords === 0 ? 0 : (currentPage - 1) * perPage + 1;
    const end = Math.min(currentPage * perPage, totalRecords);

    infoEl.innerHTML = totalRecords === 0 ? 'Menampilkan <strong>0</strong> data' : `Menampilkan <strong>${start}–${end}</strong> dari <strong>${totalRecords}</strong> data`;
    if (pageNumEl) pageNumEl.innerText = `Halaman ${currentPage} / ${totalPages || 1}`;

    btnPrev.disabled = (currentPage <= 1);
    btnNext.disabled = (currentPage >= totalPages || totalPages === 0);

}

function updateAnnouncementBanner(periode) {
    const card = document.getElementById('announcement-banner-card');
    const badge = document.getElementById('announcement-status-badge');
    const desc = document.getElementById('announcement-desc');
    const btn = document.getElementById('btn-toggle-pengumuman');
    const btnText = document.getElementById('btn-toggle-pengumuman-text');
    const pulseDot = document.getElementById('announcement-pulse-dot');

    if (!card || !badge || !desc || !btn || !btnText) return;

    if (!periode) {
        card.style.display = 'none';
        return;
    }

    card.style.display = 'flex';
    const isPub = Boolean(parseInt(periode.pengumuman_dibuka, 10));

    if (isPub) {
        card.className = 'announcement-control-card is-published';
        if (pulseDot) pulseDot.className = 'status-pulse-dot dot-published';
        badge.className = 'badge-status-pill badge-pill-published';
        badge.innerText = 'SUDAH DIPUBLIKASIKAN';
        desc.innerHTML = `Hasil penetapan resmi periode <strong>${escapeHtml(periode.nama)}</strong> sudah aktif dan dapat dilihat langsung oleh seluruh mahasiswa.`;
        
        btn.className = 'btn-announcement-toggle btn-announcement-unpublish';
        btnText.innerText = 'Tutup / Sembunyikan';
        btn.querySelector('svg').innerHTML = '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>';
    } else {
        card.className = 'announcement-control-card is-draft';
        if (pulseDot) pulseDot.className = 'status-pulse-dot dot-draft';
        badge.className = 'badge-status-pill badge-pill-draft';
        badge.innerText = 'BELUM DIPUBLIKASIKAN';
        desc.innerHTML = `Mahasiswa pendaftar saat ini hanya melihat status pending (<em>"Dalam Proses Seleksi"</em>).`;

        btn.className = 'btn-announcement-toggle btn-announcement-publish';
        btnText.innerText = 'Publikasikan Sekarang';
        btn.querySelector('svg').innerHTML = '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>';
    }
}

async function handleTogglePengumuman() {
    if (!currentPeriodeData) {
        showAdminAlert('Tidak ada periode magang yang dipilih.', 'warning');
        return;
    }

    const currentStatus = Boolean(parseInt(currentPeriodeData.pengumuman_dibuka, 10));
    const newStatus = !currentStatus;

    const confirmTitle = newStatus ? 'Publikasikan Pengumuman Penetapan?' : 'Tutup / Sembunyikan Pengumuman?';
    const confirmMessage = newStatus 
        ? `Apakah Anda yakin ingin mempublikasikan hasil penetapan periode "${currentPeriodeData.nama}" kepada seluruh mahasiswa? Seluruh mahasiswa pendaftar akan dapat melihat status resmi Diterima, Dipindahkan, atau Ditolak.`
        : `Apakah Anda yakin ingin menutup pengumuman periode "${currentPeriodeData.nama}"? Tampilan mahasiswa pendaftar akan kembali disamarkan menjadi status Pending / Menunggu Pengumuman.`;

    const confirmType = newStatus ? 'warning' : 'danger';
    const confirmButtonText = newStatus ? 'Ya, Publikasikan Sekarang' : 'Ya, Sembunyikan Pengumuman';

    const confirmed = await showAdminConfirm(confirmMessage, confirmTitle, confirmType, confirmButtonText, 'Batal');
    if (!confirmed) return;

    const btn = document.getElementById('btn-toggle-pengumuman');
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
    }

    try {
        const res = await fetch('/api/admin/penetapan/toggle_pengumuman.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                periode_id: parseInt(currentPeriodeData.id, 10),
                pengumuman_dibuka: newStatus ? 1 : 0
            })
        });

        const data = await res.json();
        if (res.ok && data.ok) {
            showAdminToast(data.message, 'success');
            await loadPenetapanData(currentPeriodeData.id, currentPage);
        } else {
            showAdminAlert(data.error || 'Gagal mengubah status pengumuman.', 'error');
        }
    } catch (err) {
        showAdminAlert('Terjadi kesalahan jaringan atau server.', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.style.opacity = '1';
        }
    }
}

function renderTable(dataArray) {
    const tbody = document.getElementById('table-penetapan');
    if (!tbody) return;
    tbody.innerHTML = '';

    const checkAll = document.getElementById('check-all');
    if (checkAll) checkAll.checked = false;

    if (dataArray.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #64748b; padding: 24px;">Tidak ada data pendaftaran yang memenuhi filter.</td></tr>';
        return;
    }

    dataArray.forEach(p => {
        let badgeClass = 'badge-draft';
        let statusLabel = 'Belum Ditetapkan';

        if (p.status === 'diterima') {
            badgeClass = 'badge-dibuka';
            statusLabel = 'Diterima (Pilihan Awal)';
        } else if (p.status === 'dipindahkan' || p.is_dipindahkan == 1) {
            badgeClass = 'badge-diarsipkan';
            statusLabel = 'Dipindahkan Paksa';
        } else if (p.status === 'ditolak') {
            badgeClass = 'badge-ditutup';
            statusLabel = 'Ditolak';
        } else if (p.status === 'diverifikasi') {
            badgeClass = 'badge-draft';
            statusLabel = 'Diverifikasi';
        }

        const progBadge = p.program === '1_bulan' 
            ? '<span style="font-weight: 600; font-size: 0.8rem; color: #0b3d6b; background: #e0f2fe; padding: 4px 10px; border-radius: 6px;">1 Bulan</span>'
            : '<span style="font-weight: 600; font-size: 0.8rem; color: #4338ca; background: #e0e7ff; padding: 4px 10px; border-radius: 6px;">5 Bulan (KRS)</span>';

        const unitAwal = p.unit_asal_nama ? p.unit_asal_nama : p.unit_nama;
        const unitHasil = p.unit_nama;
        const isMoved = p.is_dipindahkan == 1;
        const isChecked = selectedIds.has(p.pendaftaran_id);

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="text-align: center;">
                <input type="checkbox" class="chk-mhs" value="${p.pendaftaran_id}" ${isChecked ? 'checked' : ''} style="width: 18px; height: 18px; accent-color: #0b3d6b; cursor: pointer;" />
            </td>
            <td>
                <div style="font-weight: 700; color: #0f172a;">${escapeHtml(p.nama)}</div>
                <div style="font-size: 0.8rem; color: #64748b; font-family: monospace;">NIM: ${escapeHtml(p.nim)}</div>
            </td>
            <td style="font-size: 0.85rem; color: #475569; font-weight: 600;">${escapeHtml(p.jurusan_nama || '-')}</td>
            <td>${progBadge}</td>
            <td style="color: #475569; font-size: 0.875rem;">${escapeHtml(unitAwal)}</td>
            <td>
                <div style="font-weight: 700; color: ${isMoved ? '#d97706' : '#0b3d6b'};">${escapeHtml(unitHasil)}</div>
                ${isMoved ? '<span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.725rem; background: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-top: 2px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Dipindahkan</span>' : ''}
            </td>
            <td><span class="badge-status ${badgeClass}">${statusLabel}</span></td>
            <td>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; font-size: 0.775rem; background: #e0f2fe; border: 1px solid #bae6fd; color: #0284c7; font-weight: 700; border-radius: 8px; cursor: pointer;" title="Pratinjau Dokumen Transkrip Nilai (PDF)" onclick="openPdfPreviewModal('/api/admin/transkrip/download.php?pendaftaran_id=${p.pendaftaran_id}', '${escapeHtml(p.nama).replace(/'/g, "\\'")}', 'NIM: ${escapeHtml(p.nim)}')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 12 15 15"/></svg>
                        <span>Transkrip</span>
                    </button>
                    <button type="button" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; font-size: 0.775rem; background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; font-weight: 700; border-radius: 8px; cursor: pointer;" onclick="openApproveModal(${p.pendaftaran_id}, '${escapeHtml(p.nama).replace(/'/g, "\\'")}', '${escapeHtml(p.unit_nama).replace(/'/g, "\\'")}')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>Setujui</span>
                    </button>
                    <button type="button" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; font-size: 0.775rem; background: #fffbebf5; border: 1px solid #fde68a; color: #d97706; font-weight: 700; border-radius: 8px; cursor: pointer;" onclick="openRelocateModal(${p.pendaftaran_id}, '${escapeHtml(p.nama).replace(/'/g, "\\'")}', '${escapeHtml(unitAwal).replace(/'/g, "\\'")}')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                        <span>Pindahkan</span>
                    </button>
                    <button type="button" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; font-size: 0.775rem; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; font-weight: 700; border-radius: 8px; cursor: pointer;" onclick="openRejectModal(${p.pendaftaran_id}, '${escapeHtml(p.nama).replace(/'/g, "\\'")}')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <span>Tolak</span>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    // Attach listeners to row checkboxes
    document.querySelectorAll('.chk-mhs').forEach(chk => {
        chk.addEventListener('change', (e) => {
            const pid = parseInt(e.target.value);
            if (e.target.checked) {
                selectedIds.add(pid);
            } else {
                selectedIds.delete(pid);
            }
            updateBulkToolbar();
        });
    });
}

function updateBulkToolbar() {
    const toolbar = document.getElementById('bulk-toolbar');
    const countElem = document.getElementById('selected-count');
    const size = selectedIds.size;

    if (countElem) countElem.innerText = size;

    if (size > 0) {
        if (toolbar) toolbar.classList.add('active');
    } else {
        if (toolbar) toolbar.classList.remove('active');
    }
}

// Bulk Actions Handlers
async function handleBulkApprove() {
    if (selectedIds.size === 0) return;
    const confirmed = await showAdminConfirm(`Konfirmasi menyetujui penetapan ${selectedIds.size} mahasiswa terpilih pada unit pilihan awal mereka?`, 'Setujui Penetapan Massal', 'info', 'Ya, Setujui', 'Batal');
    if (!confirmed) return;

    try {
        const res = await fetch('/api/admin/penetapan/bulk_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                pendaftaran_ids: Array.from(selectedIds),
                status: 'diterima'
            })
        });
        const data = await res.json();
        if (res.ok) {
            showAdminToast(data.message || 'Berhasil menyetujui mahasiswa terpilih.', 'success');
            selectedIds.clear();
            updateBulkToolbar();
            loadPenetapanData(selectedPeriodeId, currentPage);
        } else {
            showAdminAlert(data.error || 'Gagal memproses penetapan massal.', 'error');
        }
    } catch (e) {
        showAdminAlert('Terjadi kesalahan jaringan.', 'error');
    }
}

function handleBulkRelocateOpen() {
    if (selectedIds.size === 0) return;
    document.getElementById('bulk-relocate-count').innerText = selectedIds.size;
    document.getElementById('bulk-relocate-catatan').value = '';
    showModal('modal-bulk-relocate');
}

async function handleBulkReject() {
    if (selectedIds.size === 0) return;
    const confirmed = await showAdminConfirm(`Apakah Anda yakin ingin menolak ${selectedIds.size} pendaftaran mahasiswa terpilih? Kuota unit terkait akan dibebaskan.`, 'Tolak Pendaftaran Massal', 'danger', 'Ya, Tolak Semua', 'Batal');
    if (!confirmed) return;

    try {
        const res = await fetch('/api/admin/penetapan/bulk_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                pendaftaran_ids: Array.from(selectedIds),
                status: 'ditolak',
                catatan_admin: 'Ditolak massal oleh administrator'
            })
        });
        const data = await res.json();
        if (res.ok) {
            showAdminToast(data.message || 'Berhasil menolak pendaftaran mahasiswa terpilih.', 'success');
            selectedIds.clear();
            updateBulkToolbar();
            loadPenetapanData(selectedPeriodeId, currentPage);
        } else {
            showAdminAlert(data.error || 'Gagal memproses penolakan massal.', 'error');
        }
    } catch (e) {
        showAdminAlert('Terjadi kesalahan jaringan.', 'error');
    }
}

// Individual Modal Openers
window.openRelocateModal = function(id, nama, unitAsal) {
    document.getElementById('relocate-pendaftaran-id').value = id;
    document.getElementById('relocate-mhs-nama').innerText = nama;
    document.getElementById('relocate-unit-asal').innerText = unitAsal;
    document.getElementById('relocate-catatan').value = '';
    showModal('modal-relocate');
};

window.openApproveModal = function(id, nama, unitNama) {
    document.getElementById('approve-pendaftaran-id').value = id;
    document.getElementById('approve-mhs-nama').innerText = nama;
    document.getElementById('approve-unit-nama').innerText = unitNama;
    document.getElementById('approve-catatan').value = '';
    showModal('modal-approve');
};

window.openRejectModal = function(id, nama) {
    document.getElementById('reject-pendaftaran-id').value = id;
    document.getElementById('reject-mhs-nama').innerText = nama;
    document.getElementById('reject-catatan').value = '';
    showModal('modal-reject');
};
