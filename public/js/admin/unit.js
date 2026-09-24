/**
 * public/js/admin/unit.js
 * Manajemen alokasi kuota penerimaan magang per periode
 */

import { showAdminAlert } from './common.js';

let csrfToken = null;
let allUnitKuota = [];
let filteredUnitKuota = [];
let allJurusanList = [];
let allPeminatanList = [];
let currentPeriodData = null;
let isPeriodeSelectPopulated = false;

// State Pagination
let kuotaCurrentPage = 1;
let kuotaPerPage = 15;
let activePopoverEl = null;

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
        const urlParams = new URLSearchParams(window.location.search);
        const searchParam = urlParams.get('search');
        if (searchParam) {
            searchKuotaInput.value = searchParam;
        }
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

    // 4. Pagination Event Listeners
    const selectPerPage = document.getElementById('kuota-per-page');
    if (selectPerPage) {
        selectPerPage.addEventListener('change', (e) => {
            kuotaPerPage = parseInt(e.target.value, 10) || 15;
            kuotaCurrentPage = 1;
            renderPagedTable();
        });
    }

    document.getElementById('btn-prev-page')?.addEventListener('click', () => {
        if (kuotaCurrentPage > 1) {
            kuotaCurrentPage--;
            renderPagedTable(true);
        }
    });

    document.getElementById('btn-next-page')?.addEventListener('click', () => {
        const totalPages = Math.ceil(filteredUnitKuota.length / kuotaPerPage) || 1;
        if (kuotaCurrentPage < totalPages) {
            kuotaCurrentPage++;
            renderPagedTable(true);
        }
    });

    // Close popover when clicking outside or resizing
    document.addEventListener('click', (e) => {
        if (activePopoverEl && !activePopoverEl.contains(e.target) && !e.target.closest('.badge-prodi-more')) {
            closeProdiPopover();
        }
    });

    window.addEventListener('resize', closeProdiPopover);
    window.addEventListener('scroll', closeProdiPopover, true);

    // 5. Modal Quick Action Buttons
    document.getElementById('btn-select-all-prodi')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-prodi').forEach(cb => {
            cb.checked = true;
            toggleProdiRowState(cb);
        });
        syncPeminatanFilter();
        updateModalAllocSummary();
    });
    document.getElementById('btn-clear-prodi')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-prodi:not(:disabled)').forEach(cb => {
            cb.checked = false;
            toggleProdiRowState(cb);
        });
        syncPeminatanFilter();
        updateModalAllocSummary();
    });

    document.getElementById('btn-select-all-pem')?.addEventListener('click', () => {
        document.querySelectorAll('#container-checkbox-peminatan label:not([style*="display: none"]) .chk-modal-pem').forEach(cb => cb.checked = true);
    });
    document.getElementById('btn-clear-pem')?.addEventListener('click', () => {
        document.querySelectorAll('.chk-modal-pem').forEach(cb => cb.checked = false);
    });

    // 6. Radio Mode Kuota change listener
    document.querySelectorAll('input[name="modal_tipe_kuota"]').forEach(radio => {
        radio.addEventListener('change', onModeKuotaChange);
    });

    // 7. Total Kuota input change listener
    document.getElementById('kuota_total')?.addEventListener('input', updateModalAllocSummary);

    // 8. Form Kuota Submit
    const formKuota = document.getElementById('form-kuota');
    if (formKuota) {
        formKuota.addEventListener('submit', handleFormKuotaSubmit);
    }

    // 9. Initial Load
    await loadUnit();
});

async function loadUnit(targetPeriodeId = null, preservePage = false) {
    const tbody = document.getElementById('table-unit');
    const infoEl = document.getElementById('info-periode');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: #64748b; padding: 28px;">Memuat alokasi kuota...</td></tr>';
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

            applyKuotaFilter(!preservePage);
        } else {
            if (tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: #ef4444; padding: 24px;">Gagal memuat data alokasi kuota.</td></tr>';
        }
    } catch (err) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: #ef4444; padding: 24px;">Kesalahan jaringan saat memuat kuota.</td></tr>';
    }
}

