/**
 * public/js/admin/jurusan.js
 * Modul Master Program Studi (Jurusan) Admin REMATE ITPLN
 */

import { showAdminAlert, showAdminConfirm } from './common.js';

export function escapeHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

let csrfToken = null;
let jurusanList = [];

document.addEventListener('DOMContentLoaded', async () => {
    // 1. Auth & CSRF
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
    } catch (err) {
        window.location.href = '/admin/login.html';
        return;
    }

    // 2. Load Data
    await loadJurusan();

    // 3. Search & Filters
    const searchInput = document.getElementById('search-jurusan');
    const filterJenjang = document.getElementById('filter-jenjang-jurusan');
    const filterStatus = document.getElementById('filter-status-jurusan');

    function applyFilter() {
        const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const jj = filterJenjang ? filterJenjang.value : '';
        const st = filterStatus ? filterStatus.value : '';

        const filtered = jurusanList.filter(j => {
            const matchQuery = !q || (j.kode || '').toLowerCase().includes(q) || (j.nama_jurusan || '').toLowerCase().includes(q);
            const matchJenjang = !jj || (j.jenjang || '').toUpperCase() === jj.toUpperCase();
            const matchStatus = st === '' || (st === '1' && j.aktif) || (st === '0' && !j.aktif);
            return matchQuery && matchJenjang && matchStatus;
        });

        renderTable(filtered);
    }

    if (searchInput) searchInput.addEventListener('input', applyFilter);
    if (filterJenjang) filterJenjang.addEventListener('change', applyFilter);
    if (filterStatus) filterStatus.addEventListener('change', applyFilter);

    // 4. Modal Triggers
    const btnAdd = document.getElementById('btn-add-jurusan');
    if (btnAdd) {
        btnAdd.addEventListener('click', openCreateModal);
    }

    const btnClose = document.getElementById('btn-close-modal');
    if (btnClose) btnClose.addEventListener('click', hideModal);

    const btnCancel = document.getElementById('btn-cancel-modal');
    if (btnCancel) btnCancel.addEventListener('click', hideModal);

    const form = document.getElementById('form-jurusan');
    if (form) {
        form.addEventListener('submit', handleFormSubmit);
    }

    // Modal background click to close
    const modalOverlay = document.getElementById('modal-jurusan');
    if (modalOverlay) {
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) hideModal();
        });
    }
});

async function loadJurusan() {
    const tbody = document.getElementById('table-jurusan-body');
    const badge = document.getElementById('total-count-badge');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 32px; color: #64748b;">Memuat data program studi...</td></tr>';
    }

    try {
        const res = await fetch('/api/admin/jurusan/list.php');
        const data = await res.json();
        if (data.ok && Array.isArray(data.data)) {
            jurusanList = data.data;
            if (badge) badge.innerText = `${jurusanList.length} Prodi`;
            renderTable(jurusanList);
        } else {
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 32px; color: #ef4444;">Gagal memuat data program studi.</td></tr>';
        }
    } catch (err) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 32px; color: #ef4444;">Terjadi kesalahan jaringan.</td></tr>';
    }
}

