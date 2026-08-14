let csrfToken = null;
let allPenetapanData = [];
let unitList = [];
let jurusanList = [];
let selectedIds = new Set();

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
    // 1. Cek Admin Auth
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

    // 2. Load Unit Options for Relocate Modals
    await loadUnitList();

    // 3. Load Penetapan Data
    await loadPenetapanData();

    // 4. Multi-Filter Event Listeners
    document.getElementById('filter-search').addEventListener('input', applyFilters);
    document.getElementById('filter-jurusan').addEventListener('change', applyFilters);
    document.getElementById('filter-unit').addEventListener('change', applyFilters);
    document.getElementById('filter-program').addEventListener('change', applyFilters);
    document.getElementById('filter-status').addEventListener('change', applyFilters);

    // 5. Check All Toggle
    const checkAll = document.getElementById('check-all');
    if (checkAll) {
        checkAll.addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            const checkboxes = document.querySelectorAll('.chk-mhs');
            checkboxes.forEach(chk => {
                chk.checked = isChecked;
                const pid = parseInt(chk.value);
                if (isChecked) {
                    selectedIds.add(pid);
                } else {
                    selectedIds.delete(pid);
                }
            });
            updateBulkToolbar();
        });
    }

    // 6. Bulk Actions Setup
    document.getElementById('btn-bulk-approve').addEventListener('click', handleBulkApprove);
    document.getElementById('btn-bulk-relocate').addEventListener('click', handleBulkRelocateOpen);
    document.getElementById('btn-bulk-reject').addEventListener('click', handleBulkReject);

    // Form Bulk Relocate Submit
    document.getElementById('form-bulk-relocate').addEventListener('submit', async (e) => {
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
                alert(data.message || 'Berhasil memindahkan mahasiswa terpilih.');
                hideModal('modal-bulk-relocate');
                selectedIds.clear();
                updateBulkToolbar();
                loadPenetapanData();
            } else {
                alert(data.error || 'Gagal memproses pemindahan massal.');
            }
        } catch (err) {
            alert('Terjadi kesalahan jaringan.');
        }

        btn.disabled = false;
        btn.innerText = 'Proses Pemindahan Massal';
    });

    // 7. Individual Modals Submit Handlers
    document.getElementById('form-relocate').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-relocate');
        btn.disabled = true;
        btn.innerText = 'Memproses...';

        const payload = {
            pendaftaran_id: parseInt(document.getElementById('relocate-pendaftaran-id').value),
            status: 'dipindahkan',
            new_unit_pelaksana_periode_id: parseInt(document.getElementById('relocate-new-unit').value),
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
                alert(data.message || 'Mahasiswa berhasil dipindahkan ke unit baru.');
                hideModal('modal-relocate');
                e.target.reset();
                loadPenetapanData();
            } else {
                alert(data.error || 'Gagal memindahkan unit.');
            }
        } catch (err) {
            alert('Terjadi kesalahan jaringan.');
        }

        btn.disabled = false;
        btn.innerText = 'Pindahkan Paksa Unit';
    });

    document.getElementById('form-approve').addEventListener('submit', async (e) => {
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
                alert(data.message || 'Penetapan disetujui.');
                hideModal('modal-approve');
                e.target.reset();
                loadPenetapanData();
            } else {
                alert(data.error || 'Gagal menyetujui penetapan.');
            }
        } catch (err) {
            alert('Terjadi kesalahan jaringan.');
        }

        btn.disabled = false;
        btn.innerText = 'Setujui Penetapan';
    });

    document.getElementById('form-reject').addEventListener('submit', async (e) => {
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
                alert(data.message || 'Pendaftaran ditolak.');
                hideModal('modal-reject');
                e.target.reset();
                loadPenetapanData();
            } else {
                alert(data.error || 'Gagal menolak pendaftaran.');
            }
        } catch (err) {
            alert('Terjadi kesalahan jaringan.');
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

            selectElem.innerHTML = '<option value="">-- Pilih Unit Pelaksana Tujuan --</option>';
            bulkSelect.innerHTML = '<option value="">-- Pilih Unit Pelaksana Tujuan --</option>';
            filterUnit.innerHTML = '<option value="">Semua Unit Penempatan</option>';

            unitList.forEach(u => {
                const sisa = u.kuota_tersisa !== null ? u.kuota_tersisa : 0;
                selectElem.innerHTML += `<option value="${u.upp_id}" ${sisa <= 0 ? 'disabled' : ''}>${escapeHtml(u.nama)} (Sisa Kuota: ${sisa})</option>`;
                bulkSelect.innerHTML += `<option value="${u.upp_id}" ${sisa <= 0 ? 'disabled' : ''}>${escapeHtml(u.nama)} (Sisa Kuota: ${sisa})</option>`;
                filterUnit.innerHTML += `<option value="${escapeHtml(u.nama)}">${escapeHtml(u.nama)}</option>`;
            });
        }
    } catch (e) {
        console.error('Gagal memuat unit:', e);
    }
}