function applyKuotaFilter(resetPage = true) {
    const searchInput = document.getElementById('search-kuota');
    const query = (searchInput ? searchInput.value : '').trim().toLowerCase();
    if (!query) {
        filteredUnitKuota = [...allUnitKuota];
    } else {
        filteredUnitKuota = allUnitKuota.filter(u => 
            (u.nama || '').toLowerCase().includes(query) ||
            (u.singkatan || '').toLowerCase().includes(query) ||
            (u.nama_parent || '').toLowerCase().includes(query) ||
            (u.tipe || '').toLowerCase().includes(query)
        );
    }

    if (resetPage) {
        kuotaCurrentPage = 1;
    }
    renderPagedTable();
}

function renderPagedTable(shouldScroll = false) {
    closeProdiPopover();

    const total = filteredUnitKuota.length;
    const totalPages = Math.ceil(total / kuotaPerPage) || 1;

    if (kuotaCurrentPage > totalPages) {
        kuotaCurrentPage = totalPages;
    }
    if (kuotaCurrentPage < 1) {
        kuotaCurrentPage = 1;
    }

    const startIdx = total === 0 ? 0 : (kuotaCurrentPage - 1) * kuotaPerPage;
    const endIdx = Math.min(startIdx + kuotaPerPage, total);
    const pageData = filteredUnitKuota.slice(startIdx, endIdx);

    renderKuotaTable(pageData);

    const infoEl = document.getElementById('pagination-info');
    const pageNumEl = document.getElementById('pagination-page-num');
    const btnPrev = document.getElementById('btn-prev-page');
    const btnNext = document.getElementById('btn-next-page');

    if (infoEl) {
        if (total === 0) {
            infoEl.innerHTML = 'Menampilkan <strong>0</strong> unit';
        } else {
            infoEl.innerHTML = `Menampilkan <strong>${startIdx + 1}–${endIdx}</strong> dari <strong>${total}</strong> unit`;
        }
    }

    if (pageNumEl) {
        pageNumEl.innerText = `Halaman ${kuotaCurrentPage} / ${totalPages}`;
    }

    if (btnPrev) {
        btnPrev.disabled = (kuotaCurrentPage <= 1);
    }
    if (btnNext) {
        btnNext.disabled = (kuotaCurrentPage >= totalPages || total === 0);
    }

    if (shouldScroll) {
        const cardHeader = document.querySelector('.admin-card-header');
        if (cardHeader) {
            cardHeader.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}

function closeProdiPopover() {
    if (activePopoverEl) {
        activePopoverEl.remove();
        activePopoverEl = null;
    }
}

function openProdiPopover(anchorEl, unitName, prodiList, isBreakdown) {
    closeProdiPopover();

    const popover = document.createElement('div');
    popover.className = 'prodi-popover-dropdown';

    const titleHtml = `
        <div class="prodi-popover-title">
            <span>Daftar Prodi Diterima (${prodiList.length})</span>
            <span style="font-size: 0.7rem; font-weight: 500; color: #64748b; max-width: 130px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(unitName)}">${escapeHtml(unitName)}</span>
        </div>
    `;

    const itemsHtml = prodiList.map(p => {
        const allocBadge = (isBreakdown && p.kuota_total !== null && p.kuota_total !== undefined)
            ? `<span style="font-size: 0.725rem; font-weight: 700; color: #4338ca; background: #eef2ff; padding: 1px 6px; border-radius: 4px; border: 1px solid #c7d2fe;">${p.kuota_total} slot</span>`
            : '';
        return `
            <div class="prodi-popover-item">
                <span style="font-weight: 600; color: #1e293b;">${escapeHtml(p.nama_jurusan)}</span>
                ${allocBadge}
            </div>
        `;
    }).join('');

    popover.innerHTML = `${titleHtml}<div class="prodi-popover-list">${itemsHtml}</div>`;
    document.body.appendChild(popover);

    const rect = anchorEl.getBoundingClientRect();
    const popoverRect = popover.getBoundingClientRect();

    let top = rect.bottom + 6;
    let left = rect.left;

    // If popover goes off bottom of screen, show above anchor
    if (top + popoverRect.height > window.innerHeight - 10) {
        top = rect.top - popoverRect.height - 6;
    }

    // If popover goes off right edge of screen
    if (left + popoverRect.width > window.innerWidth - 10) {
        left = window.innerWidth - popoverRect.width - 10;
    }

    if (left < 10) left = 10;

    popover.style.top = `${Math.max(10, top)}px`;
    popover.style.left = `${left}px`;

    activePopoverEl = popover;
}

function renderKuotaTable(dataArray) {
    const tbody = document.getElementById('table-unit');
    if (!tbody) return;

    if (!dataArray || dataArray.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; color: #64748b; padding: 32px;">Tidak ada data unit yang sesuai dengan pencarian atau filter.</td></tr>';
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

        // Mode Kuota Badge
        const isBreakdown = (u.tipe_kuota === 'breakdown');
        const modeBadge = isBreakdown
            ? '<span class="badge-status" style="background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; font-size: 0.725rem; font-weight: 700;">Terbagi per Prodi</span>'
            : '<span class="badge-status" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; font-size: 0.725rem; font-weight: 600;">Keseluruhan (Pool)</span>';

        // Render Prodi Tags (Clean & Compact)
        let prodiHtml = '';
        const totalJurusan = allJurusanList.length;
        const selectedJurCount = Array.isArray(u.prodi_details) ? u.prodi_details.length : 0;

        if (selectedJurCount === 0 || (totalJurusan > 0 && selectedJurCount >= totalJurusan && !isBreakdown)) {
            prodiHtml = '<span class="badge-status" style="background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; font-size: 0.72rem; font-weight: 600;">Semua Prodi (Umum)</span>';
        } else {
            const fullTooltip = u.prodi_details.map(p => {
                const alloc = (isBreakdown && p.kuota_total !== null && p.kuota_total !== undefined) ? ` (${p.kuota_total} slot)` : '';
                return `• ${p.nama_jurusan}${alloc}`;
            }).join('\n');

            if (selectedJurCount <= 2) {
                const tags = u.prodi_details.map(p => {
                    const allocText = (isBreakdown && p.kuota_total !== null && p.kuota_total !== undefined) 
                        ? ` <strong style="color: #4338ca;">(${p.kuota_total})</strong>` 
                        : '';
                    return `<span class="badge-prodi-item" title="${escapeHtml(p.nama_jurusan)}">${escapeHtml(p.nama_jurusan)}${allocText}</span>`;
                }).join('');
                prodiHtml = `<div class="prodi-tag-container" title="${escapeHtml(fullTooltip)}">${tags}</div>`;
            } else {
                const firstTwo = u.prodi_details.slice(0, 2).map(p => {
                    const allocText = (isBreakdown && p.kuota_total !== null && p.kuota_total !== undefined) 
                        ? ` <strong style="color: #4338ca;">(${p.kuota_total})</strong>` 
                        : '';
                    return `<span class="badge-prodi-item" title="${escapeHtml(p.nama_jurusan)}">${escapeHtml(p.nama_jurusan)}${allocText}</span>`;
                }).join('');
                const remaining = selectedJurCount - 2;
                const moreBtn = `<button type="button" class="badge-prodi-more btn-show-all-prodi" title="${escapeHtml(fullTooltip)}">+${remaining} lainnya</button>`;
                prodiHtml = `<div class="prodi-tag-container">${firstTwo}${moreBtn}</div>`;
            }
        }

        // Render Peminatan Tags with hover list
        let peminatanHtml = '';
        if (Array.isArray(u.peminatan_details) && u.peminatan_details.length > 0) {
            const pemTooltip = u.peminatan_details.map(p => `• ${p.nama}`).join('\n');
            peminatanHtml = `<span class="badge-status" style="background: #fefce8; color: #a16207; border: 1px solid #fef08a; font-size: 0.725rem; font-weight: 700; cursor: default;" title="${escapeHtml(pemTooltip)}">${u.peminatan_details.length} Peminatan</span>`;
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
            <td>${modeBadge}</td>
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

        const moreBtn = tr.querySelector('.btn-show-all-prodi');
        if (moreBtn) {
            moreBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                openProdiPopover(e.currentTarget, u.nama, u.prodi_details, isBreakdown);
            });
        }

        tbody.appendChild(tr);
    });
}

