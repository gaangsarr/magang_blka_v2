/**
 * public/js/admin/pic-narahubung.js
 * Manajemen Master PIC Narahubung Mahasiswa & Tampilan Unit Terhubung
 */

import { showAdminAlert, showAdminConfirm, showAdminToast, loadAdminProfileHeader } from '/js/admin/common.js';

let picDataList = [];
let distinctAreas = [];
let csrfToken = null;
let currentConnectedUnits = [];

// DOM Elements
const searchInput = document.getElementById('search-pic');
const filterAreaSelect = document.getElementById('filter-area-hcbp');
const filterStatusSelect = document.getElementById('filter-status-pic');
const tableBody = document.getElementById('table-pic-body');
const totalCountBadge = document.getElementById('total-count-badge');

// Stats Elements
const statTotalPic = document.getElementById('stat-total-pic');
const statTotalConnected = document.getElementById('stat-total-connected');
const statTotalAreas = document.getElementById('stat-total-areas');

// Modal Elements
const modalPic = document.getElementById('modal-pic');
const modalPicTitle = document.getElementById('modal-pic-title');
const formPic = document.getElementById('form-pic');
const btnAddPic = document.getElementById('btn-add-pic');
const btnCloseModal = document.getElementById('btn-close-modal');
const btnCancelModal = document.getElementById('btn-cancel-modal');

// Form Input Elements
const inputPicId = document.getElementById('pic-id');
const selectPicEntitasId = document.getElementById('pic-entitas-id');
const inputPicAreaHcbp = document.getElementById('pic-area-hcbp');
const datalistAreaHcbp = document.getElementById('list-area-hcbp');
const inputPicNama = document.getElementById('pic-nama');
const inputPicNoWa = document.getElementById('pic-no-wa');
const inputPicEmail = document.getElementById('pic-email');
const textareaPicKeterangan = document.getElementById('pic-keterangan');
const selectPicAktif = document.getElementById('pic-aktif');
const btnSubmitPic = document.getElementById('btn-submit-pic');

// Connected Units Modal Elements
const modalConnected = document.getElementById('modal-connected-units');
const btnCloseConnectedModal = document.getElementById('btn-close-connected-modal');
const btnCloseConnectedFooter = document.getElementById('btn-close-connected-footer');
const modalConnectedAreaBadge = document.getElementById('modal-connected-area-badge');
const modalConnectedTypeBadge = document.getElementById('modal-connected-type-badge');
const modalConnectedUnitName = document.getElementById('modal-connected-unit-name');
const modalConnectedPicName = document.getElementById('modal-connected-pic-name');
const modalConnectedPicPhone = document.getElementById('modal-connected-pic-phone');
const modalConnectedListContainer = document.getElementById('modal-connected-list-container');
const modalConnectedCountSummary = document.getElementById('modal-connected-count-summary');
const searchConnectedInput = document.getElementById('search-connected-input');

document.addEventListener('DOMContentLoaded', async () => {
    await loadAdminProfileHeader();
    await fetchCsrfToken();
    await fetchPicList();
    setupEventListeners();
});

async function fetchCsrfToken() {
    try {
        const res = await fetch('/api/admin/auth.php?action=csrf');
        const data = await res.json();
        if (data.ok && data.csrf_token) {
            csrfToken = data.csrf_token;
        }
    } catch (e) {
        console.warn('Could not prefetch CSRF token:', e);
    }
}

async function getValidCsrfToken() {
    if (!csrfToken) {
        await fetchCsrfToken();
    }
    return csrfToken;
}

/**
 * Fetch and render PIC Narahubung list
 */
