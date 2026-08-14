window.showModal = function(id) {
    document.getElementById(id).classList.remove('hidden');
};
window.hideModal = function(id) {
    document.getElementById(id).classList.add('hidden');
};

let peminatanList = [];
let csrfToken = null;
let allUnitKuota = [];
let allMasterUnit = [];
let currentPeriodData = null;
let isPeriodeSelectPopulated = false;

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

    // Tabs
    const btnTabKuota = document.getElementById('btn-tab-kuota');
    const btnTabMaster = document.getElementById('btn-tab-master');
    const btnTabPeminatan = document.getElementById('btn-tab-peminatan');
    const tabKuota = document.getElementById('tab-kuota');
    const tabMaster = document.getElementById('tab-master');
    const tabPeminatan = document.getElementById('tab-peminatan');

    function resetTabButtons() {
        [btnTabKuota, btnTabMaster, btnTabPeminatan].forEach(btn => {
            if (btn) {
                btn.classList.remove('active');
                btn.style.borderBottomColor = 'transparent';
                btn.style.fontWeight = 'normal';
                btn.style.color = 'var(--clr-abu)';
            }
        });
        [tabKuota, tabMaster, tabPeminatan].forEach(t => t && t.classList.add('hidden'));
    }

    btnTabKuota.addEventListener('click', () => {
        resetTabButtons();
        btnTabKuota.classList.add('active');
        btnTabKuota.style.borderBottomColor = 'var(--clr-primer)';
        btnTabKuota.style.fontWeight = 'bold';
        btnTabKuota.style.color = 'var(--clr-primer)';
        tabKuota.classList.remove('hidden');
        loadUnit();
    });

    btnTabMaster.addEventListener('click', () => {
        resetTabButtons();
        btnTabMaster.classList.add('active');
        btnTabMaster.style.borderBottomColor = 'var(--clr-primer)';
        btnTabMaster.style.fontWeight = 'bold';
        btnTabMaster.style.color = 'var(--clr-primer)';
        tabMaster.classList.remove('hidden');
        loadMasterUnit();
    });

    btnTabPeminatan.addEventListener('click', () => {
        resetTabButtons();
        btnTabPeminatan.classList.add('active');
        btnTabPeminatan.style.borderBottomColor = 'var(--clr-primer)';
        btnTabPeminatan.style.fontWeight = 'bold';
        btnTabPeminatan.style.color = 'var(--clr-primer)';
        tabPeminatan.classList.remove('hidden');
        loadPeminatanTable();
    });

    // Load initial Kuota data
    loadUnit();

    // Register search input event listeners
    const searchKuotaInput = document.getElementById('search-kuota');
    if (searchKuotaInput) {
        searchKuotaInput.addEventListener('input', applyKuotaFilter);
    }
    const searchMasterInput = document.getElementById('search-master');
    if (searchMasterInput) {
        searchMasterInput.addEventListener('input', applyMasterFilter);
    }
    
    // Fetch Peminatan for Master Modal Checkboxes
    try {
        const resPem = await fetch('/api/peminatan/list.php');
        const dataPem = await resPem.json();
        if (dataPem.ok) {
            peminatanList = dataPem.data;
            const container = document.getElementById('peminatan-checkboxes');
            container.innerHTML = peminatanList.map(p => `
                <label style="display: flex; align-items: center; gap: 4px; font-size: 0.9rem;">
                    <input type="checkbox" name="peminatan_ids[]" value="${p.id}" class="chk-peminatan">
                    ${p.nama}
                </label>
            `).join('');
        }
    } catch (e) {
        console.error('Gagal muat peminatan');
    }

    // Modal Master Actions
    document.getElementById('btn-add-master').addEventListener('click', () => {
        document.getElementById('modal-master-title').innerText = 'Tambah Master Unit';
        document.getElementById('master-id').value = '';
        document.getElementById('form-master').reset();
        document.querySelectorAll('.chk-peminatan').forEach(c => c.checked = false);
        
        showModal('modal-master');
    });

    document.getElementById('form-master').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-master');
        btn.disabled = true;
        btn.innerText = 'Menyimpan...';

        const peminatanIds = Array.from(document.querySelectorAll('.chk-peminatan:checked')).map(c => parseInt(c.value));
        
        const id = document.getElementById('master-id').value;
        const payload = {
            id: id || null,
            nama: document.getElementById('master-nama').value,
            alamat: document.getElementById('master-alamat').value,
            latitude: parseFloat(document.getElementById('master-lat').value),
            longitude: parseFloat(document.getElementById('master-lng').value),
            aktif: parseInt(document.getElementById('master-aktif').value),
            peminatan_ids: peminatanIds
        };

        const endpoint = id ? '/api/admin/entitas/update.php' : '/api/admin/entitas/create.php';

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                alert(data.message);
                hideModal('modal-master');
                loadMasterUnit();
            } else {
                alert(data.error);
            }
        } catch (err) {
            alert('Kesalahan server');
        }
        btn.disabled = false;
        btn.innerText = 'Simpan';
    });

    // Form Kuota
    document.getElementById('form-kuota').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-kuota');
        btn.disabled = true;
        btn.innerText = 'Menyimpan...';

        const selectPeriode = document.getElementById('select-periode-kuota');
        const payload = {
            entitas_id: document.getElementById('entitas-id').value,
            periode_id: selectPeriode ? selectPeriode.value : null,
            kuota_total: parseInt(document.getElementById('kuota_total').value),
            aktif: document.getElementById('aktif').value
        };

        try {
            const res = await fetch('/api/admin/unit/update_kuota.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                if (window.showAdminAlert) window.showAdminAlert(data.message || 'Kuota unit berhasil diperbarui.', 'success');
                else alert(data.message);
                hideModal('modal-kuota');
                loadUnit(payload.periode_id);
            } else {
                if (window.showAdminAlert) window.showAdminAlert(data.error || 'Gagal menyimpan kuota.', 'error');
                else alert(data.error);
            }
        } catch (err) {
            if (window.showAdminAlert) window.showAdminAlert('Kesalahan server saat menyimpan kuota.', 'error');
            else alert('Kesalahan server');
        }
        btn.disabled = false;
        btn.innerText = 'Simpan';
    });

    // Peminatan Modal Actions
    const btnAddPem = document.getElementById('btn-add-peminatan');
    if (btnAddPem) {
        btnAddPem.addEventListener('click', () => {
            document.getElementById('modal-peminatan-title').innerText = 'Tambah Peminatan Baru';
            document.getElementById('peminatan-id').value = '';
            document.getElementById('form-peminatan').reset();
            showModal('modal-peminatan');
        });
    }

    const formPem = document.getElementById('form-peminatan');
    if (formPem) {
        formPem.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btn-submit-peminatan');
            btn.disabled = true;
            btn.innerText = 'Menyimpan...';

            const id = document.getElementById('peminatan-id').value;
            const payload = {
                id: id || null,
                nama: document.getElementById('peminatan-nama').value,
                deskripsi: document.getElementById('peminatan-deskripsi').value,
                aktif: parseInt(document.getElementById('peminatan-aktif').value, 10),
            };

            const endpoint = id ? '/api/admin/peminatan/update.php' : '/api/admin/peminatan/create.php';

            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    alert(data.message);
                    hideModal('modal-peminatan');
                    loadPeminatanTable();
                } else {
                    alert(data.error || 'Gagal menyimpan peminatan.');
                }
            } catch (err) {
                alert('Kesalahan server saat menyimpan peminatan.');
            }
            btn.disabled = false;
            btn.innerText = 'Simpan Peminatan';
        });
    }
});