let isPeriodeSelectPopulated = false;

async function loadPenetapanData(targetPeriodeId = null) {
    const tbody = document.getElementById('table-penetapan');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #64748b; padding: 24px;">Memuat data pendaftaran & penetapan...</td></tr>';
    
    const selectEl = document.getElementById('select-periode-penetapan');

    try {
        const url = targetPeriodeId ? `/api/admin/penetapan/list.php?periode_id=${targetPeriodeId}` : '/api/admin/penetapan/list.php';
        const res = await fetch(url);
        const data = await res.json();

        if (data.ok) {
            // Populate period select dropdown
            if (data.all_periode && selectEl) {
                selectEl.innerHTML = '';
                data.all_periode.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.innerText = `${p.nama} (${(p.status || 'DRAFT').toUpperCase()})`;
                    if (data.periode_terpilih && parseInt(p.id) === parseInt(data.periode_terpilih.id)) {
                        opt.selected = true;
                    }
                    selectEl.appendChild(opt);
                });

                if (!isPeriodeSelectPopulated) {
                    isPeriodeSelectPopulated = true;
                    selectEl.addEventListener('change', (e) => {
                        loadPenetapanData(e.target.value);
                    });
                }
            }

            // Update Info Periode Text cleanly
            const infoEl = document.getElementById('info-periode');
            const btnExport = document.getElementById('btn-export-penetapan');
            const p = data.periode_terpilih;

            if (btnExport && p) {
                btnExport.href = `/api/admin/penetapan/export.php?periode_id=${p.id}`;
            }

            if (!p) {
                infoEl.innerHTML = '<span style="color: #ef4444; font-weight: 600;">Belum ada periode magang yang dibuat.</span>';
            } else {
                const st = (p.status || '').toLowerCase().trim();
                if (st === 'dibuka') {
                    infoEl.innerHTML = `<span style="color: #059669; font-weight: 700;">● Periode Aktif: ${escapeHtml(p.nama)} (DIBUKA)</span>`;
                } else if (st === 'persiapan') {
                    infoEl.innerHTML = `<span style="color: #d97706; font-weight: 700;">● Periode Persiapan: ${escapeHtml(p.nama)} (PERSIAPAN - SETTING KUOTA)</span>`;
                } else {
                    infoEl.innerHTML = `<span style="color: #64748b; font-weight: 600;">(Tidak Ada Periode Aktif) Periode Terpilih: ${escapeHtml(p.nama)} [STATUS: ${st.toUpperCase()}]</span>`;
                }
            }

            if (data.jurusan_list) {
                jurusanList = data.jurusan_list;
                const selectJurusan = document.getElementById('filter-jurusan');
                selectJurusan.innerHTML = '<option value="">Semua Jurusan</option>';
                jurusanList.forEach(j => {
                    selectJurusan.innerHTML += `<option value="${j.id}">${escapeHtml(j.nama)}</option>`;
                });
            }

            allPenetapanData = data.data;
            applyFilters();
        }
    } catch (err) {
        console.error(err);
        tbody.innerHTML = '<tr><td colspan="8" style="color: #ef4444; text-align: center; padding: 24px;">Gagal memuat data penetapan.</td></tr>';
    }
}

