/**
 * public/js/admin/penetapan.js
 * Manajemen Penetapan Unit, Pemindahan Paksa, & Publikasi Pengumuman Mahasiswa
 */

import { showAdminAlert, showAdminConfirm, showAdminToast } from './common.js';
import { setupUnitPicker } from './unit_picker.js';

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

    // 2. Load Penetapan Data langsung (unit pelaksana dimuat bersamaan secara efisien)
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
        const targetUppIdVal = document.getElementById('bulk-relocate-new-unit').value;
        if (!targetUppIdVal) {
            showAdminAlert('Silakan pilih salah satu unit pelaksana tujuan terlebih dahulu.', 'warning');
            return;
        }

        const targetUppId = parseInt(targetUppIdVal, 10);
        const targetUnit = unitList.find(u => parseInt(u.upp_id, 10) === targetUppId);
        const selectedCount = selectedIds.size;

        // Validasi ketersediaan kuota unit tujuan untuk rombongan
        if (targetUnit && targetUnit.kuota_tersisa !== null && targetUnit.kuota_tersisa < selectedCount) {
            const proceedQuota = await showAdminConfirm(
                `Sisa kuota unit tujuan (${targetUnit.nama || targetUnit.nama_unit}) hanya ${targetUnit.kuota_tersisa} slot, sedangkan rombongan yang dipindahkan berjumlah ${selectedCount} mahasiswa.\n\nApakah Anda yakin ingin tetap memindahkan melebihi kapasitas kuota resmi?`,
                'Peringatan Kapasitas Kuota',
                'warning',
                'Ya, Tetap Lanjutkan',
                'Batal'
            );
            if (!proceedQuota) return;
        }

        // Validasi kesesuaian prodi seluruh mahasiswa rombongan
        if (targetUnit && Array.isArray(targetUnit.prodi_ids) && targetUnit.prodi_ids.length > 0) {
            const selectedMhs = allPenetapanData.filter(item => selectedIds.has(parseInt(item.pendaftaran_id, 10)));
            const mismatchedMhs = selectedMhs.filter(m => !targetUnit.prodi_ids.includes(parseInt(m.jurusan_id, 10)));
            if (mismatchedMhs.length > 0) {
                const proceedProdi = await showAdminConfirm(
                    `Terdapat ${mismatchedMhs.length} dari ${selectedCount} mahasiswa yang jurusannya tidak tercantum pada unit tujuan (${targetUnit.nama || targetUnit.nama_unit}).\n\nApakah Anda yakin ingin tetap memindahkan rombongan ke unit ini?`,
                    'Peringatan Kesesuaian Prodi Rombongan',
                    'warning',
                    'Ya, Tetap Pindahkan Rombongan',
                    'Batal'
                );
                if (!proceedProdi) return;
            }
        }

        const btn = document.getElementById('btn-submit-bulk-relocate');
        btn.disabled = true;
        btn.innerText = 'Memproses...';

        const bulkCatatan = document.getElementById('bulk-relocate-catatan').value.trim();
        const payload = {
            pendaftaran_ids: Array.from(selectedIds),
            status: 'dipindahkan',
            action: 'dipindahkan',
            new_unit_pelaksana_periode_id: targetUppId,
            catatan_admin: bulkCatatan,
            alasan_pemindahan: bulkCatatan,
            catatan: bulkCatatan
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
        
        const pendaftaranId = parseInt(document.getElementById('relocate-pendaftaran-id').value, 10);
        const targetUppIdVal = document.getElementById('relocate-new-unit').value;
        if (!targetUppIdVal) {
            showAdminAlert('Silakan pilih salah satu unit pelaksana tujuan terlebih dahulu.', 'warning');
            return;
        }

        const targetUppId = parseInt(targetUppIdVal, 10);
        const targetUnit = unitList.find(u => parseInt(u.upp_id, 10) === targetUppId);
        const mhsItem = allPenetapanData.find(item => parseInt(item.pendaftaran_id, 10) === pendaftaranId);

        // Validasi kuota unit penuh
        if (targetUnit && targetUnit.kuota_tersisa !== null && targetUnit.kuota_tersisa <= 0) {
            const proceedQuota = await showAdminConfirm(
                `Sisa kuota pada unit tujuan (${targetUnit.nama || targetUnit.nama_unit}) telah penuh (0 slot).\n\nApakah Anda yakin tetap ingin memaksakan pemindahan mahasiswa ke unit ini?`,
                'Peringatan Kuota Penuh',
                'warning',
                'Ya, Tetap Pindahkan',
                'Batal'
            );
            if (!proceedQuota) return;
        }

        // Validasi kesesuaian prodi individual
        if (targetUnit && mhsItem && Array.isArray(targetUnit.prodi_ids) && targetUnit.prodi_ids.length > 0) {
            const mhsJurId = parseInt(mhsItem.jurusan_id, 10);
            if (!targetUnit.prodi_ids.includes(mhsJurId)) {
                const proceedProdi = await showAdminConfirm(
                    `Unit tujuan (${targetUnit.nama || targetUnit.nama_unit}) tidak membuka alokasi untuk Program Studi ${mhsItem.jurusan_nama || 'mahasiswa ini'}.\n\nApakah Anda yakin tetap ingin memindahkan paksa?`,
                    'Peringatan Kesesuaian Prodi',
                    'warning',
                    'Ya, Tetap Pindahkan',
                    'Batal'
                );
                if (!proceedProdi) return;
            }
        }

        const btn = document.getElementById('btn-submit-relocate');
        btn.disabled = true;
        btn.innerText = 'Memproses...';

        const catatanVal = document.getElementById('relocate-catatan').value.trim();
        const payload = {
            pendaftaran_id: pendaftaranId,
            status: 'dipindahkan',
            new_unit_pelaksana_periode_id: targetUppId,
            catatan_admin: catatanVal,
            alasan_pemindahan: catatanVal,
            catatan: catatanVal
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
    // Unit data sekarang dibundle secara efisien langsung di dalam loadPenetapanData
    // untuk mencegah request terpisah 741KB ke master unit dan freeze pada DOM browser.
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
                    let jOpts = '<option value="">Semua Jurusan</option>';
                    jurusanList.forEach(j => {
                        jOpts += `<option value="${j.id}">${escapeHtml(j.nama)}</option>`;
                    });
                    selectJurusan.innerHTML = jOpts;
                }
            }

            // Simpan daftar unit & isi filter-unit
            if (data.unit_list && Array.isArray(data.unit_list)) {
                unitList = data.unit_list;
                const filterUnit = document.getElementById('filter-unit');
                let filterOpts = '<option value="">Semua Unit Penempatan</option>';
                const currentFilterVal = filterUnit ? filterUnit.value : '';

                unitList.forEach(u => {
                    const isSelected = String(u.upp_id) === String(currentFilterVal);
                    filterOpts += `<option value="${u.upp_id}" ${isSelected ? 'selected' : ''}>${escapeHtml(u.nama)}</option>`;
                });

                if (filterUnit) filterUnit.innerHTML = filterOpts;
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

        if (p.status === 'ditolak') {
            badgeClass = 'badge-ditutup';
            statusLabel = 'Ditolak';
        } else if (p.status === 'diterima') {
            badgeClass = 'badge-dibuka';
            statusLabel = 'Diterima';
        } else if (p.status === 'dipindahkan' || (p.is_dipindahkan == 1 && p.status !== 'ditolak')) {
            badgeClass = 'badge-diarsipkan';
            statusLabel = 'Dipindahkan Paksa';
        } else if (p.status === 'diverifikasi') {
            badgeClass = 'badge-draft';
            statusLabel = 'Diverifikasi';
        }

        let progBadge = '<span style="font-weight: 600; font-size: 0.8rem; color: #4338ca; background: #e0e7ff; padding: 4px 10px; border-radius: 6px;">5 Bulan (KRS)</span>';
        if (p.program === '1_bulan') {
            progBadge = '<span style="font-weight: 600; font-size: 0.8rem; color: #0b3d6b; background: #e0f2fe; padding: 4px 10px; border-radius: 6px;">1 Bulan</span>';
        } else if (p.program === '3_bulan') {
            progBadge = '<span style="font-weight: 600; font-size: 0.8rem; color: #92400e; background: #fef3c7; padding: 4px 10px; border-radius: 6px;">3 Bulan</span>';
        } else if (p.program === '4_bulan') {
            progBadge = '<span style="font-weight: 600; font-size: 0.8rem; color: #1e1b4b; background: #ede9fe; padding: 4px 10px; border-radius: 6px;">4 Bulan</span>';
        }

        const unitAwal = p.unit_asal_nama ? p.unit_asal_nama : p.unit_nama;
        const unitHasil = p.unit_nama;
        const isMoved = (p.is_dipindahkan == 1 || p.status === 'dipindahkan') && p.status !== 'ditolak';
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
            <td style="white-space: nowrap;">
                <div class="action-cell-stack">
                    <div class="action-row-docs">
                        <span class="action-row-label">Dokumen:</span>
                        ${p.transkrip_path ? (() => {
                            const isLink = typeof p.transkrip_path === 'string' && (p.transkrip_path.startsWith('http://') || p.transkrip_path.startsWith('https://'));
                            const targetUrl = isLink ? p.transkrip_path : `/api/admin/transkrip/download.php?pendaftaran_id=${p.pendaftaran_id}`;
                            return `
                            <a href="${escapeHtml(targetUrl)}" target="_blank" rel="noopener noreferrer" class="btn-doc-pill pill-transkrip" title="${isLink ? 'Buka Tautan: ' + escapeHtml(p.transkrip_path) : 'Buka Dokumen Transkrip Nilai (PDF) di Tab Baru'}">
                                ${isLink 
                                    ? `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`
                                    : `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`}
                                <span>${isLink ? 'Link Transkrip' : 'Transkrip'}</span>
                            </a>`;
                        })() : ''}
                        ${p.cv_path ? (() => {
                            const isLink = typeof p.cv_path === 'string' && (p.cv_path.startsWith('http://') || p.cv_path.startsWith('https://'));
                            const targetUrl = isLink ? p.cv_path : `/api/admin/cv/download.php?pendaftaran_id=${p.pendaftaran_id}`;
                            return `
                            <a href="${escapeHtml(targetUrl)}" target="_blank" rel="noopener noreferrer" class="btn-doc-pill pill-cv" title="${isLink ? 'Buka Tautan: ' + escapeHtml(p.cv_path) : 'Buka Dokumen Curriculum Vitae / CV (PDF) di Tab Baru'}">
                                ${isLink 
                                    ? `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`
                                    : `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><circle cx="12" cy="12" r="2.5"/><path d="M8 18c0-1.8 1.8-3 4-3s4 1.2 4 3"/></svg>`}
                                <span>${isLink ? 'Link CV' : 'CV'}</span>
                            </a>`;
                        })() : ''}
                        ${p.porto_path ? (() => {
                            const isLink = typeof p.porto_path === 'string' && (p.porto_path.startsWith('http://') || p.porto_path.startsWith('https://'));
                            const targetUrl = isLink ? p.porto_path : `/api/admin/porto/download.php?pendaftaran_id=${p.pendaftaran_id}`;
                            return `
                            <a href="${escapeHtml(targetUrl)}" target="_blank" rel="noopener noreferrer" class="btn-doc-pill pill-porto" title="${isLink ? 'Buka Tautan: ' + escapeHtml(p.porto_path) : 'Buka Dokumen Portofolio (PDF) di Tab Baru'}">
                                ${isLink 
                                    ? `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`
                                    : `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>`}
                                <span>${isLink ? 'Link Porto' : 'Porto'}</span>
                            </a>`;
                        })() : ''}
                        ${(!p.transkrip_path && !p.cv_path && !p.porto_path) ? '<span class="doc-empty-text">Tidak ada dokumen</span>' : ''}
                    </div>
                    <div class="action-row-decision">
                        <button type="button" class="btn-action-pill pill-approve" onclick="openApproveModal(${p.pendaftaran_id}, '${escapeHtml(p.nama).replace(/'/g, "\\'")}', '${escapeHtml(p.unit_nama).replace(/'/g, "\\'")}')" title="Setujui dan Tetapkan Unit">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Setujui</span>
                        </button>
                        <button type="button" class="btn-action-pill pill-relocate" onclick="openRelocateModal(${p.pendaftaran_id}, '${escapeHtml(p.nama).replace(/'/g, "\\'")}', '${escapeHtml(unitAwal).replace(/'/g, "\\'")}')" title="Pindahkan Paksa ke Unit Lain">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                            <span>Pindahkan</span>
                        </button>
                        <button type="button" class="btn-action-pill pill-reject" onclick="openRejectModal(${p.pendaftaran_id}, '${escapeHtml(p.nama).replace(/'/g, "\\'")}')" title="Tolak Penetapan">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            <span>Tolak</span>
                        </button>
                    </div>
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
    const confirmed = await showAdminConfirm(`Konfirmasi menyetujui penetapan ${selectedIds.size} mahasiswa terpilih pada unit penempatan mereka?`, 'Setujui Penetapan Massal', 'info', 'Ya, Setujui', 'Batal');
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
    const selectedMhs = allPenetapanData.filter(item => selectedIds.has(parseInt(item.pendaftaran_id, 10)));
    
    document.getElementById('bulk-relocate-count').innerText = selectedIds.size;
    document.getElementById('bulk-relocate-catatan').value = '';

    const distinctProdis = Array.from(new Set(selectedMhs.map(m => m.jurusan_nama).filter(Boolean)));
    const summaryEl = document.getElementById('bulk-prodi-summary');
    if (summaryEl) {
        summaryEl.innerText = distinctProdis.length > 0 ? distinctProdis.join(', ') : 'Semua Jurusan';
    }

    showModal('modal-bulk-relocate');

    setupUnitPicker({
        searchInputId: 'bulk-relocate-search-input',
        clearBtnId: 'bulk-relocate-search-clear',
        statsCountId: 'bulk-relocate-stats-count',
        cardsContainerId: 'bulk-relocate-cards-container',
        hiddenInputId: 'bulk-relocate-new-unit',
        units: unitList,
        context: {
            isBulk: true,
            selectedMhs: selectedMhs,
            requiredCount: selectedMhs.length
        }
    });
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
                catatan_admin: 'Formasi Anda belum memenuhi kebutuhan kami'
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
    const mhsItem = allPenetapanData.find(item => parseInt(item.pendaftaran_id, 10) === parseInt(id, 10));
    document.getElementById('relocate-pendaftaran-id').value = id;
    document.getElementById('relocate-mhs-nama').innerText = (mhsItem && mhsItem.nama) ? mhsItem.nama : nama;
    
    const nimEl = document.getElementById('relocate-mhs-nim');
    if (nimEl) {
        nimEl.innerText = (mhsItem && mhsItem.nim) ? mhsItem.nim : '-';
    }

    const prodiEl = document.getElementById('relocate-mhs-prodi');
    if (prodiEl) {
        prodiEl.innerText = (mhsItem && mhsItem.jurusan_nama) ? mhsItem.jurusan_nama : '-';
    }

    document.getElementById('relocate-unit-asal').innerText = unitAsal;
    document.getElementById('relocate-catatan').value = '';
    
    showModal('modal-relocate');

    setupUnitPicker({
        searchInputId: 'relocate-search-input',
        clearBtnId: 'relocate-search-clear',
        statsCountId: 'relocate-stats-count',
        cardsContainerId: 'relocate-cards-container',
        hiddenInputId: 'relocate-new-unit',
        units: unitList,
        context: {
            isBulk: false,
            mhs: mhsItem
        }
    });
};

