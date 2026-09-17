/**
 * public/js/admin/peminatan.js
 * Master data peminatan magang ITPLN x PLN
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
let peminatanList = [];
let allJurusanList = [];

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
    await loadPeminatan();

    // 3. Search Filter
    const searchInput = document.getElementById('search-peminatan');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim().toLowerCase();
            if (!q) {
                renderTable(peminatanList);
            } else {
                const filtered = peminatanList.filter(p => 
                    (p.nama || '').toLowerCase().includes(q) ||
                    (p.deskripsi || '').toLowerCase().includes(q) ||
                    (p.jurusan_names || []).some(jn => jn.toLowerCase().includes(q))
                );
                renderTable(filtered);
            }
        });
    }

    // 4. Modal Triggers & Prodi Quick Actions
    const btnAdd = document.getElementById('btn-add-peminatan');
    if (btnAdd) {
        btnAdd.addEventListener('click', openCreateModal);
    }

    const btnClose = document.getElementById('btn-close-modal');
    if (btnClose) btnClose.addEventListener('click', hideModal);

    const btnCancel = document.getElementById('btn-cancel-modal');
    if (btnCancel) btnCancel.addEventListener('click', hideModal);

    document.getElementById('btn-select-all-prodi-pem')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-prodi-pem').forEach(cb => cb.checked = true);
    });

    document.getElementById('btn-clear-prodi-pem')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-prodi-pem').forEach(cb => cb.checked = false);
    });

    const form = document.getElementById('form-peminatan');
    if (form) {
        form.addEventListener('submit', handleFormSubmit);
    }
});

async function loadPeminatan() {
    const tbody = document.getElementById('table-peminatan-body');
    const badge = document.getElementById('total-count-badge');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 32px; color: #64748b;">Memuat data peminatan...</td></tr>';
    }

    try {
        const res = await fetch('/api/admin/peminatan/list.php');
        const data = await res.json();
        if (data.ok && Array.isArray(data.data)) {
            peminatanList = data.data;
            if (Array.isArray(data.all_jurusan)) {
                allJurusanList = data.all_jurusan;
            }
            if (badge) badge.innerText = `${peminatanList.length} Peminatan`;
            renderTable(peminatanList);
        } else {
            if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 32px; color: #ef4444;">Gagal memuat data.</td></tr>';
        }
    } catch (err) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 32px; color: #ef4444;">Kesalahan jaringan.</td></tr>';
    }
}

function renderTable(dataArray) {
    const tbody = document.getElementById('table-peminatan-body');
    if (!tbody) return;

    if (dataArray.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 36px; color: #64748b;">Belum ada master data peminatan.</td></tr>';
        return;
    }

    tbody.innerHTML = '';
    dataArray.forEach(p => {
        const statusHtml = p.aktif == 1
            ? '<span class="badge-status badge-dibuka">Aktif</span>'
            : '<span class="badge-status badge-ditutup">Nonaktif</span>';

        const prodiBadges = (p.jurusan_names && p.jurusan_names.length > 0)
            ? `<div style="display: flex; flex-wrap: wrap; gap: 4px;">
                ${p.jurusan_names.map(jn => `<span class="badge-status badge-info" style="font-size: 0.725rem; padding: 2px 6px; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 600;">${escapeHtml(jn)}</span>`).join('')}
               </div>`
            : '<span style="color: #94a3b8; font-size: 0.8rem; font-style: italic;">Belum ada prodi</span>';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.925rem;">${escapeHtml(p.nama)}</div>
            </td>
            <td>${prodiBadges}</td>
            <td style="color: #475569; font-size: 0.875rem;">${escapeHtml(p.deskripsi || '-')}</td>
            <td>${statusHtml}</td>
            <td>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <button type="button" class="btn-edit" style="padding: 6px 12px; font-size: 0.8rem; background: #f8fafc; border: 1px solid #cbd5e1; color: #0b3d6b; font-weight: 600; border-radius: 8px; cursor: pointer;">
                        Edit
                    </button>
                    <button type="button" class="btn-toggle" style="padding: 6px 12px; font-size: 0.8rem; background: ${p.aktif == 1 ? '#fff1f2' : '#f0fdf4'}; border: 1px solid ${p.aktif == 1 ? '#fecdd3' : '#bbf7d0'}; color: ${p.aktif == 1 ? '#e11d48' : '#16a34a'}; font-weight: 600; border-radius: 8px; cursor: pointer;">
                        ${p.aktif == 1 ? 'Nonaktifkan' : 'Aktifkan'}
                    </button>
                </div>
            </td>
        `;

        tr.querySelector('.btn-edit').addEventListener('click', () => openEditModal(p));
        tr.querySelector('.btn-toggle').addEventListener('click', () => handleToggleStatus(p.id, p.aktif == 1 ? 0 : 1));

        tbody.appendChild(tr);
    });
}

function renderProdiCheckboxes(selectedIds = []) {
    const container = document.getElementById('container-checkbox-prodi-pem');
    if (!container) return;

    container.innerHTML = '';
    const selectedNumIds = (selectedIds || []).map(id => parseInt(id, 10));

    allJurusanList.forEach(j => {
        const jId = parseInt(j.id, 10);
        const isChecked = selectedNumIds.includes(jId);
        const labelText = (j.jenjang ? `${j.jenjang} - ` : '') + j.nama_jurusan;

        const label = document.createElement('label');
        label.style.cssText = 'display: flex; align-items: center; gap: 8px; font-size: 0.825rem; color: #334155; cursor: pointer; padding: 4px; border-radius: 4px;';
        label.innerHTML = `
            <input type="checkbox" class="chk-modal-prodi-pem" value="${jId}" ${isChecked ? 'checked' : ''} style="cursor: pointer; width: 15px; height: 15px;">
            <span>${escapeHtml(labelText)}</span>
        `;
        container.appendChild(label);
    });
}

function openCreateModal() {
    const modal = document.getElementById('modal-peminatan');
    const form = document.getElementById('form-peminatan');
    const titleEl = document.getElementById('modal-peminatan-title');
    if (!modal || !form) return;

    form.reset();
    document.getElementById('peminatan-id').value = '';
    if (titleEl) titleEl.innerText = 'Tambah Peminatan Baru';
    document.getElementById('peminatan-aktif').value = '1';
    renderProdiCheckboxes([]);
    modal.classList.remove('hidden');
}

function openEditModal(p) {
    const modal = document.getElementById('modal-peminatan');
    const titleEl = document.getElementById('modal-peminatan-title');
    if (!modal) return;

    if (titleEl) titleEl.innerText = `Edit Peminatan: ${p.nama}`;
    document.getElementById('peminatan-id').value = p.id;
    document.getElementById('peminatan-nama').value = p.nama || '';
    document.getElementById('peminatan-deskripsi').value = p.deskripsi || '';
    document.getElementById('peminatan-aktif').value = p.aktif ? '1' : '0';
    renderProdiCheckboxes(p.jurusan_ids || []);
    modal.classList.remove('hidden');
}

function hideModal() {
    const modal = document.getElementById('modal-peminatan');
    if (modal) modal.classList.add('hidden');
}

async function handleFormSubmit(e) {
    e.preventDefault();

    const id = document.getElementById('peminatan-id').value;
    const isEdit = Boolean(id);

    const nama = document.getElementById('peminatan-nama').value.trim();
    const deskripsi = document.getElementById('peminatan-deskripsi').value.trim();
    const aktif = parseInt(document.getElementById('peminatan-aktif').value, 10);

    const checkedJids = Array.from(document.querySelectorAll('.chk-modal-prodi-pem:checked')).map(cb => parseInt(cb.value, 10));

    if (!nama) {
        showAdminAlert('Nama peminatan wajib diisi!', 'warning');
        return;
    }

    if (checkedJids.length === 0) {
        showAdminAlert('Silakan pilih minimal 1 Program Studi terkait untuk peminatan ini!', 'warning', 'Program Studi Wajib');
        return;
    }

    const payload = {
        nama,
        deskripsi: deskripsi || null,
        aktif,
        jurusan_ids: checkedJids
    };

    const submitBtn = document.getElementById('btn-submit-peminatan');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = 'Menyimpan...';
    }

    try {
        const url = isEdit ? '/api/admin/peminatan/update.php' : '/api/admin/peminatan/create.php';
        if (isEdit) payload.id = parseInt(id, 10);

        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (res.ok && data.ok) {
            hideModal();
            showAdminAlert(data.message || 'Peminatan berhasil disimpan.', 'success');
            await loadPeminatan();
        } else {
            showAdminAlert(data.error || 'Gagal menyimpan peminatan.', 'error');
        }
    } catch (err) {
        showAdminAlert('Terjadi kesalahan jaringan atau sistem.', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Simpan';
        }
    }
}

async function handleToggleStatus(id, newStatus) {
    const actionText = newStatus === 1 ? 'mengaktifkan' : 'menonaktifkan';
    const confirmed = await showAdminConfirm(
        `Apakah Anda yakin ingin ${actionText} peminatan ini?`,
        'Konfirmasi Status Peminatan',
        newStatus === 1 ? 'info' : 'warning',
        `Ya, ${newStatus === 1 ? 'Aktifkan' : 'Nonaktifkan'}`
    );

    if (!confirmed) return;

    try {
        const res = await fetch('/api/admin/peminatan/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ id, aktif: newStatus })
        });

        const data = await res.json();
        if (res.ok && data.ok) {
            showAdminAlert(`Peminatan berhasil di-${actionText}.`, 'success');
            await loadPeminatan();
        } else {
            showAdminAlert(data.error || 'Gagal memperbarui status peminatan.', 'error');
        }
    } catch (err) {
        showAdminAlert('Terjadi kesalahan jaringan.', 'error');
    }
}
