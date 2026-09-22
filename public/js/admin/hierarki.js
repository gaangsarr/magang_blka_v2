/**
 * public/js/admin/hierarki.js
 * Modul terpadu untuk CRUD dan manajemen hierarki entitas perusahaan PLN
 * (Holding, Subholding, Anak Perusahaan, Unit Induk, Unit Pelaksana, Unit Layanan)
 * Dilengkapi Server-Side Pagination (25 item/hal), Debounced Search, dan Toolbar Filtering.
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
    let parentOptions = [];
    let entitasList = [];

    // State Pagination & Filter Server-Side
    let currentPage = 1;
    const pageSize = 25;
    let currentSearch = '';
    let currentParentFilter = '';
    let currentMagangFilter = '';
    let paginationMeta = null;
    let searchDebounceTimer = null;

    const collapsedGroupKeys = new Set();

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

        // 2. Fetch Parent Candidates if hasParent
        if (hasParent && parentTipes.length > 0) {
            await loadParentCandidates();
        }

        // 3. Initial Load Table Data
        await loadData();

        // 5. Setup Toolbar Filter Listeners
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(() => {
                    currentSearch = searchInput.value.trim();
                    currentPage = 1;
                    loadData();
                }, 300);
            });
        }

        const filterParent = document.getElementById('filter-parent');
        if (filterParent) {
            filterParent.addEventListener('change', () => {
                currentParentFilter = filterParent.value;
                currentPage = 1;
                loadData();
            });
        }

        const filterMagang = document.getElementById('filter-magang');
        if (filterMagang) {
            filterMagang.addEventListener('change', () => {
                currentMagangFilter = filterMagang.value;
                currentPage = 1;
                loadData();
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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    async function loadParentCandidates() {
        try {
            const res = await fetch('/api/admin/entitas/list.php?aktif=1&all=1');
            const data = await res.json();
            if (data.ok && Array.isArray(data.data)) {
                parentOptions = data.data.filter(e => parentTipes.includes(e.tipe));
                populateParentSelect();
                populateParentFilterDropdown();
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

    function populateParentFilterDropdown() {
        const select = document.getElementById('filter-parent');
        if (!select) return;
        select.innerHTML = `<option value="">Semua ${parentLabel}</option>`;
        parentOptions.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.innerText = p.nama;
            select.appendChild(opt);
        });
    }

    async function loadData() {
        const tbody = document.getElementById('table-entitas-body');
        const colSpan = hasParent ? 5 : 4;
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #64748b;">Memuat data...</td></tr>`;
        }

        try {
            let url = `/api/admin/entitas/list.php?tipe=${tipe}&page=${currentPage}&limit=${pageSize}`;
            if (currentSearch) {
                url += `&search=${encodeURIComponent(currentSearch)}`;
            }
            if (currentParentFilter) {
                url += `&parent_id=${encodeURIComponent(currentParentFilter)}`;
            }
            if (currentMagangFilter !== '') {
                url += `&filter_kuota=${encodeURIComponent(currentMagangFilter)}`;
            }

            const res = await fetch(url);
            const data = await res.json();
            if (data.ok && Array.isArray(data.data)) {
                entitasList = data.data;
                paginationMeta = data.pagination || {
                    total: entitasList.length,
                    page: currentPage,
                    limit: pageSize,
                    total_pages: 1,
                    from: entitasList.length === 0 ? 0 : 1,
                    to: entitasList.length
                };
                renderTable(entitasList, currentSearch !== '');
                renderPagination(paginationMeta);
            } else {
                if (tbody) tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #ef4444;">${escapeHtml(data.error || 'Gagal memuat data.')}</td></tr>`;
                renderPagination(null);
            }
        } catch (err) {
            if (tbody) tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #ef4444;">Kesalahan jaringan saat memuat data.</td></tr>`;
            renderPagination(null);
        }
    }

    function renderTable(dataArray, isSearching = false) {
        const tbody = document.getElementById('table-entitas-body');
        const countBadge = document.getElementById('total-count-badge');
        const colSpan = hasParent ? 5 : 4;
        if (!tbody) return;

        if (countBadge && paginationMeta) {
            countBadge.innerText = `${paginationMeta.total} ${label}`;
        }

        if (dataArray.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 36px; color: #64748b;">Tidak ada data ${label} yang cocok dengan filter atau pencarian.</td></tr>`;
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
                const groupKey = parentName;
                // If searching, keep expanded so matching results are visible
                const isCollapsed = isSearching ? false : collapsedGroupKeys.has(groupKey);

                // Group Header Row
                const trGroup = document.createElement('tr');
                trGroup.className = `table-group-header ${isCollapsed ? 'collapsed' : ''}`;
                trGroup.setAttribute('role', 'button');
                trGroup.setAttribute('tabindex', '0');
                trGroup.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                trGroup.setAttribute('title', isCollapsed ? 'Klik untuk membuka daftar entitas' : 'Klik untuk menyembunyikan daftar entitas');

                trGroup.innerHTML = `
                    <td colspan="${colSpan}">
                        <div class="table-group-title">
                            <span class="group-chevron">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </span>
                            <span style="font-weight: 700;">${escapeHtml(parentName)}</span>
                            <span class="table-group-count">${items.length} ${label}</span>
                            <span class="table-group-hint">
                                <span class="hint-text">${isCollapsed ? 'Tampilkan' : 'Sembunyikan'}</span>
                            </span>
                        </div>
                    </td>
                `;

                // Group Items
                const itemRows = [];
                items.forEach(item => {
                    const row = createRow(item);
                    row.classList.add('table-group-item');
                    if (isCollapsed) {
                        row.classList.add('is-hidden');
                    }
                    itemRows.push(row);
                });

                // Toggle Collapsible Action
                const toggleGroup = () => {
                    const willCollapse = !trGroup.classList.contains('collapsed');
                    if (willCollapse) {
                        trGroup.classList.add('collapsed');
                        trGroup.setAttribute('aria-expanded', 'false');
                        trGroup.setAttribute('title', 'Klik untuk membuka daftar entitas');
                        collapsedGroupKeys.add(groupKey);
                        itemRows.forEach(r => r.classList.add('is-hidden'));
                    } else {
                        trGroup.classList.remove('collapsed');
                        trGroup.setAttribute('aria-expanded', 'true');
                        trGroup.setAttribute('title', 'Klik untuk menyembunyikan daftar entitas');
                        collapsedGroupKeys.delete(groupKey);
                        itemRows.forEach(r => r.classList.remove('is-hidden'));
                    }
                    const hintEl = trGroup.querySelector('.hint-text');
                    if (hintEl) {
                        hintEl.innerText = willCollapse ? 'Tampilkan' : 'Sembunyikan';
                    }
                };

                trGroup.addEventListener('click', toggleGroup);
                trGroup.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggleGroup();
                    }
                });

                tbody.appendChild(trGroup);
                itemRows.forEach(r => tbody.appendChild(r));
            }
        } else {
            // Flat List (Holding)
            dataArray.forEach(item => {
                tbody.appendChild(createRow(item));
            });
        }
    }

    function renderPagination(meta) {
        let container = document.getElementById('admin-pagination-container');
        if (!container) {
            const card = document.querySelector('.admin-card');
            if (card) {
                container = document.createElement('div');
                container.id = 'admin-pagination-container';
                container.className = 'admin-pagination-container';
                card.appendChild(container);
            } else {
                return;
            }
        }

        if (!meta || meta.total === 0 || meta.total_pages <= 1) {
            container.innerHTML = '';
            container.style.display = meta && meta.total > 0 ? 'flex' : 'none';
            if (meta && meta.total > 0) {
                container.innerHTML = `<div class="admin-pagination-info">Menampilkan <strong>${meta.from}</strong> - <strong>${meta.to}</strong> dari <strong>${meta.total}</strong> ${escapeHtml(label)}</div>`;
            }
            return;
        }

        container.style.display = 'flex';

        const { page, total, total_pages, from, to } = meta;

        let buttonsHtml = '';

        // Tombol Prev
        const prevDisabled = page <= 1 ? 'disabled' : '';
        buttonsHtml += `
            <button type="button" class="admin-pagination-btn btn-page-nav" data-page="${page - 1}" ${prevDisabled} title="Halaman Sebelumnya">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="icon-prev"><path d="m15 18-6-6 6-6"/></svg>
                <span>Prev</span>
            </button>
        `;

        // Generasi angka halaman (dengan ellipsis jika banyak halaman)
        const maxPills = 5;
        let startP = Math.max(1, page - 2);
        let endP = Math.min(total_pages, startP + maxPills - 1);
        if (endP - startP < maxPills - 1) {
            startP = Math.max(1, endP - maxPills + 1);
        }

        if (startP > 1) {
            buttonsHtml += `<button type="button" class="admin-pagination-page-btn btn-page-nav" data-page="1">1</button>`;
            if (startP > 2) {
                buttonsHtml += `<span class="admin-pagination-page-btn ellipsis">...</span>`;
            }
        }

        for (let p = startP; p <= endP; p++) {
            const activeClass = p === page ? 'active' : '';
            buttonsHtml += `<button type="button" class="admin-pagination-page-btn btn-page-nav ${activeClass}" data-page="${p}">${p}</button>`;
        }

        if (endP < total_pages) {
            if (endP < total_pages - 1) {
                buttonsHtml += `<span class="admin-pagination-page-btn ellipsis">...</span>`;
            }
            buttonsHtml += `<button type="button" class="admin-pagination-page-btn btn-page-nav" data-page="${total_pages}">${total_pages}</button>`;
        }

        // Tombol Next
        const nextDisabled = page >= total_pages ? 'disabled' : '';
        buttonsHtml += `
            <button type="button" class="admin-pagination-btn btn-page-nav" data-page="${page + 1}" ${nextDisabled} title="Halaman Berikutnya">
                <span>Next</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="icon-next"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        `;

        container.innerHTML = `
            <div class="admin-pagination-info">
                Menampilkan <strong>${from}</strong> - <strong>${to}</strong> dari <strong>${total}</strong> ${escapeHtml(label)}
            </div>
            <div class="admin-pagination-controls">
                ${buttonsHtml}
            </div>
        `;

        // Event listener navigasi halaman
        container.querySelectorAll('.btn-page-nav:not(:disabled)').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const targetPage = parseInt(btn.dataset.page, 10);
                if (targetPage && targetPage !== currentPage && targetPage >= 1 && targetPage <= total_pages) {
                    currentPage = targetPage;
                    loadData();
                }
            });
        });
    }

    function createRow(item) {
        const tr = document.createElement('tr');

        // Status Kuota Magang (Periode Aktif)
        let statusKuotaHtml = '';
        const isAssigned = item.upp_id !== null && item.upp_id !== undefined;
        if (isAssigned) {
            if (item.upp_aktif == 1 && item.kuota_total > 0) {
                statusKuotaHtml = `
                    <span class="badge-status badge-dibuka" style="font-size: 0.75rem; font-weight: 700;">
                        Dibuka (${item.kuota_total} slot)
                    </span>
                `;
            } else if (item.upp_aktif == 0) {
                statusKuotaHtml = `<span class="badge-status badge-ditutup" style="font-size: 0.75rem;">Disembunyikan</span>`;
            } else {
                statusKuotaHtml = `<span class="badge-status badge-draft" style="font-size: 0.75rem;">Belum Diset (0 slot)</span>`;
            }
        } else {
            statusKuotaHtml = `<span class="badge-status badge-draft" style="font-size: 0.75rem;">Belum Diset</span>`;
        }

        // Shortcut Link Atur Kuota
        const aturKuotaBtn = `
            <a href="/admin/unit.html?search=${encodeURIComponent(item.nama)}" 
               style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; font-size: 0.725rem; background: #e0f2fe; border: 1px solid #bae6fd; color: #0369a1; border-radius: 6px; font-weight: 600; text-decoration: none; transition: background 0.15s ease;"
               title="Atur alokasi kuota, prodi, dan peminatan di menu Alokasi Kuota Unit">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                <span>Atur Kuota</span>
            </a>
        `;

        tr.innerHTML = `
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.85rem; line-height: 1.35;">${escapeHtml(item.nama)}</div>
                ${item.singkatan ? `<div style="font-size: 0.725rem; color: #64748b; font-weight: 500; margin-top: 2px;">Singkatan: ${escapeHtml(item.singkatan)}</div>` : ''}
            </td>
            ${hasParent ? `<td style="color: #334155; font-weight: 600; font-size: 0.8125rem;">${escapeHtml(item.nama_parent || '-')}</td>` : ''}
            <td style="color: #475569; font-size: 0.8125rem; max-width: 240px;">
                <div>${escapeHtml(item.alamat || '-')}</div>
                ${item.latitude && item.longitude ? `<div style="font-family: monospace; font-size: 0.725rem; color: #94a3b8; margin-top: 2px;">${item.latitude}, ${item.longitude}</div>` : ''}
            </td>
            <td>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    ${statusKuotaHtml}
                    ${aturKuotaBtn}
                </div>
            </td>
            <td>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <button type="button" class="btn-edit" data-id="${item.id}" style="padding: 5px 12px; font-size: 0.75rem; background: #f8fafc; border: 1px solid #cbd5e1; color: #0b3d6b; font-weight: 600; border-radius: 6px; cursor: pointer;">
                        Edit
                    </button>
                    <button type="button" class="btn-delete" data-id="${item.id}" data-nama="${escapeHtml(item.nama)}" style="padding: 5px 10px; font-size: 0.75rem; background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; font-weight: 600; border-radius: 6px; cursor: pointer;">
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

        return tr;
    }

    function openCreateModal() {
        const modal = document.getElementById('modal-entitas');
        const form = document.getElementById('form-entitas');
        const titleEl = document.getElementById('modal-entitas-title');
        if (!modal || !form) return;

        form.reset();
        document.getElementById('entitas-id').value = '';
        if (titleEl) titleEl.innerText = `Tambah ${label} Baru`;

        showModal();
    }

    function openEditModal(item) {
        const modal = document.getElementById('modal-entitas');
        const titleEl = document.getElementById('modal-entitas-title');
        if (!modal) return;

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

        let parentId = null;
        if (hasParent) {
            const pVal = document.getElementById('entitas-parent-id').value;
            if (!pVal && tipe !== 'holding') {
                showAdminAlert(`Harap pilih ${parentLabel}!`, 'warning');
                return;
            }
            parentId = pVal ? parseInt(pVal, 10) : null;
        }

        const payload = {
            nama,
            singkatan: singkatan || null,
            alamat: alamat || null,
            latitude: latVal !== '' ? parseFloat(latVal) : null,
            longitude: lngVal !== '' ? parseFloat(lngVal) : null,
            aktif: 1,
            menerima_magang: 1
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