function onModeKuotaChange() {
    const selectedMode = document.querySelector('input[name="modal_tipe_kuota"]:checked')?.value || 'keseluruhan';
    const isBreakdown = (selectedMode === 'breakdown');

    const lblKeseluruhan = document.getElementById('label-mode-keseluruhan');
    const lblBreakdown = document.getElementById('label-mode-breakdown');

    if (lblKeseluruhan && lblBreakdown) {
        if (isBreakdown) {
            lblBreakdown.style.borderColor = '#4338ca';
            lblBreakdown.style.background = '#eef2ff';
            lblBreakdown.querySelector('strong').style.color = '#3730a3';

            lblKeseluruhan.style.borderColor = '#cbd5e1';
            lblKeseluruhan.style.background = '#fff';
            lblKeseluruhan.querySelector('strong').style.color = '#334155';
        } else {
            lblKeseluruhan.style.borderColor = '#0284c7';
            lblKeseluruhan.style.background = '#f0f9ff';
            lblKeseluruhan.querySelector('strong').style.color = '#0369a1';

            lblBreakdown.style.borderColor = '#cbd5e1';
            lblBreakdown.style.background = '#fff';
            lblBreakdown.querySelector('strong').style.color = '#334155';
        }
    }

    // Toggle allocation input containers
    document.querySelectorAll('.prodi-row-item').forEach(row => {
        const cb = row.querySelector('.chk-modal-prodi');
        const allocBox = row.querySelector('.prodi-alloc-box');
        if (allocBox) {
            allocBox.style.display = (isBreakdown && cb && cb.checked) ? 'flex' : 'none';
        }
    });

    updateModalAllocSummary();
}