async function loadUnit(targetPeriodeId = null) {
    const tbody = document.getElementById('table-unit');
    const selectEl = document.getElementById('select-periode-kuota');
    
    try {
        const url = targetPeriodeId ? `/api/admin/unit/list.php?periode_id=${targetPeriodeId}` : '/api/admin/unit/list.php';
        const res = await fetch(url);
        const data = await res.json();
        
        if (data.ok) {
            if (data.all_periode && selectEl) {
                selectEl.innerHTML = '';
                data.all_periode.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.innerText = `${p.nama} (${(p.status || 'DRAFT').toUpperCase()})`;
                    if (data.periode_aktif && parseInt(p.id) === parseInt(data.periode_aktif.id)) {
                        opt.selected = true;
                    }
                    selectEl.appendChild(opt);
                });

                if (!isPeriodeSelectPopulated) {
                    isPeriodeSelectPopulated = true;
                    selectEl.addEventListener('change', (e) => {
                        loadUnit(e.target.value);
                    });
                }
            }

            currentPeriodData = data.periode_terpilih || data.periode_aktif;
            const infoEl = document.getElementById('info-periode');
            if (!currentPeriodData) {
                infoEl.innerHTML = '<span style="color: #ef4444; font-weight: 600;">Belum ada periode magang yang dibuat.</span>';
            } else {
                const st = (currentPeriodData.status || '').toLowerCase().trim();
                if (st === 'dibuka') {
                    infoEl.innerHTML = `<span style="color: #059669; font-weight: 700;">● Periode Aktif: ${escapeHtml(currentPeriodData.nama)} (DIBUKA)</span>`;
                } else if (st === 'persiapan') {
                    infoEl.innerHTML = `<span style="color: #d97706; font-weight: 700;">● Periode Persiapan: ${escapeHtml(currentPeriodData.nama)} (PERSIAPAN - SETTING KUOTA)</span>`;
                } else {
                    infoEl.innerHTML = `<span style="color: #64748b; font-weight: 600;">(Tidak Ada Periode Aktif) Periode Terpilih: ${escapeHtml(currentPeriodData.nama)} [STATUS: ${st.toUpperCase()}]</span>`;
                }
            }

            allUnitKuota = data.data;
            applyKuotaFilter();
        }
    } catch (err) {
        console.error(err);
        tbody.innerHTML = '<tr><td colspan="6" style="color: #ef4444; padding: 24px; text-align: center;">Gagal memuat data.</td></tr>';
    }
}