async function fetchPicList() {
    try {
        tableBody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 32px; color: #64748b;">Memuat data PIC Narahubung...</td></tr>';

        const search = searchInput ? searchInput.value.trim() : '';
        const area = filterAreaSelect ? filterAreaSelect.value : '';
        const status = filterStatusSelect ? filterStatusSelect.value : '';

        const params = new URLSearchParams();
        if (search) params.append('search', search);
        if (area) params.append('area_hcbp', area);
        if (status !== '') params.append('status', status);

        const res = await fetch(`/api/admin/pic_narahubung/list.php?${params.toString()}`);
        if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
        }

        const data = await res.json();
        if (!data.ok) {
            throw new Error(data.error || 'Gagal memuat data.');
        }

        picDataList = data.data || [];
        distinctAreas = data.areas || [];

        // Update stats
        if (data.stats) {
            if (statTotalPic) statTotalPic.textContent = data.stats.total_pic;
            if (statTotalConnected) statTotalConnected.textContent = `${data.stats.total_unit_terhubung} Unit`;
            if (statTotalAreas) statTotalAreas.textContent = `${data.stats.total_area} Area`;
        }

        if (totalCountBadge) {
            totalCountBadge.textContent = `${picDataList.length} PIC`;
        }

        populateAreaFilter(distinctAreas);
        renderTable(picDataList);

    } catch (err) {
        console.error('Error fetching PIC list:', err);
        tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 24px; color: #ef4444;">Gagal memuat data PIC Narahubung: ${escapeHtml(err.message)}</td></tr>`;
    }
}

/**
 * Populate Area HCBP filter & datalist options
 */
function populateAreaFilter(areas) {
    if (filterAreaSelect && filterAreaSelect.options.length <= 1) {
        const currentVal = filterAreaSelect.value;
        filterAreaSelect.innerHTML = '<option value="">Semua Area HCBP</option>';
        areas.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a;
            opt.textContent = a;
            filterAreaSelect.appendChild(opt);
        });
        filterAreaSelect.value = currentVal;
    }

    if (datalistAreaHcbp) {
        datalistAreaHcbp.innerHTML = '';
        areas.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a;
            datalistAreaHcbp.appendChild(opt);
        });
    }
}

/**
 * Render main PIC table
 */