function toggleProdiRowState(checkboxEl) {
    const row = checkboxEl.closest('.prodi-row-item');
    if (!row) return;

    const isBreakdown = document.querySelector('input[name="modal_tipe_kuota"]:checked')?.value === 'breakdown';
    const allocBox = row.querySelector('.prodi-alloc-box');
    const allocInput = row.querySelector('.input-modal-prodi-alloc');

    if (checkboxEl.checked) {
        row.style.background = '#ffffff';
        row.style.borderColor = '#93c5fd';
        if (isBreakdown && allocBox) {
            allocBox.style.display = 'flex';
            if (allocInput && (!allocInput.value || parseInt(allocInput.value, 10) <= 0)) {
                allocInput.value = '1';
            }
        }
    } else {
        row.style.background = '#f8fafc';
        row.style.borderColor = '#e2e8f0';
        if (allocBox) {
            allocBox.style.display = 'none';
        }
    }
}

function updateModalAllocSummary() {
    const isBreakdown = document.querySelector('input[name="modal_tipe_kuota"]:checked')?.value === 'breakdown';
    const summaryEl = document.getElementById('modal-alloc-summary');
    if (!summaryEl) return;

    if (!isBreakdown) {
        summaryEl.classList.add('hidden');
        return;
    }

    summaryEl.classList.remove('hidden');

    const totalKuota = parseInt(document.getElementById('kuota_total')?.value, 10) || 0;
    let allocated = 0;
    let countChecked = 0;

    document.querySelectorAll('.chk-modal-prodi:checked').forEach(cb => {
        countChecked++;
        const row = cb.closest('.prodi-row-item');
        const inp = row?.querySelector('.input-modal-prodi-alloc');
        allocated += parseInt(inp?.value, 10) || 0;
    });

    const diff = totalKuota - allocated;

    if (countChecked === 0) {
        summaryEl.style.background = '#fef2f2';
        summaryEl.style.color = '#991b1b';
        summaryEl.style.border = '1px solid #fecaca';
        summaryEl.innerHTML = `⚠️ Pilih minimal 1 program studi untuk membagikan alokasi kuota.`;
    } else if (diff === 0 && totalKuota > 0) {
        summaryEl.style.background = '#f0fdf4';
        summaryEl.style.color = '#166534';
        summaryEl.style.border = '1px solid #bbf7d0';
        summaryEl.innerHTML = `✓ Total teralokasi: <strong>${allocated}</strong> dari <strong>${totalKuota}</strong> mahasiswa (Pas).`;
    } else if (diff > 0) {
        summaryEl.style.background = '#fffbeb';
        summaryEl.style.color = '#92400e';
        summaryEl.style.border = '1px solid #fde68a';
        summaryEl.innerHTML = `ℹ️ Teralokasi: <strong>${allocated}</strong> / ${totalKuota}. Masih ada sisa <strong>${diff}</strong> kuota yang belum dialokasikan.`;
    } else {
        summaryEl.style.background = '#fef2f2';
        summaryEl.style.color = '#991b1b';
        summaryEl.style.border = '1px solid #fecaca';
        summaryEl.innerHTML = `⚠️ Kelebihan alokasi: <strong>${allocated}</strong> / ${totalKuota} (Kelebihan ${Math.abs(diff)} mahasiswa!).`;
    }
}

