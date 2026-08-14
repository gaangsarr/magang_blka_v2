let csrfToken = null;
let currentPeriodeList = [];

window.showModal = function(id) {
    document.getElementById(id).classList.remove('hidden');
};
window.hideModal = function(id) {
    document.getElementById(id).classList.add('hidden');
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
    const statusRes = await fetch('/api/admin/status.php');
    const statusData = await statusRes.json();
    if(statusData.csrf_token) csrfToken = statusData.csrf_token;
    if (!statusRes.ok || !statusData.authenticated) {
        window.location.href = '/admin/login.html';
        return;
    }

    if (statusData.admin && statusData.admin.nama) {
        const nameElem = document.getElementById('admin-name');
        if (nameElem) nameElem.innerText = statusData.admin.nama;
    }

    loadPeriode();

    // Initial render of default angkatan checkboxes (e.g. 2021 to 2030)
    renderAngkatanCheckboxes('container-chk-angkatan-create', ['2023', '2024']);
    renderAngkatanCheckboxes('container-chk-angkatan-edit', []);

    // Manual Angkatan Addition in Create Modal
    document.getElementById('btn-add-angkatan-create').addEventListener('click', () => {
        const input = document.getElementById('create-add-angkatan-input');
        const val = input.value.trim();
        if (val) {
            addAngkatanCheckbox('container-chk-angkatan-create', val, true);
            input.value = '';
        }
    });

    // Manual Angkatan Addition in Edit Modal
    document.getElementById('btn-add-angkatan-edit').addEventListener('click', () => {
        const input = document.getElementById('edit-add-angkatan-input');
        const val = input.value.trim();
        if (val) {
            addAngkatanCheckbox('container-chk-angkatan-edit', val, true);
            input.value = '';
        }
    });

    // Form Create
    document.getElementById('form-create-periode').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-periode');
        btn.disabled = true;
        btn.innerText = 'Menyimpan...';

        const selectedAngkatan = Array.from(document.querySelectorAll('#container-chk-angkatan-create .chk-angkatan:checked')).map(cb => cb.value);

        const payload = {
            nama: document.getElementById('create-nama').value,
            tanggal_mulai: document.getElementById('create-tanggal_mulai').value,
            tanggal_selesai: document.getElementById('create-tanggal_selesai').value,
            angkatan_eligible: selectedAngkatan,
            program_1_bulan: document.getElementById('create-prog1').checked,
            program_5_bulan: document.getElementById('create-prog5').checked,
            copy_from_periode_id: document.getElementById('copy_from_periode_id').value ? parseInt(document.getElementById('copy_from_periode_id').value) : null
        };

        try {
            const res = await fetch('/api/admin/periode/create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                alert(data.message || 'Periode berhasil dibuat.');
                hideModal('modal-create');
                e.target.reset();
                loadPeriode();
            } else {
                alert(data.error || 'Gagal membuat periode.');
            }
        } catch (err) {
            alert('Kesalahan server.');
        }
        btn.disabled = false;
        btn.innerText = 'Simpan Draft';
    });

    // Form Edit Submit
    document.getElementById('form-edit-periode').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-edit-periode');
        btn.disabled = true;
        btn.innerText = 'Memproses...';

        const selectedAngkatan = Array.from(document.querySelectorAll('#container-chk-angkatan-edit .chk-angkatan:checked')).map(cb => cb.value);

        const payload = {
            id: parseInt(document.getElementById('edit-periode-id').value),
            nama: document.getElementById('edit-nama').value,
            tanggal_mulai: document.getElementById('edit-tanggal_mulai').value,
            tanggal_selesai: document.getElementById('edit-tanggal_selesai').value,
            angkatan_eligible: selectedAngkatan,
            program_1_bulan: document.getElementById('edit-prog1').checked,
            program_5_bulan: document.getElementById('edit-prog5').checked
        };

        try {
            const res = await fetch('/api/admin/periode/update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                alert(data.message || 'Periode berhasil diperbarui.');
                hideModal('modal-edit');
                loadPeriode();
            } else {
                alert(data.error || 'Gagal memperbarui periode.');
            }
        } catch (err) {
            alert('Kesalahan server.');
        }
        btn.disabled = false;
        btn.innerText = 'Simpan Perubahan';
    });

    // Form Status
    document.getElementById('form-status').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('status-periode-id').value;
        const status = document.getElementById('status').value;

        try {
            const res = await fetch('/api/admin/periode/update_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ id, status })
            });
            const data = await res.json();
            if (res.ok) {
                alert(data.message);
                hideModal('modal-status');
                loadPeriode();
            } else {
                alert(data.error);
            }
        } catch (err) {
            alert('Kesalahan server');
        }
    });
});

function renderAngkatanCheckboxes(containerId, selectedYearsArray = []) {
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    const currentYear = new Date().getFullYear();
    const defaultYears = [];
    for (let y = currentYear - 5; y <= currentYear + 4; y++) {
        defaultYears.push(y.toString());
    }

    // Union with selectedYearsArray
    const allYears = Array.from(new Set([...defaultYears, ...selectedYearsArray])).sort();

    allYears.forEach(year => {
        const isChecked = selectedYearsArray.includes(year);
        addAngkatanCheckbox(containerId, year, isChecked);
    });
}