function renderTable(list) {
    if (!list || list.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">Tidak ada data PIC Narahubung yang sesuai dengan filter pencarian.</td></tr>';
        return;
    }

    tableBody.innerHTML = list.map((item, idx) => {
        const typeBadge = formatTypeBadge(item.tipe_entitas, item.singkatan_entitas);
        const activeBadge = item.aktif
            ? `<span class="badge-status badge-dibuka" style="cursor: pointer; user-select: none;" title="Klik untuk nonaktifkan" onclick="window.togglePicStatus(${item.id})">Aktif</span>`
            : `<span class="badge-status badge-ditutup" style="cursor: pointer; user-select: none;" title="Klik untuk aktifkan" onclick="window.togglePicStatus(${item.id})">Nonaktif</span>`;

        let connectedBtn = '';
        if (parseInt(item.entitas_id) === 1) {
            connectedBtn = `<span style="font-size: 0.725rem; color: #64748b; font-weight: 600; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 3px 10px; border-radius: 9999px;">Mandiri (Pusat)</span>`;
        } else if (item.total_unit_terhubung > 0) {
            connectedBtn = `<button type="button" class="btn-view-connected" onclick="window.openConnectedUnitsModal(${item.entitas_id})" title="Lihat daftar ${item.total_unit_terhubung} unit bawahan">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                <span>${item.total_unit_terhubung} Unit Terhubung</span>
               </button>`;
        } else {
            connectedBtn = `<span style="font-size: 0.75rem; color: #94a3b8; font-style: italic;">0 Unit</span>`;
        }

        const phoneDisplay = item.no_wa
            ? `<div style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; color: #065f46; font-size: 0.85rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>
                <span>${escapeHtml(item.no_wa)}</span>
               </div>`
            : '<span style="color: #94a3b8; font-size: 0.8rem; font-style: italic;">-</span>';

        const emailDisplay = item.email
            ? `<div style="font-size: 0.725rem; color: #64748b; margin-top: 2px;">${escapeHtml(item.email)}</div>`
            : '';

        const areaDisplay = item.area_hcbp
            ? `<span class="area-hcbp-pill">${escapeHtml(item.area_hcbp)}</span>`
            : '<span style="color: #94a3b8; font-size: 0.8rem;">-</span>';

        return `
            <tr>
                <td style="text-align: center; color: #64748b; font-weight: 600; font-size: 0.85rem;">${idx + 1}</td>
                <td>${areaDisplay}</td>
                <td>
                    <div style="font-weight: 700; color: #0b3d6b; font-size: 0.875rem; line-height: 1.35;">${escapeHtml(item.nama_entitas)}</div>
                    <div style="margin-top: 4px;">
                        ${typeBadge}
                    </div>
                </td>
                <td>
                    <div style="font-weight: 700; color: #1e293b; font-size: 0.875rem;">${escapeHtml(item.nama_pic)}</div>
                    ${item.keterangan ? `<div style="font-size: 0.75rem; color: #64748b; margin-top: 2px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(item.keterangan)}</div>` : ''}
                </td>
                <td>
                    ${phoneDisplay}
                    ${emailDisplay}
                </td>
                <td style="text-align: center;">
                    ${connectedBtn}
                </td>
                <td style="text-align: center;">
                    ${activeBadge}
                </td>
                <td style="text-align: center;">
                    <div style="display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        <button type="button" class="btn-action-icon edit" onclick="window.editPic(${item.id})" title="Edit Data PIC">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                        </button>
                        <button type="button" class="btn-action-icon delete" onclick="window.deletePic(${item.id}, '${escapeHtml(item.nama_pic)}')" title="Hapus PIC">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

function formatTypeBadge(type, singkatan) {
    let label = '';
    if (type === 'unit_induk') {
        label = singkatan ? `Unit Induk • ${singkatan}` : 'Unit Induk';
        return `<span class="unit-type-pill" style="color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd;">${escapeHtml(label)}</span>`;
    } else if (type === 'holding') {
        label = singkatan ? `Holding • ${singkatan}` : 'Holding';
        return `<span class="unit-type-pill" style="color: #7c2d12; background: #ffedd5; border: 1px solid #fed7aa;">${escapeHtml(label)}</span>`;
    } else if (type === 'subholding') {
        label = singkatan ? `Subholding • ${singkatan}` : 'Subholding';
        return `<span class="unit-type-pill" style="color: #431407; background: #fed7aa; border: 1px solid #fdba74;">${escapeHtml(label)}</span>`;
    } else if (type === 'anak_perusahaan') {
        label = singkatan ? `Anak Perusahaan • ${singkatan}` : 'Anak Perusahaan';
        return `<span class="unit-type-pill" style="color: #6b21a8; background: #f3e8ff; border: 1px solid #e9d5ff;">${escapeHtml(label)}</span>`;
    } else {
        label = singkatan || type || '-';
        return `<span class="unit-type-pill" style="color: #475569; background: #f1f5f9; border: 1px solid #e2e8f0;">${escapeHtml(label)}</span>`;
    }
}

/**
 * Load available entity units for select dropdown in modal
 */
async function loadUnitOptions(selectedEntitasId = null, currentPicId = null) {
    try {
        selectPicEntitasId.innerHTML = '<option value="">Memuat daftar unit...</option>';
        const url = currentPicId ? `/api/admin/pic_narahubung/options_unit.php?current_pic_id=${currentPicId}` : '/api/admin/pic_narahubung/options_unit.php';
        const res = await fetch(url);
        const data = await res.json();

        if (!data.ok || !data.data) {
            throw new Error(data.error || 'Gagal mengambil opsi unit.');
        }

        const groups = {
            'holding': 'Holding (Kantor Pusat & Pusat-Pusat)',
            'unit_induk': 'Unit Induk (Distribusi / Wilayah / UIP / UIT / UIK / UIP3B)',
            'subholding': 'Subholding PLN',
            'anak_perusahaan': 'Anak Perusahaan PLN'
        };

        const grouped = {};
        Object.keys(groups).forEach(k => grouped[k] = []);

        data.data.forEach(u => {
            const t = u.tipe || 'unit_induk';
            if (!grouped[t]) grouped[t] = [];
            grouped[t].push(u);
        });

        let html = '<option value="">Pilih Unit Induk / Perusahaan...</option>';

        Object.keys(groups).forEach(k => {
            if (grouped[k] && grouped[k].length > 0) {
                html += `<optgroup label="${groups[k]}">`;
                grouped[k].forEach(u => {
                    const isSelected = selectedEntitasId && (parseInt(selectedEntitasId) === parseInt(u.id));
                    const disabledAttr = (u.is_occupied && !isSelected) ? 'disabled' : '';
                    const labelExtra = u.is_occupied && !isSelected ? ` (Sudah ada PIC: ${escapeHtml(u.occupied_by)})` : '';
                    html += `<option value="${u.id}" ${isSelected ? 'selected' : ''} ${disabledAttr}>${escapeHtml(u.nama)}${labelExtra}</option>`;
                });
                html += `</optgroup>`;
            }
        });

        selectPicEntitasId.innerHTML = html;

    } catch (e) {
        console.error('Error loading unit options:', e);
        selectPicEntitasId.innerHTML = '<option value="">Gagal memuat opsi unit</option>';
    }
}

/**
 * Open Create / Edit Modal
 */
async function openPicModal(picId = null) {
    formPic.reset();
    inputPicId.value = '';

    if (picId) {
        const item = picDataList.find(p => p.id === parseInt(picId));
        if (!item) return;

        modalPicTitle.textContent = 'Edit Data PIC Narahubung';
        inputPicId.value = item.id;
        inputPicAreaHcbp.value = item.area_hcbp || '';
        inputPicNama.value = item.nama_pic || '';
        inputPicNoWa.value = item.no_wa || '';
        inputPicEmail.value = item.email || '';
        textareaPicKeterangan.value = item.keterangan || '';
        selectPicAktif.value = item.aktif ? '1' : '0';

        await loadUnitOptions(item.entitas_id, item.id);
    } else {
        modalPicTitle.textContent = 'Tambah PIC Narahubung Baru';
        selectPicAktif.value = '1';
        await loadUnitOptions();
    }

    modalPic.classList.remove('hidden');
}

function closePicModal() {
    modalPic.classList.add('hidden');
}

/**
 * Submit Save PIC Form
 */
async function handleFormSubmit(e) {
    e.preventDefault();

    const picId = inputPicId.value.trim();
    const entitasId = selectPicEntitasId.value;
    const namaPic = inputPicNama.value.trim();
    const noWa = inputPicNoWa.value.trim();
    const areaHcbp = inputPicAreaHcbp.value.trim();
    const email = inputPicEmail.value.trim();
    const keterangan = textareaPicKeterangan.value.trim();
    const aktif = parseInt(selectPicAktif.value);

    if (!entitasId) {
        showAdminToast('Unit Induk / Perusahaan wajib dipilih.', 'warning');
        selectPicEntitasId.focus();
        return;
    }

    if (!namaPic) {
        showAdminToast('Nama lengkap PIC wajib diisi.', 'warning');
        inputPicNama.focus();
        return;
    }

    if (!noWa) {
        showAdminToast('Nomor WhatsApp / HP wajib diisi.', 'warning');
        inputPicNoWa.focus();
        return;
    }

    btnSubmitPic.disabled = true;
    btnSubmitPic.textContent = 'Menyimpan...';

    try {
        const isEdit = !!picId;
        const endpoint = isEdit ? '/api/admin/pic_narahubung/update.php' : '/api/admin/pic_narahubung/create.php';
        const payload = {
            id: isEdit ? parseInt(picId) : undefined,
            entitas_id: parseInt(entitasId),
            nama_pic: namaPic,
            no_wa: noWa,
            area_hcbp: areaHcbp || null,
            email: email || null,
            keterangan: keterangan || null,
            aktif: aktif
        };

        const token = await getValidCsrfToken();
        const res = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Gagal menyimpan data.');
        }

        showAdminToast(data.message || 'Data PIC Narahubung berhasil disimpan.', 'success');
        closePicModal();
        await fetchPicList();

    } catch (err) {
        console.error('Error saving PIC:', err);
        showAdminAlert(err.message, 'error', 'Gagal Menyimpan');
    } finally {
        btnSubmitPic.disabled = false;
        btnSubmitPic.textContent = 'Simpan';
    }
}

/**
 * Toggle active status
 */
window.togglePicStatus = async function(id) {
    const item = picDataList.find(p => p.id === parseInt(id));
    if (!item) return;

    try {
        const token = await getValidCsrfToken();
        const res = await fetch('/api/admin/pic_narahubung/toggle_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token
            },
            body: JSON.stringify({ id })
        });

        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Gagal mengubah status.');
        }

        showAdminToast(`Status PIC ${item.nama_pic} berhasil diubah.`, 'success');
        await fetchPicList();

    } catch (err) {
        console.error('Error toggling status:', err);
        showAdminAlert(err.message, 'error', 'Gagal Mengubah Status');
    }
};