function syncPeminatanFilter() {
    const checkedProdiIds = Array.from(document.querySelectorAll('.chk-modal-prodi:checked')).map(cb => parseInt(cb.value, 10));
    const container = document.getElementById('container-checkbox-peminatan');
    if (!container) return;

    let visibleCount = 0;
    container.querySelectorAll('.peminatan-item-wrapper').forEach(wrapper => {
        const pJurusanIdsStr = wrapper.getAttribute('data-jurusan-ids') || '';
        const pJurusanIds = pJurusanIdsStr ? pJurusanIdsStr.split(',').map(n => parseInt(n, 10)) : [];

        // If no prodi is checked at all: show all peminatan
        // If prodi(s) checked: show only if peminatan intersects with checked prodis
        const isMatch = (checkedProdiIds.length === 0) || pJurusanIds.some(jid => checkedProdiIds.includes(jid));

        if (isMatch) {
            wrapper.style.display = 'flex';
            visibleCount++;
        } else {
            wrapper.style.display = 'none';
            // Uncheck hidden peminatan to avoid accidental submission
            const cb = wrapper.querySelector('.chk-modal-pem');
            if (cb) cb.checked = false;
        }
    });

    const emptyNotice = document.getElementById('peminatan-empty-notice');
    if (emptyNotice) {
        emptyNotice.style.display = (visibleCount === 0) ? 'block' : 'none';
    }
}