function addAngkatanCheckbox(containerId, yearStr, isChecked = false) {
    const container = document.getElementById(containerId);
    
    // Avoid duplicates
    const existing = container.querySelector(`.chk-angkatan[value="${yearStr}"]`);
    if (existing) {
        existing.checked = true;
        return;
    }

    const label = document.createElement('label');
    label.style.cssText = 'display: flex; align-items: center; gap: 6px; font-size: 0.875rem; color: #0f172a; cursor: pointer; background: #f8fafc; padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600;';
    label.innerHTML = `
        <input type="checkbox" class="chk-angkatan" value="${escapeHtml(yearStr)}" ${isChecked ? 'checked' : ''} style="accent-color: #0b3d6b;">
        <span>${escapeHtml(yearStr)}</span>
    `;
    container.appendChild(label);
}

window.openCreateModal = function() {
    renderAngkatanCheckboxes('container-chk-angkatan-create', ['2023', '2024']);
    showModal('modal-create');
};

async function loadPeriode() {
    const tbody = document.getElementById('table-periode');
    const selectCopy = document.getElementById('copy_from_periode_id');
    try {
        const res = await fetch('/api/admin/periode/list.php');
        const data = await res.json();
        
        if (data.ok) {
            currentPeriodeList = data.data;

            selectCopy.innerHTML = '<option value="">-- Tidak Menyalin (Mulai Kosong) --</option>';
            data.data.forEach(p => {
                const escapedName = escapeHtml(p.nama);
                selectCopy.innerHTML += `<option value="${p.id}">${escapedName} (${p.status})</option>`;
            });

            tbody.innerHTML = '';
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-abu" style="padding: var(--space-3); text-align: center;">Belum ada periode.</td></tr>';
                return;
            }
            
            data.data.forEach(p => {
                const st = (p.status || '').toLowerCase().trim();
                let badgeHTML = `<span class="badge-status badge-persiapan">Persiapan</span>`;

                if (st === 'dibuka') {
                    badgeHTML = `<span class="badge-status badge-dibuka">Dibuka</span>`;
                } else if (st === 'persiapan') {
                    badgeHTML = `<span class="badge-status badge-persiapan">Persiapan</span>`;
                } else if (st === 'ditutup') {
                    badgeHTML = `<span class="badge-status badge-ditutup">Ditutup</span>`;
                } else if (st === 'diarsipkan') {
                    badgeHTML = `<span class="badge-status badge-diarsipkan">Diarsipkan</span>`;
                } else if (st === 'draft') {
                    badgeHTML = `<span class="badge-status badge-draft">Draft</span>`;
                }

                const angkatanPills = p.angkatan_eligible 
                    ? `<span style="font-weight: 700; font-size: 0.8rem; color: #0369a1; background: #e0f2fe; padding: 3px 8px; border-radius: 6px;">${escapeHtml(p.angkatan_eligible)}</span>`
                    : '<span style="font-size: 0.8rem; color: #94a3b8;">Semua Angkatan</span>';

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight: 600; color: #64748b;">${p.id}</td>
                    <td style="font-weight: 700; color: #0b3d6b;">${escapeHtml(p.nama)}</td>
                    <td style="color: #475569;">${p.tanggal_mulai}</td>
                    <td style="color: #475569;">${p.tanggal_selesai}</td>
                    <td>${angkatanPills}</td>
                    <td>${badgeHTML}</td>
                    <td>
                        <span style="font-weight: 600; font-size: 0.825rem; color: #0b3d6b; background: #f1f5f9; padding: 3px 8px; border-radius: 6px;">
                            ${p.program_1_bulan ? '1 Bulan ' : ''}${p.program_5_bulan ? '5 Bulan' : ''}
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" style="padding: 6px 10px; font-size: 0.775rem; background: #e0f2fe; border: 1px solid #bae6fd; color: #0369a1; font-weight: 700; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" onclick="openEditModal(${p.id})">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                <span>Edit</span>
                            </button>
                            <button type="button" style="padding: 6px 10px; font-size: 0.775rem; background: #f8fafc; border: 1px solid #e2e8f0; color: #0b3d6b; font-weight: 700; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" onclick="openStatusModal(${p.id}, '${escapeHtml(st)}')">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                <span>Status</span>
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-merah" style="padding: var(--space-3); text-align: center;">Gagal memuat data.</td></tr>';
    }
}

window.openEditModal = function(periodeId) {
    const p = currentPeriodeList.find(item => item.id == periodeId);
    if (!p) return;

    document.getElementById('edit-periode-id').value = p.id;
    document.getElementById('edit-nama').value = p.nama || '';
    document.getElementById('edit-tanggal_mulai').value = p.tanggal_mulai || '';
    document.getElementById('edit-tanggal_selesai').value = p.tanggal_selesai || '';
    document.getElementById('edit-prog1').checked = p.program_1_bulan == 1;
    document.getElementById('edit-prog5').checked = p.program_5_bulan == 1;

    const selectedYears = p.angkatan_eligible ? p.angkatan_eligible.split(',').map(s => s.trim()) : [];
    renderAngkatanCheckboxes('container-chk-angkatan-edit', selectedYears);

    showModal('modal-edit');
};

window.openStatusModal = function(id, currentStatus) {
    document.getElementById('status-periode-id').value = id;
    const selectEl = document.getElementById('status');
    const validVals = ['draft', 'persiapan', 'dibuka', 'ditutup', 'diarsipkan'];
    const valToSet = (currentStatus && validVals.includes(currentStatus.toLowerCase())) ? currentStatus.toLowerCase() : 'persiapan';
    selectEl.value = valToSet;
    showModal('modal-status');
};