/**
 * Edit button handler
 */
window.editPic = function(id) {
    openPicModal(id);
};

/**
 * Delete button handler
 */
window.deletePic = async function(id, name) {
    const confirmed = await showAdminConfirm(
        `Apakah Anda yakin ingin menghapus data PIC Narahubung <strong>${escapeHtml(name)}</strong>? Unit bawahan tidak akan lagi memiliki kontak narahubung ini.`,
        'Hapus PIC Narahubung',
        'danger',
        'Ya, Hapus',
        'Batal'
    );

    if (!confirmed) return;

    try {
        const token = await getValidCsrfToken();
        const res = await fetch('/api/admin/pic_narahubung/delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token
            },
            body: JSON.stringify({ id })
        });

        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Gagal menghapus.');
        }

        showAdminToast('PIC Narahubung berhasil dihapus.', 'success');
        await fetchPicList();

    } catch (err) {
        console.error('Error deleting PIC:', err);
        showAdminAlert(err.message, 'error', 'Gagal Menghapus');
    }
};

/**
 * Open Connected Units Modal
 */
window.openConnectedUnitsModal = async function(entitasId) {
    try {
        modalConnectedListContainer.innerHTML = '<div style="text-align: center; padding: 32px; color: #64748b;">Memuat pohon unit bawahan...</div>';
        searchConnectedInput.value = '';
        modalConnected.classList.remove('hidden');

        const res = await fetch(`/api/admin/pic_narahubung/connected_units.php?entitas_id=${entitasId}`);
        const data = await res.json();

        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Gagal memuat data unit terhubung.');
        }

        const parent = data.parent_unit || {};
        const pic = data.pic || {};
        currentConnectedUnits = data.data || [];

        modalConnectedUnitName.textContent = parent.nama || 'Unit Induk';
        modalConnectedAreaBadge.textContent = pic.area_hcbp || 'HCBP Area';
        modalConnectedTypeBadge.textContent = parent.tipe ? parent.tipe.toUpperCase() : 'UNIT';
        modalConnectedPicName.textContent = pic.nama_pic || 'Tidak ada PIC aktif';
        modalConnectedPicPhone.textContent = pic.no_wa || '-';

        renderConnectedUnitsList(currentConnectedUnits);

    } catch (err) {
        console.error('Error loading connected units:', err);
        modalConnectedListContainer.innerHTML = `<div style="text-align: center; padding: 24px; color: #ef4444;">Gagal memuat unit bawahan: ${escapeHtml(err.message)}</div>`;
    }
};