function applyFilters() {
    const search = document.getElementById('filter-search').value.toLowerCase().trim();
    const jurusanId = document.getElementById('filter-jurusan').value;
    const unitNama = document.getElementById('filter-unit').value;
    const program = document.getElementById('filter-program').value;
    const status = document.getElementById('filter-status').value;

    const filtered = allPenetapanData.filter(item => {
        const matchesSearch = !search || 
            item.nama.toLowerCase().includes(search) || 
            item.nim.toLowerCase().includes(search) ||
            item.email.toLowerCase().includes(search) ||
            (item.unit_nama && item.unit_nama.toLowerCase().includes(search));

        const matchesJurusan = !jurusanId || item.jurusan_id == jurusanId;
        const matchesUnit = !unitNama || (item.unit_nama && item.unit_nama === unitNama);
        const matchesProgram = !program || item.program === program;
        const matchesStatus = !status || item.status === status;

        return matchesSearch && matchesJurusan && matchesUnit && matchesProgram && matchesStatus;
    });

    const badgeElem = document.getElementById('filtered-count-badge');
    if (badgeElem) badgeElem.innerText = `${filtered.length} Data Ditampilkan (${allPenetapanData.length} Total)`;

    renderTable(filtered);
}

function renderTable(dataArray) {
    const tbody = document.getElementById('table-penetapan');
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
        toolbar.classList.add('active');
    } else {
        toolbar.classList.remove('active');
    }
}

// Bulk Actions Handlers
async function handleBulkApprove() {
    if (selectedIds.size === 0) return;
    const isConfirmed = window.showAdminConfirm
        ? await window.showAdminConfirm(`Konfirmasi menyetujui penetapan ${selectedIds.size} mahasiswa terpilih pada unit pilihan awal mereka?`, 'Setujui Penetapan Massal', 'info', 'Ya, Setujui', 'Batal')
        : confirm(`Konfirmasi menyetujui penetapan ${selectedIds.size} mahasiswa terpilih pada unit pilihan awal mereka?`);

    if (!isConfirmed) return;

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
            if (window.showAdminAlert) await window.showAdminAlert(data.message || 'Berhasil menyetujui mahasiswa terpilih.', 'success');
            else alert(data.message || 'Berhasil menyetujui mahasiswa terpilih.');
            selectedIds.clear();
            updateBulkToolbar();
            loadPenetapanData();
        } else {
            if (window.showAdminAlert) await window.showAdminAlert(data.error || 'Gagal memproses penetapan massal.', 'error');
            else alert(data.error || 'Gagal memproses penetapan massal.');
        }
    } catch (e) {
        if (window.showAdminAlert) await window.showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        else alert('Terjadi kesalahan jaringan.');
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
    const reason = prompt(`Tolak ${selectedIds.size} pengajuan magang mahasiswa terpilih. Masukkan alasan penolakan (opsional):`);
    if (reason === null) return; // User cancelled prompt

    try {
        const res = await fetch('/api/admin/penetapan/bulk_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                pendaftaran_ids: Array.from(selectedIds),
                status: 'ditolak',
                catatan_admin: reason
            })
        });
        const data = await res.json();
        if (res.ok) {
            alert(data.message || 'Berhasil menolak pendaftaran mahasiswa terpilih.');
            selectedIds.clear();
            updateBulkToolbar();
            loadPenetapanData();
        } else {
            alert(data.error || 'Gagal memproses penolakan massal.');
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
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