function openKuotaModal(unit) {
    document.getElementById('entitas-id').value = unit.entitas_id;
    document.getElementById('kuota-unit-nama').innerText = unit.nama;
    document.getElementById('kuota_total').value = unit.kuota_total || 0;
    document.getElementById('aktif').value = (unit.upp_id ? (unit.aktif || 1) : 1);

    const tipeKuota = unit.tipe_kuota || 'keseluruhan';
    const radioMode = document.querySelector(`input[name="modal_tipe_kuota"][value="${tipeKuota}"]`);
    if (radioMode) {
        radioMode.checked = true;
    }
    onModeKuotaChange();

    const isDibuka = (currentPeriodData?.status === 'dibuka');
    const existingTotalKuota = parseInt(unit.kuota_total, 10) || 0;
    const hasExistingKuota = Boolean(unit.upp_id && existingTotalKuota > 0);

    const radioKeseluruhan = document.querySelector('input[name="modal_tipe_kuota"][value="keseluruhan"]');
    const radioBreakdown = document.querySelector('input[name="modal_tipe_kuota"][value="breakdown"]');
    const inputTotal = document.getElementById('kuota_total');
    const selectAktif = document.getElementById('aktif');

    let lockBadge = document.getElementById('modal-kuota-lock-badge');
    if (!lockBadge) {
        lockBadge = document.createElement('div');
        lockBadge.id = 'modal-kuota-lock-badge';
        const formKuota = document.getElementById('form-kuota');
        if (formKuota) formKuota.prepend(lockBadge);
    }

    if (isDibuka && hasExistingKuota) {
        lockBadge.style.cssText = 'display: block; padding: 10px 14px; margin-bottom: 14px; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; border-radius: 8px; font-size: 0.825rem; font-weight: 600; line-height: 1.4;';
        lockBadge.innerHTML = '🔒 <strong>Periode DIBUKA:</strong> Kuota unit hanya dapat ditambah (minimal ' + existingTotalKuota + ' mahasiswa). Metode alokasi terkunci dan unit aktif tidak dapat dinonaktifkan.';

        if (radioKeseluruhan) radioKeseluruhan.disabled = true;
        if (radioBreakdown) radioBreakdown.disabled = true;
        if (inputTotal) {
            inputTotal.min = existingTotalKuota;
            inputTotal.title = `Minimal ${existingTotalKuota} saat periode DIBUKA`;
        }
        if (selectAktif) {
            const optNonaktif = selectAktif.querySelector('option[value="0"]');
            if (optNonaktif) optNonaktif.disabled = true;
        }
    } else {
        if (lockBadge) lockBadge.style.display = 'none';
        if (radioKeseluruhan) radioKeseluruhan.disabled = false;
        if (radioBreakdown) radioBreakdown.disabled = false;
        if (inputTotal) {
            inputTotal.min = 0;
            inputTotal.title = '';
        }
        if (selectAktif) {
            const optNonaktif = selectAktif.querySelector('option[value="0"]');
            if (optNonaktif) optNonaktif.disabled = false;
        }
    }

    // Map existing prodi allocations
    const existingAllocMap = {};
    if (Array.isArray(unit.prodi_details)) {
        unit.prodi_details.forEach(p => {
            if (p.kuota_total !== null && p.kuota_total !== undefined) {
                existingAllocMap[parseInt(p.id, 10)] = parseInt(p.kuota_total, 10);
            }
        });
    }

    // Render Checkboxes Prodi
    const prodiContainer = document.getElementById('container-checkbox-prodi');
    if (prodiContainer) {
        prodiContainer.innerHTML = '';
        const assignedProdiIds = Array.isArray(unit.prodi_ids) ? unit.prodi_ids.map(n => parseInt(n, 10)) : [];
        const isBreakdown = (tipeKuota === 'breakdown');

        allJurusanList.forEach(j => {
            const jId = parseInt(j.id, 10);
            const isChecked = assignedProdiIds.includes(jId);
            const initialAlloc = existingAllocMap[jId] || (isChecked ? 1 : '');
            const isAllocatedLocked = isDibuka && hasExistingKuota && isChecked && (existingAllocMap[jId] > 0);

            const row = document.createElement('div');
            row.className = 'prodi-row-item';
            row.style.cssText = `display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; background: ${isChecked ? '#ffffff' : '#f8fafc'}; border: 1px solid ${isChecked ? '#93c5fd' : '#e2e8f0'}; border-radius: 8px; transition: all 0.2s;`;

            row.innerHTML = `
                <label style="display: flex; align-items: center; gap: 10px; font-size: 0.825rem; color: #1e293b; cursor: ${isAllocatedLocked ? 'default' : 'pointer'}; user-select: none; flex: 1; margin: 0;">
                    <input type="checkbox" value="${jId}" class="chk-modal-prodi" ${isChecked ? 'checked' : ''} ${isAllocatedLocked ? 'disabled title="Prodi sudah dialokasikan kuota pada periode DIBUKA dan tidak dapat dinonaktifkan"' : ''} style="cursor: ${isAllocatedLocked ? 'not-allowed' : 'pointer'}; width: 16px; height: 16px;">
                    <div>
                        <span style="font-weight: 600;">${escapeHtml(j.nama_jurusan)}</span>
                        <span style="font-size: 0.725rem; color: #64748b; margin-left: 4px;">(${escapeHtml(j.jenjang || 'S1')})</span>
                    </div>
                </label>
                <div class="prodi-alloc-box" style="display: ${(isBreakdown && isChecked) ? 'flex' : 'none'}; align-items: center; gap: 6px;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: #4338ca;">Jatah:</span>
                    <input type="number" class="input-modal-prodi-alloc" data-jid="${jId}" min="${isAllocatedLocked ? existingAllocMap[jId] : 1}" value="${initialAlloc}" placeholder="0" style="width: 70px; padding: 4px 8px; border: 1.5px solid #c7d2fe; border-radius: 6px; font-size: 0.85rem; font-weight: 700; text-align: center; color: #1e1b4b;" ${isAllocatedLocked ? `title="Minimal ${existingAllocMap[jId]} saat periode DIBUKA"` : ''}>
                    <span style="font-size: 0.75rem; color: #64748b;">Mhs</span>
                </div>
            `;

            const chk = row.querySelector('.chk-modal-prodi');
            chk.addEventListener('change', () => {
                toggleProdiRowState(chk);
                syncPeminatanFilter();
                updateModalAllocSummary();
            });

            const allocInp = row.querySelector('.input-modal-prodi-alloc');
            allocInp?.addEventListener('input', updateModalAllocSummary);

            prodiContainer.appendChild(row);
        });
    }

    // Render Checkboxes Peminatan with prodi tags
    const peminatanContainer = document.getElementById('container-checkbox-peminatan');
    if (peminatanContainer) {
        peminatanContainer.innerHTML = '';
        const assignedPemIds = Array.isArray(unit.peminatan_ids) ? unit.peminatan_ids.map(n => parseInt(n, 10)) : [];

        // Build a lookup map of jurusan kode/nama
        const jurusanMap = {};
        allJurusanList.forEach(j => {
            jurusanMap[parseInt(j.id, 10)] = j.kode || j.nama_jurusan;
        });

        allPeminatanList.forEach(p => {
            const pId = parseInt(p.id, 10);
            const isChecked = assignedPemIds.includes(pId);
            const jIds = Array.isArray(p.jurusan_ids) ? p.jurusan_ids : [];

            // Prodi pills for this peminatan
            const prodiPills = jIds.map(jid => {
                const label = jurusanMap[jid] || `ID ${jid}`;
                return `<span style="font-size: 0.675rem; background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 4px; font-weight: 600;">${escapeHtml(label)}</span>`;
            }).join(' ');

            const wrapper = document.createElement('div');
            wrapper.className = 'peminatan-item-wrapper';
            wrapper.setAttribute('data-jurusan-ids', jIds.join(','));
            wrapper.style.cssText = 'display: flex; align-items: flex-start; gap: 8px; font-size: 0.825rem; color: #334155; padding: 6px 8px; border-radius: 6px; background: #ffffff; border: 1px solid #e2e8f0;';

            wrapper.innerHTML = `
                <input type="checkbox" value="${pId}" class="chk-modal-pem" ${isChecked ? 'checked' : ''} style="cursor: pointer; margin-top: 3px;">
                <div style="flex: 1;">
                    <div style="font-weight: 600; color: #1e293b; font-size: 0.825rem;">${escapeHtml(p.nama)}</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px;">
                        ${prodiPills || '<span style="font-size: 0.675rem; color: #94a3b8;">(Semua Prodi)</span>'}
                    </div>
                </div>
            `;

            peminatanContainer.appendChild(wrapper);
        });

        // Add notice element if all peminatan are hidden
        const noticeEl = document.createElement('div');
        noticeEl.id = 'peminatan-empty-notice';
        noticeEl.style.cssText = 'display: none; grid-column: 1 / -1; padding: 14px; text-align: center; color: #94a3b8; font-size: 0.8rem; font-style: italic;';
        noticeEl.innerText = 'Tidak ada bidang peminatan yang cocok dengan prodi terpilih.';
        peminatanContainer.appendChild(noticeEl);
    }

    syncPeminatanFilter();
    updateModalAllocSummary();

    document.getElementById('modal-kuota').classList.remove('hidden');
}

