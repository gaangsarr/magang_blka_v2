/**
 * public/js/admin/unit.js
 * Manajemen alokasi kuota penerimaan magang per periode
 */

import { showAdminAlert } from './common.js';

let csrfToken = null;
let allUnitKuota = [];
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

    // 4. Form Kuota Submit
    const formKuota = document.getElementById('form-kuota');
    if (formKuota) {
        formKuota.addEventListener('submit', handleFormKuotaSubmit);
    }

    // 5. Initial Load
    await loadUnit();
});

async function loadUnit(targetPeriodeId = null) {
    const tbody = document.getElementById('table-unit');
    const infoEl = document.getElementById('info-periode');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #64748b; padding: 28px;">Memuat alokasi kuota...</td></tr>';
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
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #ef4444; padding: 24px;">Gagal memuat data alokasi kuota.</td></tr>';
        }
    } catch (err) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #ef4444; padding: 24px;">Kesalahan jaringan saat memuat kuota.</td></tr>';
    }
}

function renderKuotaTable(dataArray) {
    const tbody = document.getElementById('table-unit');
    if (!tbody) return;

    if (!dataArray || dataArray.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #64748b; padding: 32px;">Belum ada kantor/unit yang membuka magang. Aktifkan toggle "Buka Magang" pada menu Hierarki PLN terlebih dahulu.</td></tr>';
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

        const totalK = isAssigned ? `<strong>${u.kuota_total}</strong> slot` : '<span style="color: #94a3b8;">-</span>';
        const sisaK = isAssigned ? `<span style="font-weight: 700; color: ${u.kuota_tersisa > 0 ? '#10b981' : '#ef4444'};">${u.kuota_tersisa}</span> slot` : '<span style="color: #94a3b8;">-</span>';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.925rem;">${escapeHtml(u.nama)}</div>
                ${u.singkatan ? `<div style="font-size: 0.75rem; color: #64748b; font-weight: 600;">Singkatan: ${escapeHtml(u.singkatan)}</div>` : ''}
            </td>
            <td>${getTipeBadge(u.tipe)}</td>
            <td style="color: #475569; font-size: 0.875rem;">${escapeHtml(u.nama_parent || '-')}</td>
            <td>${totalK}</td>
            <td>${sisaK}</td>
            <td>${statusHtml}</td>
            <td>
                <button type="button" class="btn-set-kuota" style="padding: 6px 14px; font-size: 0.8rem; background: #0b3d6b; color: #ffffff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
                    Atur Kuota
                </button>
            </td>
        `;

        tr.querySelector('.btn-set-kuota').addEventListener('click', () => {
            openKuotaModal(u.entitas_id, u.nama, u.kuota_total || 0, isAssigned ? (u.aktif || 1) : 1);
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

function openKuotaModal(entitasId, nama, currentKuota, currentAktif) {
    document.getElementById('entitas-id').value = entitasId;
    document.getElementById('kuota-unit-nama').innerText = nama;
    document.getElementById('kuota_total').value = currentKuota;
    document.getElementById('aktif').value = currentAktif;
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
                aktif: aktif
            })
        });

        const data = await res.json();
        if (res.ok && data.ok) {
            document.getElementById('modal-kuota').classList.add('hidden');
            showAdminAlert('Kuota unit berhasil disimpan!', 'success');
            await loadUnit(periodeId);
        } else {
            showAdminAlert(data.error || 'Gagal menyimpan kuota unit.', 'error');
        }
    } catch (err) {
        showAdminAlert('Terjadi kesalahan jaringan atau server.', 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Simpan Kuota';
        }
    }
}
