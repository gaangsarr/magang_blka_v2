/**
 * public/js/admin/unit.js
 * Manajemen alokasi kuota penerimaan magang per periode
 */

import { showAdminAlert } from './common.js';

let csrfToken = null;
let allUnitKuota = [];
let allJurusanList = [];
let allPeminatanList = [];
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

function getTipeBadge(tipe) {
    switch (tipe) {
        case 'holding':
            return '<span class="badge-status badge-holding">Holding</span>';
        case 'subholding':
            return '<span class="badge-status badge-subholding">Subholding</span>';
        case 'anak_perusahaan':
            return '<span class="badge-status badge-anak_perusahaan">Anak Perusahaan</span>';
        case 'unit_induk':
            return '<span class="badge-status badge-unit_induk">Unit Induk</span>';
        case 'unit_pelaksana':
            return '<span class="badge-status badge-unit_pelaksana">Unit Pelaksana</span>';
        case 'unit_layanan':
            return '<span class="badge-status badge-unit_layanan">Unit Layanan</span>';
        default:
            return `<span class="badge-status badge-secondary">${escapeHtml(tipe || 'Unit')}</span>`;
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    // 1. Auth & CSRF Check
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

    // 2. Search Filter
    const searchKuotaInput = document.getElementById('search-kuota');
    if (searchKuotaInput) {
        searchKuotaInput.addEventListener('input', applyKuotaFilter);
    }

    // 3. Periode Select Dropdown
    const selectPeriode = document.getElementById('select-periode-kuota');
    if (selectPeriode) {
        selectPeriode.addEventListener('change', (e) => {
            const pid = parseInt(e.target.value, 10);
            if (pid) {
                loadUnit(pid);
            }
        });
    }

    // 4. Modal Quick Action Buttons
    document.getElementById('btn-select-all-prodi')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-prodi').forEach(cb => cb.checked = true);
    });
    document.getElementById('btn-clear-prodi')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-prodi').forEach(cb => cb.checked = false);
    });

    document.getElementById('btn-select-all-pem')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-pem').forEach(cb => cb.checked = true);
    });
    document.getElementById('btn-clear-pem')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-pem').forEach(cb => cb.checked = false);
    });

    // 5. Form Kuota Submit
    const formKuota = document.getElementById('form-kuota');
    if (formKuota) {
        formKuota.addEventListener('submit', handleFormKuotaSubmit);
    }

    // 6. Initial Load
    await loadUnit();
});

async function loadUnit(targetPeriodeId = null) {
    const tbody = document.getElementById('table-unit');
    const infoEl = document.getElementById('info-periode');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #64748b; padding: 28px;">Memuat alokasi kuota...</td></tr>';
    }

    try {
        let url = '/api/admin/unit/list.php';
        if (targetPeriodeId) {
            url += `?periode_id=${targetPeriodeId}`;
        }

        const res = await fetch(url);
        const data = await res.json();

        if (data.ok) {
            currentPeriodData = data.periode_terpilih;
            allUnitKuota = data.data || [];
            allJurusanList = data.all_jurusan || [];
            allPeminatanList = data.all_peminatan || [];

            // Populate Dropdown if not done yet or reload requested
            if (!isPeriodeSelectPopulated && Array.isArray(data.all_periode)) {
                const select = document.getElementById('select-periode-kuota');
                if (select) {
                    select.innerHTML = '';
                    data.all_periode.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        opt.innerText = `${p.nama} (${(p.status || 'DRAFT').toUpperCase()})`;
                        if (currentPeriodData && p.id == currentPeriodData.id) {
                            opt.selected = true;
                        }
                        select.appendChild(opt);
                    });
                    isPeriodeSelectPopulated = true;
                }
            }

            // Update Info Periode Banner
            if (infoEl) {
                if (currentPeriodData) {
                    const st = (currentPeriodData.status || '').toLowerCase();
                    if (st === 'dibuka') {
                        infoEl.innerHTML = `<span style="color: #10b981; font-weight: 700;">● Periode Aktif: ${escapeHtml(currentPeriodData.nama)} (PENDAFTARAN DIBUKA)</span>`;
                    } else if (st === 'persiapan') {
                        infoEl.innerHTML = `<span style="color: #d97706; font-weight: 700;">● Periode Persiapan: ${escapeHtml(currentPeriodData.nama)} (PERSIAPAN - SETTING KUOTA)</span>`;
                    } else {
                        infoEl.innerHTML = `<span style="color: #64748b; font-weight: 700;">● Periode: ${escapeHtml(currentPeriodData.nama)} (${st.toUpperCase()})</span>`;
                    }
                } else {
                    infoEl.innerHTML = `<span style="color: #ef4444; font-weight: 700;">Belum ada periode magang dibuat.</span>`;
                }
            }

            renderKuotaTable(allUnitKuota);
        } else {
            if (tbody) tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #ef4444; padding: 24px;">Gagal memuat data alokasi kuota.</td></tr>';
        }
    } catch (err) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #ef4444; padding: 24px;">Kesalahan jaringan saat memuat kuota.</td></tr>';
    }
}