async function handleFormKuotaSubmit(e) {
    e.preventDefault();

    const entitasId = parseInt(document.getElementById('entitas-id').value, 10);
    const kuotaTotal = parseInt(document.getElementById('kuota_total').value, 10);
    const aktif = parseInt(document.getElementById('aktif').value, 10);
    const tipeKuota = document.querySelector('input[name="modal_tipe_kuota"]:checked')?.value || 'keseluruhan';

    const periodeSelect = document.getElementById('select-periode-kuota');
    const periodeId = periodeSelect ? parseInt(periodeSelect.value, 10) : (currentPeriodData ? currentPeriodData.id : null);

    if (!periodeId) {
        showAdminAlert('Tidak ada periode aktif yang dipilih!', 'error');
        return;
    }

    // Kumpulkan prodi_ids terpilih
    const prodiIds = Array.from(document.querySelectorAll('.chk-modal-prodi:checked')).map(cb => parseInt(cb.value, 10));

    // Kumpulkan alokasi breakdown jika mode breakdown
    const prodiAllocations = {};
    if (tipeKuota === 'breakdown') {
        if (prodiIds.length === 0) {
            showAdminAlert('Pada mode breakdown, wajib memilih minimal 1 Program Studi.', 'error');
            return;
        }

        let sumAlloc = 0;
        for (const jid of prodiIds) {
            const inp = document.querySelector(`.input-modal-prodi-alloc[data-jid="${jid}"]`);
            const val = parseInt(inp?.value, 10) || 0;
            if (val <= 0) {
                showAdminAlert('Setiap program studi yang dipilih wajib memiliki jatah minimal 1 mahasiswa.', 'error');
                inp?.focus();
                return;
            }
            prodiAllocations[jid] = val;
            sumAlloc += val;
        }

        if (sumAlloc !== kuotaTotal) {
            showAdminAlert(`Jumlah alokasi per prodi (${sumAlloc}) harus sama persis dengan Total Kuota (${kuotaTotal}).`, 'error');
            return;
        }
    }

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
                tipe_kuota: tipeKuota,
                aktif: aktif,
                prodi_ids: prodiIds,
                prodi_allocations: prodiAllocations,
                peminatan_ids: peminatanIds
            })
        });

        const data = await res.json();
        if (res.ok && data.ok) {
            document.getElementById('modal-kuota').classList.add('hidden');
            showAdminAlert('Alokasi kuota unit berhasil disimpan!', 'success');
            await loadUnit(periodeId, true);
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