function renderConnectedUnitsList(units) {
    if (!units || units.length === 0) {
        modalConnectedListContainer.innerHTML = '<div style="text-align: center; padding: 32px; color: #64748b;">Unit ini tidak memiliki kantor/unit bawahan terdaftar.</div>';
        modalConnectedCountSummary.textContent = 'Total: 0 Unit Bawahan';
        return;
    }

    modalConnectedCountSummary.textContent = `Total: ${units.length} Unit Bawahan (${units.filter(u => u.tipe === 'unit_pelaksana').length} UP3, ${units.filter(u => u.tipe === 'unit_layanan').length} ULP)`;

    let html = '<div style="display: flex; flex-direction: column; divide-y: 1px solid #f1f5f9;">';

    units.forEach((u, i) => {
        const isUp3 = u.tipe === 'unit_pelaksana';
        const levelIndent = u.level > 1 ? (u.level - 1) * 20 : 0;
        const iconColor = isUp3 ? '#0284c7' : '#16a34a';
        const typeBadge = isUp3
            ? '<span style="font-size: 0.675rem; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px;">UP3</span>'
            : '<span style="font-size: 0.675rem; font-weight: 700; color: #16a34a; background: #dcfce7; padding: 2px 6px; border-radius: 4px;">ULP</span>';

        html += `
            <div style="padding: 10px 16px; padding-left: ${16 + levelIndent}px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid #f1f5f9; background: ${isUp3 ? '#f8fafc' : '#ffffff'};">
                <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                    <div style="color: ${iconColor}; flex-shrink: 0;">
                        ${isUp3
                            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>'
                            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>'
                        }
                    </div>
                    <div style="min-width: 0;">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 0.85rem; font-weight: 700; color: #1e293b;">${escapeHtml(u.nama)}</span>
                            ${typeBadge}
                        </div>
                        ${u.parent_nama ? `<div style="font-size: 0.725rem; color: #64748b; margin-top: 2px;">Induk: ${escapeHtml(u.parent_nama)}</div>` : ''}
                    </div>
                </div>
                <div>
                    <span style="font-size: 0.7rem; color: #16a34a; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">Mewarisi Narahubung</span>
                </div>
            </div>
        `;
    });

    html += '</div>';
    modalConnectedListContainer.innerHTML = html;
}