function renderTable(dataArray) {
    const tbody = document.getElementById('table-jurusan-body');
    if (!tbody) return;

    if (dataArray.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">Belum ada data program studi yang sesuai kriteria pencarian.</td></tr>';
        return;
    }

    tbody.innerHTML = '';
    dataArray.forEach((j, idx) => {
        const statusHtml = j.aktif
            ? '<span class="badge-status badge-dibuka">Aktif</span>'
            : '<span class="badge-status badge-ditutup">Nonaktif</span>';

        const isD3 = (j.jenjang || '').toUpperCase() === 'D3';
        const jenjangBadge = `
            <span style="display: inline-block; font-size: 0.775rem; font-weight: 800; background: ${isD3 ? '#fef3c7' : '#e0e7ff'}; color: ${isD3 ? '#b45309' : '#3730a3'}; padding: 3px 8px; border-radius: 6px; border: 1px solid ${isD3 ? '#fde68a' : '#c7d2fe'};">
                ${escapeHtml(j.jenjang || 'S1')}
            </span>
        `;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="text-align: center; color: #64748b;">${idx + 1}</td>
            <td style="text-align: center;">
                <span style="display: inline-block; font-family: monospace; font-size: 0.95rem; font-weight: 800; background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 6px; border: 1px solid #bae6fd;">
                    ${escapeHtml(j.kode)}
                </span>
            </td>
            <td style="text-align: center;">${jenjangBadge}</td>
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.925rem;">${escapeHtml(j.nama_jurusan)}</div>
                <div style="font-size: 0.775rem; color: #64748b; margin-top: 2px;">Terhubung pada ${j.total_unit_terhubung || 0} unit kantor mitra</div>
            </td>
            <td style="text-align: center;">
                <span style="font-weight: 700; color: #0b3d6b; font-size: 0.9rem;">${j.total_mahasiswa}</span>
                <span style="color: #64748b; font-size: 0.8rem;"> Mahasiswa</span>
            </td>
            <td style="text-align: center;">${statusHtml}</td>
            <td style="text-align: center;">
                <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: center;">
                    <button type="button" class="btn-edit" title="Edit Program Studi" style="padding: 6px 10px; font-size: 0.775rem; background: #f8fafc; border: 1px solid #cbd5e1; color: #0b3d6b; font-weight: 600; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                        <span>Edit</span>
                    </button>
                    <button type="button" class="btn-toggle" title="${j.aktif ? 'Nonaktifkan' : 'Aktifkan'}" style="padding: 6px 10px; font-size: 0.775rem; background: ${j.aktif ? '#fff1f2' : '#f0fdf4'}; border: 1px solid ${j.aktif ? '#fecdd3' : '#bbf7d0'}; color: ${j.aktif ? '#e11d48' : '#16a34a'}; font-weight: 600; border-radius: 6px; cursor: pointer;">
                        ${j.aktif ? 'Nonaktifkan' : 'Aktifkan'}
                    </button>
                    <button type="button" class="btn-delete" title="Hapus Program Studi" style="padding: 6px 8px; font-size: 0.775rem; background: #fff; border: 1px solid #e2e8f0; color: #64748b; border-radius: 6px; cursor: pointer;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                    </button>
                </div>
            </td>
        `;

        tr.querySelector('.btn-edit').addEventListener('click', () => openEditModal(j));
        tr.querySelector('.btn-toggle').addEventListener('click', () => handleToggleStatus(j.id, j.aktif ? 0 : 1));
        tr.querySelector('.btn-delete').addEventListener('click', () => handleDelete(j));

        tbody.appendChild(tr);
    });
}

function openCreateModal() {
    const modal = document.getElementById('modal-jurusan');
    const form = document.getElementById('form-jurusan');
    const titleEl = document.getElementById('modal-jurusan-title');
    if (!modal || !form) return;

    form.reset();
    document.getElementById('jurusan-id').value = '';
    if (titleEl) titleEl.innerText = 'Tambah Program Studi Baru';
    document.getElementById('jurusan-jenjang').value = 'S1';
    document.getElementById('jurusan-aktif').value = '1';
    modal.classList.remove('hidden');
    document.getElementById('jurusan-kode').focus();
}

function openEditModal(j) {
    const modal = document.getElementById('modal-jurusan');
    const titleEl = document.getElementById('modal-jurusan-title');
    if (!modal) return;

    if (titleEl) titleEl.innerText = `Edit Program Studi: ${j.nama_jurusan}`;
    document.getElementById('jurusan-id').value = j.id;
    document.getElementById('jurusan-jenjang').value = j.jenjang || 'S1';
    document.getElementById('jurusan-kode').value = j.kode || '';
    document.getElementById('jurusan-nama').value = j.nama_jurusan || '';
    document.getElementById('jurusan-aktif').value = j.aktif ? '1' : '0';
    modal.classList.remove('hidden');
    document.getElementById('jurusan-nama').focus();
}

function hideModal() {
    const modal = document.getElementById('modal-jurusan');
    if (modal) modal.classList.add('hidden');
}

async function handleFormSubmit(e) {
    e.preventDefault();

    const id = document.getElementById('jurusan-id').value;
    const isEdit = Boolean(id);

    const jenjang = document.getElementById('jurusan-jenjang').value;
    const kode = document.getElementById('jurusan-kode').value.trim().toUpperCase();
    const namaJurusan = document.getElementById('jurusan-nama').value.trim();
    const aktif = parseInt(document.getElementById('jurusan-aktif').value, 10);

    if (!kode || kode.length !== 2) {
        showAdminAlert('Kode prodi harus tepat 2 digit karakter (misal: 11, 31, 71)!', 'warning');
        return;
    }

    if (!namaJurusan) {
        showAdminAlert('Nama program studi wajib diisi!', 'warning');
        return;
    }

    const payload = {
        kode,
        jenjang,
        nama_jurusan: namaJurusan,
        aktif
    };

    const submitBtn = document.getElementById('btn-submit-jurusan');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = 'Menyimpan...';
    }

    try {
        const url = isEdit ? '/api/admin/jurusan/update.php' : '/api/admin/jurusan/create.php';
        if (isEdit) payload.id = parseInt(id, 10);

        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            body: JSON.stringify(payload)
        });

        const result = await res.json();
        if (res.ok && result.ok) {
            hideModal();
            showAdminAlert(result.message || 'Data program studi berhasil disimpan.', 'success');
            await loadJurusan();
        } else {
            showAdminAlert(result.error || 'Gagal menyimpan data program studi.', 'error');
        }
    } catch (err) {
        showAdminAlert('Terjadi kesalahan koneksi saat menyimpan program studi.', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Simpan';
        }
    }
}

async function handleToggleStatus(id, newStatus) {
    const statusText = newStatus === 1 ? 'mengaktifkan' : 'menonaktifkan';
    const confirmed = await showAdminConfirm(
        `Apakah Anda yakin ingin ${statusText} status program studi ini?`,
        'Konfirmasi Perubahan Status'
    );
    if (!confirmed) return;

    try {
        const res = await fetch('/api/admin/jurusan/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            body: JSON.stringify({
                id: parseInt(id, 10),
                aktif: newStatus
            })
        });

        const result = await res.json();
        if (res.ok && result.ok) {
            showAdminAlert('Status program studi berhasil diperbarui.', 'success');
            await loadJurusan();
        } else {
            showAdminAlert(result.error || 'Gagal memperbarui status program studi.', 'error');
        }
    } catch (err) {
        showAdminAlert('Terjadi kesalahan jaringan.', 'error');
    }
}

async function handleDelete(j) {
    const confirmed = await showAdminConfirm(
        `Apakah Anda yakin ingin menghapus program studi <strong>${escapeHtml(j.jenjang || 'S1')} ${escapeHtml(j.nama_jurusan)}</strong> (Kode: ${escapeHtml(j.kode)})?<br><br><span style="font-size: 0.8rem; color: #64748b;">Perhatian: Penghapusan akan ditolak jika prodi telah memiliki riwayat data mahasiswa.</span>`,
        'Hapus Program Studi'
    );
    if (!confirmed) return;

    try {
        const res = await fetch('/api/admin/jurusan/delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            body: JSON.stringify({
                id: parseInt(j.id, 10)
            })
        });

        const result = await res.json();
        if (res.ok && result.ok) {
            showAdminAlert(result.message || 'Program studi berhasil dihapus.', 'success');
            await loadJurusan();
        } else {
            showAdminAlert(result.error || 'Gagal menghapus program studi.', 'error');
        }
    } catch (err) {
        showAdminAlert('Terjadi kesalahan jaringan saat menghapus program studi.', 'error');
    }
}