function applyKuotaFilter() {
    const searchVal = document.getElementById('search-kuota') ? document.getElementById('search-kuota').value.toLowerCase().trim() : '';
    const filtered = allUnitKuota.filter(u => {
        return !searchVal || 
            u.nama.toLowerCase().includes(searchVal) || 
            (u.alamat && u.alamat.toLowerCase().includes(searchVal));
    });
    renderUnitKuotaTable(filtered);
}

function renderUnitKuotaTable(dataArray) {
    const tbody = document.getElementById('table-unit');
    tbody.innerHTML = '';
    
    if (dataArray.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-abu" style="padding: var(--space-3); text-align: center;">Tidak ada unit pelaksana cocok dengan pencarian.</td></tr>';
        return;
    }
    
    dataArray.forEach(u => {
        const isSet = u.upp_id !== null;
        const statusHtml = isSet 
            ? (u.aktif == 1 ? '<span class="badge-status badge-dibuka">Aktif</span>' : '<span class="badge-status badge-ditutup">Nonaktif</span>')
            : '<span class="badge-status badge-draft">Belum Diset</span>';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="font-weight: 700; color: #0b3d6b;">${escapeHtml(u.nama)}</td>
            <td style="color: #475569; font-size: 0.875rem;">${escapeHtml(u.alamat || '-')}</td>
            <td style="font-weight: 600;">${u.kuota_total !== null ? u.kuota_total : '-'}</td>
            <td style="font-weight: 700; color: #10b981;">${u.kuota_tersisa !== null ? u.kuota_tersisa : '-'}</td>
            <td>${statusHtml}</td>
            <td>
                ${currentPeriodData ? `<button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; background: #f8fafc; border: 1px solid #e2e8f0; color: #0b3d6b; font-weight: 600; border-radius: 8px; cursor: pointer;" onclick="openKuotaModal(${u.entitas_id}, '${escapeHtml(u.nama).replace(/'/g, "\\'")}', ${u.kuota_total || 0}, ${u.aktif !== null ? u.aktif : 1})">Atur Kuota</button>` : ''}
            </td>
        `;
        tbody.appendChild(tr);
    });
}

async function loadMasterUnit() {
    const tbody = document.getElementById('table-master');
    tbody.innerHTML = '<tr><td colspan="5" style="color: #64748b; padding: 24px; text-align: center;">Memuat data master unit...</td></tr>';
    try {
        const res = await fetch('/api/admin/entitas/list.php');
        const data = await res.json();
        
        if (data.ok) {
            allMasterUnit = data.data;
            applyMasterFilter();
        }
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-merah" style="padding: var(--space-3); text-align: center;">Gagal memuat data.</td></tr>';
    }
}

function applyMasterFilter() {
    const searchVal = document.getElementById('search-master') ? document.getElementById('search-master').value.toLowerCase().trim() : '';
    const filtered = allMasterUnit.filter(u => {
        return !searchVal || 
            u.nama.toLowerCase().includes(searchVal) || 
            (u.alamat && u.alamat.toLowerCase().includes(searchVal));
    });
    renderMasterUnitTable(filtered);
}

function renderMasterUnitTable(dataArray) {
    const tbody = document.getElementById('table-master');
    tbody.innerHTML = '';
    
    if (dataArray.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="color: #64748b; padding: 24px; text-align: center;">Tidak ada master data unit cocok dengan pencarian.</td></tr>';
        return;
    }
    
    dataArray.forEach(u => {
        const statusHtml = u.aktif == 1 
            ? '<span class="badge-status badge-dibuka">Beroperasi</span>' 
            : '<span class="badge-status badge-ditutup">Tutup / Pindah</span>';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="font-weight: 700; color: #0b3d6b;">${escapeHtml(u.nama)}</td>
            <td style="color: #475569; font-size: 0.875rem;">${escapeHtml(u.alamat || '-')}</td>
            <td style="font-family: monospace; font-size: 0.85rem; color: #64748b;">${u.latitude || '-'}, ${u.longitude || '-'}</td>
            <td>${statusHtml}</td>
            <td>
                <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; background: #f8fafc; border: 1px solid #e2e8f0; color: #0b3d6b; font-weight: 600; border-radius: 8px; cursor: pointer;" onclick='openMasterModal(${JSON.stringify(u).replace(/'/g, "\\'")})'>Edit</button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

async function loadPeminatanTable() {
    const tbody = document.getElementById('table-peminatan');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" style="color: #64748b; padding: 24px; text-align: center;">Memuat data peminatan...</td></tr>';
    try {
        const res = await fetch('/api/admin/peminatan/list.php');
        const data = await res.json();
        
        if (data.ok) {
            tbody.innerHTML = '';
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="color: #64748b; padding: 24px; text-align: center;">Belum ada master data peminatan.</td></tr>';
                return;
            }
            
            data.data.forEach(p => {
                const statusHtml = p.aktif == 1 
                    ? '<span class="badge-status badge-dibuka">Aktif</span>' 
                    : '<span class="badge-status badge-ditutup">Nonaktif</span>';

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight: 700; color: #0b3d6b;">${escapeHtml(p.nama)}</td>
                    <td style="color: #475569; font-size: 0.875rem;">${escapeHtml(p.deskripsi || '-')}</td>
                    <td>${statusHtml}</td>
                    <td style="display: flex; gap: 8px; align-items: center;">
                        <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; background: #f8fafc; border: 1px solid #e2e8f0; color: #0b3d6b; font-weight: 600; border-radius: 8px; cursor: pointer;" onclick='openPeminatanModal(${JSON.stringify(p).replace(/'/g, "\\'")})'>Edit</button>
                        <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; background: ${p.aktif == 1 ? '#fff1f2' : '#f0fdf4'}; border: 1px solid ${p.aktif == 1 ? '#fecdd3' : '#bbf7d0'}; color: ${p.aktif == 1 ? '#e11d48' : '#16a34a'}; font-weight: 600; border-radius: 8px; cursor: pointer;" onclick='togglePeminatanStatus(${p.id}, ${p.aktif == 1 ? 0 : 1})'>${p.aktif == 1 ? 'Nonaktifkan' : 'Aktifkan'}</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-merah" style="padding: var(--space-3); text-align: center;">Gagal memuat data peminatan.</td></tr>';
    }
}

window.openKuotaModal = function(entitasId, nama, currentKuota, currentAktif) {
    document.getElementById('entitas-id').value = entitasId;
    document.getElementById('kuota-unit-nama').innerText = nama;
    document.getElementById('kuota_total').value = currentKuota;
    document.getElementById('aktif').value = currentAktif;
    showModal('modal-kuota');
};

window.openMasterModal = async function(unit) {
    document.getElementById('modal-master-title').innerText = 'Edit Master Unit';
    document.getElementById('master-id').value = unit.id;
    document.getElementById('master-nama').value = unit.nama;
    document.getElementById('master-alamat').value = unit.alamat;
    document.getElementById('master-lat').value = unit.latitude;
    document.getElementById('master-lng').value = unit.longitude;
    document.getElementById('master-aktif').value = unit.aktif;
    
    document.querySelectorAll('.chk-peminatan').forEach(c => c.checked = false);
    if (unit.peminatan_ids) {
        unit.peminatan_ids.split(',').forEach(pid => {
            const chk = document.querySelector(`.chk-peminatan[value="${pid}"]`);
            if(chk) chk.checked = true;
        });
    }

    showModal('modal-master');
};

window.openPeminatanModal = function(pem) {
    document.getElementById('modal-peminatan-title').innerText = 'Edit Peminatan';
    document.getElementById('peminatan-id').value = pem.id;
    document.getElementById('peminatan-nama').value = pem.nama;
    document.getElementById('peminatan-deskripsi').value = pem.deskripsi || '';
    document.getElementById('peminatan-aktif').value = pem.aktif;
    showModal('modal-peminatan');
};

window.togglePeminatanStatus = async function(id, newStatus) {
    const actionText = newStatus === 1 ? 'mengaktifkan' : 'menonaktifkan';
    const isConfirmed = window.showAdminConfirm 
        ? await window.showAdminConfirm(`Apakah Anda yakin ingin ${actionText} peminatan ini?`, 'Konfirmasi Status Peminatan', 'warning')
        : confirm(`Yakin ingin ${actionText} peminatan ini?`);

    if (!isConfirmed) return;

    try {
        const res = await fetch('/api/admin/peminatan/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ id, aktif: newStatus })
        });
        const data = await res.json();
        if (res.ok && data.ok) {
            loadPeminatanTable();
            if (window.showAdminAlert) window.showAdminAlert(`Peminatan berhasil di-${actionText}.`, 'success');
        } else {
            if (window.showAdminAlert) window.showAdminAlert(data.error || 'Gagal mengubah status peminatan.', 'error');
        }
    } catch (err) {
        if (window.showAdminAlert) window.showAdminAlert('Kesalahan server.', 'error');
    }
};