function renderKuotaTable(dataArray) {
    const tbody = document.getElementById('table-unit');
    if (!tbody) return;

    if (!dataArray || dataArray.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #64748b; padding: 32px;">Belum ada kantor/unit yang membuka magang. Aktifkan toggle "Buka Magang" pada menu Hierarki PLN terlebih dahulu.</td></tr>';
        return;
    }

    tbody.innerHTML = '';
    dataArray.forEach(u => {
        const isAssigned = u.upp_id !== null && u.upp_id !== undefined;
        let statusHtml = '<span class="badge-status badge-draft">Belum Diset</span>';
        if (isAssigned) {
            statusHtml = u.aktif == 1
                ? '<span class="badge-status badge-dibuka">Aktif</span>'
                : '<span class="badge-status badge-ditutup">Disembunyikan</span>';
        }

        const totalK = isAssigned ? `<span style="font-weight: 700; color: #0f172a; font-size: 0.8125rem;">${u.kuota_total}</span> <span style="font-size: 0.75rem; color: #64748b;">slot</span>` : '<span style="color: #94a3b8; font-size: 0.8rem;">-</span>';
        const sisaK = isAssigned ? `<span style="font-weight: 700; color: ${u.kuota_tersisa > 0 ? '#10b981' : '#ef4444'}; font-size: 0.8125rem;">${u.kuota_tersisa}</span> <span style="font-size: 0.75rem; color: #64748b;">slot</span>` : '<span style="color: #94a3b8; font-size: 0.8rem;">-</span>';

        // Render Prodi Tags
        let prodiHtml = '';
        const totalJurusan = allJurusanList.length;
        const selectedJurCount = Array.isArray(u.prodi_details) ? u.prodi_details.length : 0;

        if (selectedJurCount === 0 || (totalJurusan > 0 && selectedJurCount >= totalJurusan)) {
            prodiHtml = '<span class="badge-status" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 0.725rem;">Semua Prodi (Umum)</span>';
        } else {
            const tags = u.prodi_details.map(p => 
                `<span class="badge-status" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 0.725rem; margin: 2px 2px; display: inline-flex;" title="${escapeHtml(p.nama_jurusan)}">${escapeHtml(p.nama_jurusan)}</span>`
            ).join('');
            prodiHtml = `<div style="max-width: 260px; display: flex; flex-wrap: wrap; gap: 2px;">${tags}</div>`;
        }

        // Render Peminatan Tags
        let peminatanHtml = '';
        if (Array.isArray(u.peminatan_details) && u.peminatan_details.length > 0) {
            peminatanHtml = `<span class="badge-status" style="background: #fefce8; color: #a16207; border: 1px solid #fef08a; font-size: 0.725rem; font-weight: 700;">${u.peminatan_details.length} Peminatan</span>`;
        } else {
            peminatanHtml = '<span style="color: #94a3b8; font-size: 0.8rem;">-</span>';
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.85rem; line-height: 1.35;">${escapeHtml(u.nama)}</div>
                ${u.singkatan ? `<div style="font-size: 0.725rem; color: #64748b; font-weight: 500; margin-top: 2px;">Singkatan: ${escapeHtml(u.singkatan)}</div>` : ''}
            </td>
            <td>${getTipeBadge(u.tipe)}</td>
            <td style="color: #475569; font-size: 0.8125rem;">${escapeHtml(u.nama_parent || '-')}</td>
            <td>${prodiHtml}</td>
            <td>${peminatanHtml}</td>
            <td>${totalK}</td>
            <td>${sisaK}</td>
            <td>${statusHtml}</td>
            <td>
                <button type="button" class="btn-set-kuota" style="padding: 5px 12px; font-size: 0.75rem; background: #0b3d6b; color: #ffffff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; white-space: nowrap;">
                    Atur Alokasi
                </button>
            </td>
        `;

        tr.querySelector('.btn-set-kuota').addEventListener('click', () => {
            openKuotaModal(u);
        });

        tbody.appendChild(tr);
    });
}

function applyKuotaFilter() {
    const query = document.getElementById('search-kuota').value.trim().toLowerCase();
    if (!query) {
        renderKuotaTable(allUnitKuota);
        return;
    }

    const filtered = allUnitKuota.filter(u => 
        (u.nama || '').toLowerCase().includes(query) ||
        (u.singkatan || '').toLowerCase().includes(query) ||
        (u.nama_parent || '').toLowerCase().includes(query) ||
        (u.tipe || '').toLowerCase().includes(query)
    );

    renderKuotaTable(filtered);
}

function openKuotaModal(unit) {
    document.getElementById('entitas-id').value = unit.entitas_id;
    document.getElementById('kuota-unit-nama').innerText = unit.nama;
    document.getElementById('kuota_total').value = unit.kuota_total || 0;
    document.getElementById('aktif').value = (unit.upp_id ? (unit.aktif || 1) : 1);

    // Render Checkboxes Prodi
    const prodiContainer = document.getElementById('container-checkbox-prodi');
    if (prodiContainer) {
        prodiContainer.innerHTML = '';
        const assignedProdiIds = Array.isArray(unit.prodi_ids) ? unit.prodi_ids : [];
        allJurusanList.forEach(j => {
            const isChecked = assignedProdiIds.includes(parseInt(j.id));
            const lbl = document.createElement('label');
            lbl.style.cssText = 'display: flex; align-items: center; gap: 8px; font-size: 0.825rem; color: #334155; cursor: pointer; user-select: none; padding: 4px 6px; border-radius: 6px;';
            lbl.innerHTML = `
                <input type="checkbox" value="${j.id}" class="chk-modal-prodi" ${isChecked ? 'checked' : ''} style="cursor: pointer;">
                <span title="${escapeHtml(j.nama_jurusan)}">${escapeHtml(j.nama_jurusan)}</span>
            `;
            prodiContainer.appendChild(lbl);
        });
    }

    // Render Checkboxes Peminatan
    const peminatanContainer = document.getElementById('container-checkbox-peminatan');
    if (peminatanContainer) {
        peminatanContainer.innerHTML = '';
        const assignedPemIds = Array.isArray(unit.peminatan_ids) ? unit.peminatan_ids : [];
        allPeminatanList.forEach(p => {
            const isChecked = assignedPemIds.includes(parseInt(p.id));
            const lbl = document.createElement('label');
            lbl.style.cssText = 'display: flex; align-items: center; gap: 8px; font-size: 0.825rem; color: #334155; cursor: pointer; user-select: none; padding: 4px 6px; border-radius: 6px;';
            lbl.innerHTML = `
                <input type="checkbox" value="${p.id}" class="chk-modal-pem" ${isChecked ? 'checked' : ''} style="cursor: pointer;">
                <span>${escapeHtml(p.nama)}</span>
            `;
            peminatanContainer.appendChild(lbl);
        });
    }

    document.getElementById('modal-kuota').classList.remove('hidden');
}

async function handleFormKuotaSubmit(e) {
    e.preventDefault();

    const entitasId = parseInt(document.getElementById('entitas-id').value, 10);
    const kuotaTotal = parseInt(document.getElementById('kuota_total').value, 10);
    const aktif = parseInt(document.getElementById('aktif').value, 10);
    const periodeSelect = document.getElementById('select-periode-kuota');
    const periodeId = periodeSelect ? parseInt(periodeSelect.value, 10) : (currentPeriodData ? currentPeriodData.id : null);

    if (!periodeId) {
        showAdminAlert('Tidak ada periode aktif yang dipilih!', 'error');
        return;
    }

    // Kumpulkan prodi_ids terpilih
    const prodiIds = Array.from(document.querySelectorAll('.chk-modal-prodi:checked')).map(cb => parseInt(cb.value, 10));

    // Kumpulkan peminatan_ids terpilih
    const peminatanIds = Array.from(document.querySelectorAll('.chk-modal-pem:checked')).map(cb => parseInt(cb.value, 10));

    const submitBtn = document.getElementById('btn-submit-kuota');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = 'Menyimpan...';
    }

    try {
        const res = await fetch('/api/admin/unit/update_kuota.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                entitas_id: entitasId,
                periode_id: periodeId,
                kuota_total: kuotaTotal,
                aktif: aktif,
                prodi_ids: prodiIds,
                peminatan_ids: peminatanIds
            })
        });

        const data = await res.json();
        if (res.ok && data.ok) {
            document.getElementById('modal-kuota').classList.add('hidden');
            showAdminAlert('Alokasi kuota unit berhasil disimpan!', 'success');
            await loadUnit(periodeId);
        } else {
            showAdminAlert(data.error || 'Gagal menyimpan kuota unit.', 'error');
        }
    } catch (err) {
        showAdminAlert('Terjadi kesalahan jaringan atau server.', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Simpan Alokasi';
        }
    }
}

