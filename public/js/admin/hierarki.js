/**
 * public/js/admin/hierarki.js
 * Modul terpadu untuk CRUD dan manajemen hierarki entitas perusahaan PLN
 * (Holding, Subholding, Anak Perusahaan, Unit Induk, Unit Pelaksana)
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

export function initHierarkiPage(config) {
    let csrfToken = null;
    let masterPeminatan = [];
    let parentOptions = [];
    let entitasList = [];

    const {
        tipe,
        label,
        hasParent = false,
        parentTipes = [],
        parentLabel = 'Parent Entitas'
    } = config;

    async function start() {
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

        // 2. Fetch Master Peminatan (use admin API with fallback)
        try {
            let resPem = await fetch('/api/admin/peminatan/list.php');
            if (!resPem.ok) {
                resPem = await fetch('/api/peminatan/list.php');
            }
            const dataPem = await resPem.json();
            if (dataPem.ok && Array.isArray(dataPem.data)) {
                masterPeminatan = dataPem.data.filter(p => p.aktif === undefined || p.aktif == 1 || p.aktif === true || p.aktif === '1');
                renderPeminatanCheckboxes();
            }
        } catch (err) {
            console.error('Error fetching peminatan:', err);
        }

        // 3. Fetch Parent Candidates if hasParent
        if (hasParent && parentTipes.length > 0) {
            await loadParentCandidates();
        }

        // 4. Initial Load Table Data
        await loadData();

        // 5. Setup Search Filter
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                applySearchFilter(searchInput.value.trim().toLowerCase());
            });
        }

        // 6. Setup Modal Listeners
        const btnAdd = document.getElementById('btn-add-entitas');
        if (btnAdd) {
            btnAdd.addEventListener('click', () => openCreateModal());
        }

        const btnCloseModal = document.getElementById('btn-close-modal');
        if (btnCloseModal) {
            btnCloseModal.addEventListener('click', () => hideModal());
        }

        const btnCancelModal = document.getElementById('btn-cancel-modal');
        if (btnCancelModal) {
            btnCancelModal.addEventListener('click', () => hideModal());
        }

        const form = document.getElementById('form-entitas');
        if (form) {
            form.addEventListener('submit', handleFormSubmit);
        }

        // Toggle peminatan section visibility based on "menerima_magang" checkbox in modal
        const chkMagang = document.getElementById('entitas-menerima-magang');
        if (chkMagang) {
            chkMagang.addEventListener('change', () => {
                updatePeminatanSectionState(chkMagang.checked);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    async function loadParentCandidates() {
        try {
            const res = await fetch('/api/admin/entitas/list.php?aktif=1');
            const data = await res.json();
            if (data.ok && Array.isArray(data.data)) {
                parentOptions = data.data.filter(e => parentTipes.includes(e.tipe));
                populateParentSelect();
            }
        } catch (err) {
            console.error('Error loading parents:', err);
        }
    }

    function populateParentSelect() {
        const select = document.getElementById('entitas-parent-id');
        if (!select) return;
        select.innerHTML = `<option value="">-- Pilih ${parentLabel} --</option>`;
        parentOptions.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            const tipeText = p.tipe.replace('_', ' ').toUpperCase();
            opt.innerText = `${p.nama} (${tipeText})`;
            select.appendChild(opt);
        });
    }

    async function fetchPeminatanIfEmpty() {
        if (masterPeminatan.length === 0) {
            try {
                let resPem = await fetch('/api/admin/peminatan/list.php');
                if (!resPem.ok) {
                    resPem = await fetch('/api/peminatan/list.php');
                }
                const dataPem = await resPem.json();
                if (dataPem.ok && Array.isArray(dataPem.data)) {
                    masterPeminatan = dataPem.data.filter(p => p.aktif === undefined || p.aktif == 1 || p.aktif === true || p.aktif === '1');
                }
            } catch (err) {
                console.error('Error fetching peminatan fallback:', err);
            }
        }
        renderPeminatanCheckboxes();
    }

    function renderPeminatanCheckboxes() {
        const container = document.getElementById('peminatan-checkboxes-container');
        if (!container) return;
        container.innerHTML = '';
        if (masterPeminatan.length === 0) {
            container.innerHTML = '<span style="font-size: 0.8rem; color: #64748b;">Belum ada master data peminatan aktif. Silakan tambahkan pada menu Master Peminatan.</span>';
            return;
        }

        masterPeminatan.forEach(pem => {
            const labelEl = document.createElement('label');
            labelEl.style.display = 'flex';
            labelEl.style.alignItems = 'center';
            labelEl.style.gap = '8px';
            labelEl.style.fontSize = '0.85rem';
            labelEl.style.color = '#334155';
            labelEl.style.cursor = 'pointer';
            labelEl.style.padding = '6px 10px';
            labelEl.style.background = '#f8fafc';
            labelEl.style.borderRadius = '6px';
            labelEl.style.border = '1px solid #cbd5e1';

            labelEl.innerHTML = `
                <input type="checkbox" class="chk-pem-item" value="${pem.id}" style="cursor: pointer;" />
                <span>${escapeHtml(pem.nama)}</span>
            `;
            container.appendChild(labelEl);
        });
    }

    function updatePeminatanSectionState(isMenerimaMagang) {
        const wrapper = document.getElementById('peminatan-field-wrapper');
        const badgeReq = document.getElementById('peminatan-req-badge');
        if (wrapper) {
            if (isMenerimaMagang) {
                wrapper.style.opacity = '1';
                wrapper.style.pointerEvents = 'auto';
                if (badgeReq) badgeReq.style.display = 'inline';
            } else {
                wrapper.style.opacity = '0.5';
                wrapper.style.pointerEvents = 'none';
                if (badgeReq) badgeReq.style.display = 'none';
            }
        }
    }

    async function loadData() {
        const tbody = document.getElementById('table-entitas-body');
        const colSpan = hasParent ? 6 : 5;
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #64748b;">Memuat data...</td></tr>`;
        }

        try {
            const res = await fetch(`/api/admin/entitas/list.php?tipe=${tipe}&with_peminatan=1`);
            const data = await res.json();
            if (data.ok && Array.isArray(data.data)) {
                entitasList = data.data;
                renderTable(entitasList);
            } else {
                if (tbody) tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #ef4444;">Gagal memuat data.</td></tr>`;
            }
        } catch (err) {
            if (tbody) tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #ef4444;">Kesalahan jaringan saat memuat data.</td></tr>`;
        }
    }

    function renderTable(dataArray) {
        const tbody = document.getElementById('table-entitas-body');
        const countBadge = document.getElementById('total-count-badge');
        const colSpan = hasParent ? 6 : 5;
        if (!tbody) return;

        if (countBadge) {
            countBadge.innerText = `${dataArray.length} ${label}`;
        }

        if (dataArray.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 36px; color: #64748b;">Belum ada data ${label}. Klik tombol "+ Tambah ${label}" untuk membuat baru.</td></tr>`;
            return;
        }

        tbody.innerHTML = '';

        if (hasParent) {
            // Group by Parent Name
            const grouped = {};
            dataArray.forEach(item => {
                const pKey = item.nama_parent || 'Tanpa Parent';
                if (!grouped[pKey]) grouped[pKey] = [];
                grouped[pKey].push(item);
            });

            for (const [parentName, items] of Object.entries(grouped)) {
                // Group Header Row
                const trGroup = document.createElement('tr');
                trGroup.className = 'table-group-header';
                trGroup.innerHTML = `
                    <td colspan="${colSpan}">
                        <div class="table-group-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b3d6b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                            <span>${escapeHtml(parentName)}</span>
                            <span class="table-group-count">${items.length} ${label}</span>
                        </div>
                    </td>
                `;
                tbody.appendChild(trGroup);

                // Group Items
                items.forEach(item => {
                    tbody.appendChild(createRow(item));
                });
            }
        } else {
            // Flat List (Holding)
            dataArray.forEach(item => {
                tbody.appendChild(createRow(item));
            });
        }
    }

    function createRow(item) {
        const tr = document.createElement('tr');

        // Peminatan Tags
        let peminatanHtml = '<span style="color: #94a3b8; font-size: 0.8rem;">-</span>';
        if (item.peminatan_names && item.peminatan_names.length > 0) {
            peminatanHtml = `<div class="peminatan-tag-list">${item.peminatan_names.map(name => `<span class="peminatan-tag">${escapeHtml(name)}</span>`).join('')}</div>`;
        }

        // Toggle Switch Menerima Magang
        const checkedAttr = item.menerima_magang ? 'checked' : '';
        const magangBadge = item.menerima_magang
            ? '<span class="badge-status badge-magang-ya"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Buka Magang</span>'
            : '<span class="badge-status badge-magang-tidak">Tidak Buka</span>';

        tr.innerHTML = `
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.925rem;">${escapeHtml(item.nama)}</div>
                ${item.singkatan ? `<div style="font-size: 0.75rem; color: #64748b; font-weight: 600;">Singkatan: ${escapeHtml(item.singkatan)}</div>` : ''}
            </td>
            ${hasParent ? `<td style="color: #334155; font-weight: 600; font-size: 0.875rem;">${escapeHtml(item.nama_parent || '-')}</td>` : ''}
            <td style="color: #475569; font-size: 0.85rem; max-width: 200px;">
                <div>${escapeHtml(item.alamat || '-')}</div>
                ${item.latitude && item.longitude ? `<div style="font-family: monospace; font-size: 0.75rem; color: #94a3b8; margin-top: 2px;">${item.latitude}, ${item.longitude}</div>` : ''}
            </td>
            <td>${peminatanHtml}</td>
            <td>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label class="switch">
                        <input type="checkbox" class="toggle-magang-row" data-id="${item.id}" ${checkedAttr} />
                        <span class="slider"></span>
                    </label>
                    ${magangBadge}
                </div>
            </td>
            <td>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <button type="button" class="btn-edit" data-id="${item.id}" style="padding: 6px 12px; font-size: 0.8rem; background: #f8fafc; border: 1px solid #cbd5e1; color: #0b3d6b; font-weight: 600; border-radius: 8px; cursor: pointer;">
                        Edit
                    </button>
                    <button type="button" class="btn-delete" data-id="${item.id}" data-nama="${escapeHtml(item.nama)}" style="padding: 6px 10px; font-size: 0.8rem; background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; font-weight: 600; border-radius: 8px; cursor: pointer;">
                        Hapus
                    </button>
                </div>
            </td>
        `;

        // Event listeners for actions in row
        const editBtn = tr.querySelector('.btn-edit');
        if (editBtn) {
            editBtn.addEventListener('click', () => openEditModal(item));
        }

        const delBtn = tr.querySelector('.btn-delete');
        if (delBtn) {
            delBtn.addEventListener('click', () => handleDelete(item.id, item.nama));
        }

        const toggleInput = tr.querySelector('.toggle-magang-row');
        if (toggleInput) {
            toggleInput.addEventListener('change', async (e) => {
                const targetState = toggleInput.checked;
                // If toggling ON and item has NO peminatan assigned, force edit modal
                if (targetState && (!item.peminatan_ids || item.peminatan_ids.length === 0)) {
                    toggleInput.checked = false; // revert toggle visually
                    await showAdminAlert('Entitas ini belum memiliki peminatan. Silakan pilih minimal 1 peminatan pada form edit yang akan terbuka.', 'warning', 'Peminatan Wajib');
                    openEditModal(item, true); // open modal with magang checked
                    return;
                }

                await handleQuickMagangToggle(item, targetState ? 1 : 0, toggleInput);
            });
        }

        return tr;
    }

    function applySearchFilter(query) {
        if (!query) {
            renderTable(entitasList);
            return;
        }

        const filtered = entitasList.filter(item => {
            const nama = (item.nama || '').toLowerCase();
            const singkatan = (item.singkatan || '').toLowerCase();
            const parent = (item.nama_parent || '').toLowerCase();
            const alamat = (item.alamat || '').toLowerCase();
            const pem = (item.peminatan_names || []).join(' ').toLowerCase();

            return nama.includes(query) || singkatan.includes(query) || parent.includes(query) || alamat.includes(query) || pem.includes(query);
        });

        renderTable(filtered);
    }

    async function openCreateModal() {
        const modal = document.getElementById('modal-entitas');
        const form = document.getElementById('form-entitas');
        const titleEl = document.getElementById('modal-entitas-title');
        if (!modal || !form) return;

        form.reset();
        document.getElementById('entitas-id').value = '';
        if (titleEl) titleEl.innerText = `Tambah ${label} Baru`;

        // Ensure peminatan checkboxes are rendered
        await fetchPeminatanIfEmpty();

        // Uncheck all peminatan
        document.querySelectorAll('.chk-pem-item').forEach(c => c.checked = false);

        // Default menerima magang state
        const chkMagang = document.getElementById('entitas-menerima-magang');
        if (chkMagang) {
            chkMagang.checked = (tipe === 'unit_pelaksana');
            updatePeminatanSectionState(chkMagang.checked);
        }

        showModal();
    }

    async function openEditModal(item, forceMagangOn = false) {
        const modal = document.getElementById('modal-entitas');
        const titleEl = document.getElementById('modal-entitas-title');
        if (!modal) return;

        // Ensure peminatan checkboxes are rendered
        await fetchPeminatanIfEmpty();

        if (titleEl) titleEl.innerText = `Edit ${label}: ${item.nama}`;
        document.getElementById('entitas-id').value = item.id;
        document.getElementById('entitas-nama').value = item.nama || '';
        document.getElementById('entitas-singkatan').value = item.singkatan || '';
        document.getElementById('entitas-alamat').value = item.alamat || '';
        document.getElementById('entitas-latitude').value = item.latitude !== null ? item.latitude : '';
        document.getElementById('entitas-longitude').value = item.longitude !== null ? item.longitude : '';

        if (hasParent) {
            const parentSelect = document.getElementById('entitas-parent-id');
            if (parentSelect) parentSelect.value = item.parent_id || '';
        }

        // Menerima magang & Peminatan
        const chkMagang = document.getElementById('entitas-menerima-magang');
        if (chkMagang) {
            chkMagang.checked = forceMagangOn ? true : Boolean(item.menerima_magang);
            updatePeminatanSectionState(chkMagang.checked);
        }

        // Check assigned peminatan
        document.querySelectorAll('.chk-pem-item').forEach(chk => {
            const pid = parseInt(chk.value, 10);
            chk.checked = Array.isArray(item.peminatan_ids) && item.peminatan_ids.includes(pid);
        });

        showModal();
    }

    function showModal() {
        const modal = document.getElementById('modal-entitas');
        if (modal) modal.classList.remove('hidden');
    }

    function hideModal() {
        const modal = document.getElementById('modal-entitas');
        if (modal) modal.classList.add('hidden');
    }

    async function handleFormSubmit(e) {
        e.preventDefault();

        const id = document.getElementById('entitas-id').value;
        const isEdit = Boolean(id);

        const nama = document.getElementById('entitas-nama').value.trim();
        const singkatan = document.getElementById('entitas-singkatan').value.trim();
        const alamat = document.getElementById('entitas-alamat').value.trim();
        const latVal = document.getElementById('entitas-latitude').value.trim();
        const lngVal = document.getElementById('entitas-longitude').value.trim();
        const menerimaMagang = document.getElementById('entitas-menerima-magang').checked ? 1 : 0;

        let parentId = null;
        if (hasParent) {
            const pVal = document.getElementById('entitas-parent-id').value;
            if (!pVal && tipe !== 'holding') {
                showAdminAlert(`Harap pilih ${parentLabel}!`, 'warning');
                return;
            }
            parentId = pVal ? parseInt(pVal, 10) : null;
        }

        // Collect selected peminatan
        const selectedPeminatan = [];
        document.querySelectorAll('.chk-pem-item:checked').forEach(c => {
            selectedPeminatan.push(parseInt(c.value, 10));
        });

        if (menerimaMagang === 1 && selectedPeminatan.length === 0) {
            showAdminAlert('Entitas yang menerima magang wajib memiliki minimal 1 peminatan!', 'warning', 'Peminatan Wajib');
            return;
        }

        const payload = {
            nama,
            singkatan: singkatan || null,
            alamat: alamat || null,
            latitude: latVal !== '' ? parseFloat(latVal) : null,
            longitude: lngVal !== '' ? parseFloat(lngVal) : null,
            aktif: 1,
            menerima_magang: menerimaMagang,
            peminatan_ids: selectedPeminatan
        };

        const submitBtn = document.getElementById('btn-submit-entitas');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerText = 'Menyimpan...';
        }

        try {
            const url = isEdit ? '/api/admin/entitas/update.php' : '/api/admin/entitas/create.php';
            if (isEdit) {
                payload.id = parseInt(id, 10);
            } else {
                payload.tipe = tipe;
                payload.parent_id = parentId;
            }

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
                showAdminAlert(data.message || `${label} berhasil disimpan.`, 'success');
                await loadData();
            } else {
                showAdminAlert(data.error || `Gagal menyimpan ${label}.`, 'error');
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

    async function handleQuickMagangToggle(item, newStatus, toggleInput) {
        try {
            const payload = {
                id: item.id,
                nama: item.nama,
                singkatan: item.singkatan,
                alamat: item.alamat,
                latitude: item.latitude,
                longitude: item.longitude,
                aktif: item.aktif ? 1 : 0,
                menerima_magang: newStatus
            };

            const res = await fetch('/api/admin/entitas/update.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (res.ok && data.ok) {
                item.menerima_magang = Boolean(newStatus);
                await loadData();
            } else {
                toggleInput.checked = !newStatus; // revert
                showAdminAlert(data.error || 'Gagal memperbarui status buka magang.', 'error');
            }
        } catch (err) {
            toggleInput.checked = !newStatus;
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        }
    }

    async function handleDelete(id, nama) {
        const confirmed = await showAdminConfirm(
            `Apakah Anda yakin ingin menonaktifkan "${nama}"? Entitas yang memiliki sub-unit atau reservasi aktif tidak dapat dinonaktifkan.`,
            'Konfirmasi Hapus Entitas',
            'danger',
            'Ya, Nonaktifkan',
            'Batal'
        );

        if (!confirmed) return;

        try {
            const res = await fetch('/api/admin/entitas/delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ id })
            });

            const data = await res.json();
            if (res.ok && data.ok) {
                showAdminAlert(data.message || 'Entitas berhasil dinonaktifkan.', 'success');
                await loadData();
            } else {
                showAdminAlert(data.error || 'Gagal menonaktifkan entitas.', 'error');
            }
        } catch (err) {
            showAdminAlert('Terjadi kesalahan jaringan.', 'error');
        }
    }
}