window.openApproveModal = function(id, nama, unitNama) {
    const mhsItem = allPenetapanData.find(item => parseInt(item.pendaftaran_id, 10) === parseInt(id, 10));
    document.getElementById('approve-pendaftaran-id').value = id;
    document.getElementById('approve-mhs-nama').innerText = (mhsItem && mhsItem.nama) ? mhsItem.nama : nama;
    const nimEl = document.getElementById('approve-mhs-nim');
    if (nimEl) {
        nimEl.innerText = (mhsItem && mhsItem.nim) ? mhsItem.nim : '-';
    }
    document.getElementById('approve-unit-nama').innerText = unitNama;
    document.getElementById('approve-catatan').value = '';
    showModal('modal-approve');
};

window.openRejectModal = function(id, nama) {
    const mhsItem = allPenetapanData.find(item => parseInt(item.pendaftaran_id, 10) === parseInt(id, 10));
    document.getElementById('reject-pendaftaran-id').value = id;
    document.getElementById('reject-mhs-nama').innerText = (mhsItem && mhsItem.nama) ? mhsItem.nama : nama;
    const nimEl = document.getElementById('reject-mhs-nim');
    if (nimEl) {
        nimEl.innerText = (mhsItem && mhsItem.nim) ? mhsItem.nim : '-';
    }
    document.getElementById('reject-catatan').value = '';
    showModal('modal-reject');
};
