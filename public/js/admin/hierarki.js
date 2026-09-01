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
    let masterPeminatan = [];
    let masterJurusan = [];
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

        // 2. Fetch Master Peminatan & Master Jurusan (use admin API with fallback)
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

        try {
            let resJur = await fetch('/api/admin/jurusan/list.php');
            if (!resJur.ok) {
                resJur = await fetch('/api/jurusan/list.php');
            }
            const dataJur = await resJur.json();
            if (dataJur.ok && Array.isArray(dataJur.data)) {
                masterJurusan = dataJur.data.filter(j => j.aktif === undefined || j.aktif == 1 || j.aktif === true || j.aktif === '1');
                renderProdiCheckboxes();
            }
        } catch (err) {
            console.error('Error fetching jurusan:', err);
        }

        // 3. Fetch Parent Candidates if hasParent
        if (hasParent && parentTipes.length > 0) {
            await loadParentCandidates();
        }

        // 4. Initial Load Table Data
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
        const colSpan = hasParent ? 7 : 6;
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #64748b;">Memuat data...</td></tr>`;
        }

        try {
            let url = `/api/admin/entitas/list.php?tipe=${tipe}&with_peminatan=1&page=${currentPage}&limit=${pageSize}`;
            if (currentSearch) {
                url += `&search=${encodeURIComponent(currentSearch)}`;
            }
            if (currentParentFilter) {
                url += `&parent_id=${encodeURIComponent(currentParentFilter)}`;
            }
            if (currentMagangFilter !== '') {
                url += `&menerima_magang=${encodeURIComponent(currentMagangFilter)}`;
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
        const colSpan = hasParent ? 7 : 6;
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

        // Prodi Tags
        let prodiHtml = '<span style="color: #94a3b8; font-size: 0.8rem;">-</span>';
        if (item.menerima_magang) {
            const totalMasterJur = masterJurusan.length;
            const selectedJurCount = (item.prodi_names && Array.isArray(item.prodi_names)) ? item.prodi_names.length : 0;

            if (selectedJurCount === 0 || (totalMasterJur > 0 && selectedJurCount >= totalMasterJur)) {
                prodiHtml = `<span class="badge-status" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 0.725rem;">Semua Prodi (Umum)</span>`;
            } else {
                prodiHtml = `<div class="peminatan-tag-list">${item.prodi_names.map(name => `<span class="peminatan-tag" style="background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; font-size: 0.725rem;">${escapeHtml(name)}</span>`).join('')}</div>`;
            }
        }

        // Peminatan Tags
        let peminatanHtml = '<span style="color: #94a3b8; font-size: 0.8rem;">-</span>';
        if (item.peminatan_names && item.peminatan_names.length > 0) {
            peminatanHtml = `<div class="peminatan-tag-list">${item.peminatan_names.map(name => `<span class="peminatan-tag" style="font-size: 0.725rem;">${escapeHtml(name)}</span>`).join('')}</div>`;
        }

        // Toggle Switch Menerima Magang
        const checkedAttr = item.menerima_magang ? 'checked' : '';
        const magangBadge = item.menerima_magang
            ? '<span class="badge-status badge-magang-ya"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Buka Magang</span>'
            : '<span class="badge-status badge-magang-tidak">Tidak Buka</span>';

        tr.innerHTML = `
            <td>
                <div style="font-weight: 700; color: #0b3d6b; font-size: 0.85rem; line-height: 1.35;">${escapeHtml(item.nama)}</div>
                ${item.singkatan ? `<div style="font-size: 0.725rem; color: #64748b; font-weight: 500; margin-top: 2px;">Singkatan: ${escapeHtml(item.singkatan)}</div>` : ''}
            </td>
            ${hasParent ? `<td style="color: #334155; font-weight: 600; font-size: 0.8125rem;">${escapeHtml(item.nama_parent || '-')}</td>` : ''}
            <td style="color: #475569; font-size: 0.8125rem; max-width: 200px;">
                <div>${escapeHtml(item.alamat || '-')}</div>
                ${item.latitude && item.longitude ? `<div style="font-family: monospace; font-size: 0.725rem; color: #94a3b8; margin-top: 2px;">${item.latitude}, ${item.longitude}</div>` : ''}
            </td>
            <td>${prodiHtml}</td>
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

    function renderProdiCheckboxes() {
        let wrapper = document.getElementById('prodi-field-wrapper');
        if (!wrapper) {
            const pemWrapper = document.getElementById('peminatan-field-wrapper');
            if (pemWrapper && pemWrapper.parentNode) {
                wrapper = document.createElement('div');
                wrapper.id = 'prodi-field-wrapper';
                wrapper.style.cssText = 'border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; transition: opacity 0.2s ease;';
                wrapper.innerHTML = `
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #334155;">Default Program Studi (Opsional)</label>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" id="btn-hierarki-all-prodi" style="font-size: 0.725rem; color: #0284c7; background: none; border: none; cursor: pointer; font-weight: 600;">Pilih Semua</button>
                            <span style="color: #cbd5e1;">|</span>
                            <button type="button" id="btn-hierarki-clear-prodi" style="font-size: 0.725rem; color: #64748b; background: none; border: none; cursor: pointer; font-weight: 600;">Kosongkan Semua</button>
                        </div>
                    </div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 8px;">Template default prodi saat unit dialokasikan kuota di periode baru. Kosongkan jika menerima semua prodi.</div>
                    <div id="prodi-checkboxes-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 6px; max-height: 140px; overflow-y: auto; padding: 4px;"></div>
                `;
                pemWrapper.parentNode.insertBefore(wrapper, pemWrapper);

                document.getElementById('btn-hierarki-all-prodi')?.addEventListener('click', () => {
                    document.querySelectorAll('.chk-prodi-item').forEach(c => c.checked = true);
                });
                document.getElementById('btn-hierarki-clear-prodi')?.addEventListener('click', () => {
                    document.querySelectorAll('.chk-prodi-item').forEach(c => c.checked = false);
                });
            }
        }

        const container = document.getElementById('prodi-checkboxes-container');
        if (!container) return;
        container.innerHTML = '';
        if (masterJurusan.length === 0) {
            container.innerHTML = '<span style="font-size: 0.8rem; color: #64748b;">Belum ada master data program studi aktif.</span>';
            return;
        }

        masterJurusan.forEach(jur => {
            const labelEl = document.createElement('label');
            labelEl.style.cssText = 'display: flex; align-items: center; gap: 8px; font-size: 0.825rem; color: #334155; cursor: pointer; padding: 4px 8px; background: #f8fafc; border-radius: 6px; border: 1px solid #cbd5e1;';
            labelEl.innerHTML = `
                <input type="checkbox" class="chk-prodi-item" value="${jur.id}" style="cursor: pointer;" />
                <span title="${escapeHtml(jur.nama_jurusan)}">${escapeHtml(jur.nama_jurusan)}</span>
            `;
            container.appendChild(labelEl);
        });
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
        const prodiWrapper = document.getElementById('prodi-field-wrapper');
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
        if (prodiWrapper) {
            prodiWrapper.style.opacity = isMenerimaMagang ? '1' : '0.5';
            prodiWrapper.style.pointerEvents = isMenerimaMagang ? 'auto' : 'none';
        }
    }

    async function loadData() {
        const tbody = document.getElementById('table-entitas-body');
        const colSpan = hasParent ? 6 : 5;
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #64748b;">Memuat data...</td></tr>`;
        }

        try {
            let url = `/api/admin/entitas/list.php?tipe=${tipe}&with_peminatan=1&page=${currentPage}&limit=${pageSize}`;
            if (currentSearch) {
                url += `&search=${encodeURIComponent(currentSearch)}`;
            }
            if (currentParentFilter) {
                url += `&parent_id=${encodeURIComponent(currentParentFilter)}`;
            }
            if (currentMagangFilter !== '') {
                url += `&menerima_magang=${encodeURIComponent(currentMagangFilter)}`;
            }

            const res = await fetch(url);
            const data = await res.json();

            if (!res.ok || !data.ok) {
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #ef4444;">${escapeHtml(data.error || 'Gagal memuat data.')}</td></tr>`;
                }
                return;
            }

            entitasList = data.data || [];
            paginationMeta = data.pagination || null;

            renderTable(entitasList);
            renderPagination(paginationMeta);

        } catch (err) {
            console.error('Error loadData:', err);
            if (tbody) {
                tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 32px; color: #ef4444;">Gagal memuat data entitas.</td></tr>`;
            }
        }
    }

    async function fetchPeminatanIfEmpty() {
        if (masterPeminatan.length === 0) {
            try {
                let resPem = await fetch('/api/admin/peminatan/list.php');
                if (!resPem.ok) resPem = await fetch('/api/peminatan/list.php');
                const dataPem = await resPem.json();
                if (dataPem.ok && Array.isArray(dataPem.data)) {
                    masterPeminatan = dataPem.data.filter(p => p.aktif === undefined || p.aktif == 1 || p.aktif === true || p.aktif === '1');
                }
            } catch (err) {}
            renderPeminatanCheckboxes();
        }
        if (masterJurusan.length === 0) {
            try {
                let resJur = await fetch('/api/admin/jurusan/list.php');
                if (!resJur.ok) resJur = await fetch('/api/jurusan/list.php');
                const dataJur = await resJur.json();
                if (dataJur.ok && Array.isArray(dataJur.data)) {
                    masterJurusan = dataJur.data.filter(j => j.aktif === undefined || j.aktif == 1 || j.aktif === true || j.aktif === '1');
                }
            } catch (err) {}
            renderProdiCheckboxes();
        }
    }

    async function openCreateModal() {
        const modal = document.getElementById('modal-entitas');
        const form = document.getElementById('form-entitas');
        const titleEl = document.getElementById('modal-entitas-title');
        if (!modal || !form) return;

        form.reset();
        document.getElementById('entitas-id').value = '';
        if (titleEl) titleEl.innerText = `Tambah ${label} Baru`;

        // Ensure checkboxes are rendered
        await fetchPeminatanIfEmpty();

        // Uncheck all peminatan & prodi
        document.querySelectorAll('.chk-pem-item').forEach(c => c.checked = false);
        document.querySelectorAll('.chk-prodi-item').forEach(c => c.checked = false);

        // Default menerima magang state
        const chkMagang = document.getElementById('entitas-menerima-magang');
        if (chkMagang) {
            chkMagang.checked = (tipe === 'unit_pelaksana' || tipe === 'unit_layanan');
            updatePeminatanSectionState(chkMagang.checked);
        }

        showModal();
    }

    async function openEditModal(item, forceMagangOn = false) {
        const modal = document.getElementById('modal-entitas');
        const titleEl = document.getElementById('modal-entitas-title');
        if (!modal) return;

        // Ensure checkboxes are rendered
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

        // Check assigned default prodi
        document.querySelectorAll('.chk-prodi-item').forEach(chk => {
            const jid = parseInt(chk.value, 10);
            chk.checked = Array.isArray(item.prodi_ids) && item.prodi_ids.includes(jid);
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

        // Collect selected default prodi
        const selectedProdi = [];
        document.querySelectorAll('.chk-prodi-item:checked').forEach(c => {
            selectedProdi.push(parseInt(c.value, 10));
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
            peminatan_ids: selectedPeminatan,
            prodi_ids: selectedProdi
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