function closeConnectedUnitsModal() {
    modalConnected.classList.add('hidden');
}

/**
 * Event Listeners
 */
function setupEventListeners() {
    if (btnAddPic) btnAddPic.addEventListener('click', () => openPicModal());
    if (btnCloseModal) btnCloseModal.addEventListener('click', closePicModal);
    if (btnCancelModal) btnCancelModal.addEventListener('click', closePicModal);
    if (modalPic) {
        modalPic.addEventListener('click', (e) => {
            if (e.target === modalPic) closePicModal();
        });
    }

    if (formPic) formPic.addEventListener('submit', handleFormSubmit);

    // Connected modal close handlers
    if (btnCloseConnectedModal) btnCloseConnectedModal.addEventListener('click', closeConnectedUnitsModal);
    if (btnCloseConnectedFooter) btnCloseConnectedFooter.addEventListener('click', closeConnectedUnitsModal);
    if (modalConnected) {
        modalConnected.addEventListener('click', (e) => {
            if (e.target === modalConnected) closeConnectedUnitsModal();
        });
    }

    // Search subordinate units inside modal
    if (searchConnectedInput) {
        searchConnectedInput.addEventListener('input', () => {
            const q = searchConnectedInput.value.toLowerCase().trim();
            if (!q) {
                renderConnectedUnitsList(currentConnectedUnits);
                return;
            }
            const filtered = currentConnectedUnits.filter(u => 
                (u.nama && u.nama.toLowerCase().includes(q)) || 
                (u.singkatan && u.singkatan.toLowerCase().includes(q)) ||
                (u.parent_nama && u.parent_nama.toLowerCase().includes(q))
            );
            renderConnectedUnitsList(filtered);
        });
    }

    // Filter change handlers with debounce
    let debounceTimer;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => fetchPicList(), 300);
        });
    }

    if (filterAreaSelect) {
        filterAreaSelect.addEventListener('change', () => fetchPicList());
    }

    if (filterStatusSelect) {
        filterStatusSelect.addEventListener('change', () => fetchPicList());
    }
}

function escapeHtml(unsafe) {
    if (!unsafe && unsafe !== 0) return '';
    return String(unsafe)
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}
