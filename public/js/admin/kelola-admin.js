import { showAdminAlert, showAdminConfirm } from './common.js';

let csrfToken = null;
let rawPerusahaanData = [];
let rawKampusData = [];
let rawProdiData = [];
let currentAdmin = null;

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const statusRes = await fetch('/api/admin/status.php');
        const statusData = await statusRes.json();
        if (statusData.csrf_token) csrfToken = statusData.csrf_token;
        if (!statusRes.ok || !statusData.authenticated) {
            window.location.href = '/admin/login.html';
            return;
        }
        currentAdmin = statusData.admin;
    } catch (_) {}

    initTabs();
    initPerusahaanModule();
    initKampusModule();
    initProdiModule();
    initModalCred();
    initModalResetPic();

    loadPerusahaanData();
    loadKampusData();
    loadProdiData();
});

// ==========================================
// TABS HANDLING
// ==========================================
function initTabs() {
    const tabBtns = document.querySelectorAll('.kelola-tab-btn');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));

            btn.classList.add('active');
            const targetId = btn.getAttribute('data-target');
            const targetPanel = document.getElementById(targetId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        });
    });
}

// ==========================================
// MODUL ADMIN MITRA PERUSAHAAN
// ==========================================
function initPerusahaanModule() {
    // Search & Filter
    const searchInput = document.getElementById('filter-search-perusahaan');
    const statusSelect = document.getElementById('filter-status-perusahaan');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            filterAndRenderPerusahaan();
            updateExportLinks();
        });
    }
    if (statusSelect) {
        statusSelect.addEventListener('change', () => {
            filterAndRenderPerusahaan();
            updateExportLinks();
        });
    }

    updateExportLinks();

    // Generate All Button with Real-Time Progress Modal
    const btnGenAll = document.getElementById('btn-generate-all-perusahaan');
    if (btnGenAll) {
        btnGenAll.addEventListener('click', () => handleGenerateAllWithProgress());
    }
}

function updateExportLinks() {
    const search = encodeURIComponent((document.getElementById('filter-search-perusahaan')?.value || '').trim());
    const status = encodeURIComponent(document.getElementById('filter-status-perusahaan')?.value || '');

    let queryParams = [];
    if (search) queryParams.push(`search=${search}`);
    if (status) queryParams.push(`status=${status}`);
    const queryStr = queryParams.length > 0 ? '&' + queryParams.join('&') : '';

    const pdfBtn = document.getElementById('btn-export-pdf-perusahaan');
    const excelBtn = document.getElementById('btn-export-excel-perusahaan');

    if (pdfBtn) {
        pdfBtn.href = `/api/admin/kelola/perusahaan_export.php?format=print${queryStr}`;
    }
    if (excelBtn) {
        excelBtn.href = `/api/admin/kelola/perusahaan_export.php?format=excel${queryStr}`;
    }
}

async function handleGenerateAllWithProgress() {
    try {
        // 1. Cek jumlah entitas yang belum punya akun
        const countRes = await fetch('/api/admin/kelola/perusahaan_accounts.php?action=count_unassigned');
        const countData = await countRes.json();
        const totalUnassigned = countData.total_unassigned || 0;

        if (totalUnassigned === 0) {
            await showAdminAlert('Semua entitas unit mitra aktif sudah memiliki akun admin perusahaan.', 'info', 'Sudah Lengkap');
            return;
        }

        const isConfirmed = await showAdminConfirm(
            `Ditemukan ${totalUnassigned.toLocaleString('id-ID')} unit kantor yang belum memiliki akun. Sistem akan membuatkan akun secara otomatis dengan formula password terstandar (PLN-{KODE}-2026). Lanjutkan?`,
            'Generate Akun Otomatis',
            'primary',
            `Ya, Buatkan ${totalUnassigned} Akun`,
            'Batal'
        );
        if (!isConfirmed) return;

        // 2. Tampilkan Modal Progress
        showCredentialModal(
            '⚡ Sedang Mengenerate Akun...',
            `
            <div style="padding: 6px 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 0.875rem; font-weight: 700; color: #004687;" id="gen-progress-status">Menyiapkan pembuatan akun...</span>
                    <span style="font-size: 1rem; font-weight: 800; color: #059669;" id="gen-progress-percent">0%</span>
                </div>
                
                <div style="width: 100%; height: 14px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-bottom: 16px; box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);">
                    <div id="gen-progress-bar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #004687, #0284c7, #10b981); border-radius: 999px; transition: width 0.3s ease;"></div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; font-size: 0.85rem; color: #475569;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span>Total Target Akun:</span>
                        <strong style="color: #1e293b;">${totalUnassigned.toLocaleString('id-ID')} Unit</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span>Akun Selesai Dibuat:</span>
                        <strong style="color: #059669;" id="gen-completed-count">0 Unit</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Sedang Memproses:</span>
                        <strong style="color: #004687; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" id="gen-last-unit">Memulai...</strong>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 0.8rem; color: #64748b;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.5" style="animation: spin 1s linear infinite; flex-shrink: 0;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                    <span>Sistem sedang mengenkripsi password aman untuk seluruh unit kantor...</span>
                </div>
            </div>
            `
        );

        // 3. Loop Batch Requests
        let completedCount = 0;
        const batchSize = 75;

        while (true) {
            const batchRes = await fetch('/api/admin/kelola/perusahaan_accounts.php?action=generate_batch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ limit: batchSize })
            });
            const batchData = await batchRes.json();

            if (!batchData.ok) {
                await showAdminAlert(batchData.error || 'Terjadi kesalahan saat memproses batch akun.', 'error');
                break;
            }

            const batchCreated = batchData.batch_count || 0;
            completedCount += batchCreated;
            const remaining = batchData.remaining_count ?? 0;

            const percent = totalUnassigned > 0 ? Math.min(100, Math.round((completedCount / totalUnassigned) * 100)) : 100;

            // Update DOM Progress
            const barEl = document.getElementById('gen-progress-bar');
            const pctEl = document.getElementById('gen-progress-percent');
            const countEl = document.getElementById('gen-completed-count');
            const unitEl = document.getElementById('gen-last-unit');
            const statusEl = document.getElementById('gen-progress-status');

            if (barEl) barEl.style.width = `${percent}%`;
            if (pctEl) pctEl.innerText = `${percent}%`;
            if (countEl) countEl.innerText = `${completedCount.toLocaleString('id-ID')} Unit`;
            if (unitEl) unitEl.innerText = batchData.last_unit_nama || 'Memproses batch...';
            if (statusEl) statusEl.innerText = `Membuat akun (${completedCount} / ${totalUnassigned})...`;

            if (remaining === 0 || batchCreated === 0) {
                // Selesai!
                if (barEl) barEl.style.width = '100%';
                if (pctEl) pctEl.innerText = '100%';
                
                await new Promise(r => setTimeout(r, 400));

                showCredentialModal(
                    'Akun Berhasil Dibuat',
                    `
                    <div style="text-align: center; padding: 12px 0;">
                        <div style="width: 54px; height: 54px; background: #dcfce7; color: #166534; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; border: 2px solid #bbf7d0;">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: 6px;">Pembuatan Akun Selesai</h3>
                        <p style="font-size: 0.875rem; color: #475569; line-height: 1.5; margin-bottom: 20px;">
                            Total <strong>${completedCount.toLocaleString('id-ID')}</strong> akun mitra perusahaan telah berhasil dibuat dengan format password awal aman <strong>(PLN-{KODE}-2026)</strong>.
                        </p>
                        <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                            <button type="button" class="btn-portal-primary" id="btn-close-gen-finish" style="padding: 9px 20px;">
                                Lihat Data di Tabel
                            </button>
                            <a href="/api/admin/kelola/perusahaan_export.php?format=print" target="_blank" class="btn-action-sm btn-action-primary" style="padding: 9px 20px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                                <span>Cetak Dokumen PDF</span>
                            </a>
                        </div>
                    </div>
                    `
                );

                document.getElementById('btn-close-gen-finish')?.addEventListener('click', () => {
                    const modal = document.getElementById('modal-credential');
                    if (modal) modal.classList.add('hidden');
                });

                loadPerusahaanData();
                break;
            }
        }
    } catch (err) {
        console.error('Error in handleGenerateAllWithProgress:', err);
        await showAdminAlert('Terjadi kesalahan jaringan saat generate akun.', 'error');
    }
}

async function loadPerusahaanData() {
    try {
        const res = await fetch('/api/admin/kelola/perusahaan_accounts.php?action=list');
        const data = await res.json();

        if (data.ok) {
            rawPerusahaanData = data.data || [];
            
            // Update stats
            if (data.stats) {
                document.getElementById('stat-total-unit').innerText = data.stats.total_entities;
                document.getElementById('stat-has-account').innerText = data.stats.total_has_account;
                document.getElementById('stat-need-reset').innerText = data.stats.total_need_reset;
                document.getElementById('stat-no-account').innerText = data.stats.total_no_account;
                document.getElementById('count-perusahaan').innerText = data.stats.total_entities;
            }

            filterAndRenderPerusahaan();
        } else {
            console.error('Failed to load perusahaan accounts:', data.error);
        }
    } catch (err) {
        console.error('Error loadPerusahaanData:', err);
    }
}

let currentPagePerusahaan = 1;
const pageSizePerusahaan = 25;
let filteredPerusahaanData = [];

let currentPageKampus = 1;
const pageSizeKampus = 25;
let filteredKampusData = [];

function filterAndRenderPerusahaan() {
    const search = (document.getElementById('filter-search-perusahaan')?.value || '').toLowerCase().trim();
    const statusFilter = document.getElementById('filter-status-perusahaan')?.value || '';

    filteredPerusahaanData = rawPerusahaanData.filter(item => {
        // Search filter
        if (search !== '') {
            const matchName = (item.entitas_nama || '').toLowerCase().includes(search);
            const matchSingkatan = (item.singkatan || '').toLowerCase().includes(search);
            const matchUsername = (item.username || '').toLowerCase().includes(search);
            const matchPic = (item.pic_nama || '').toLowerCase().includes(search);
            if (!matchName && !matchSingkatan && !matchUsername && !matchPic) return false;
        }

        // Status filter
        if (statusFilter === 'has_account' && !item.has_account) return false;
        if (statusFilter === 'no_account' && item.has_account) return false;
        if (statusFilter === 'first_login' && (!item.has_account || !item.force_password_change)) return false;

        return true;
    });

    currentPagePerusahaan = 1;
    renderPerusahaanTablePage(1);
}

function renderPerusahaanTablePage(page = 1) {
    currentPagePerusahaan = page;
    const tbody = document.getElementById('table-perusahaan');
    if (!tbody) return;
    tbody.innerHTML = '';

    const totalItems = filteredPerusahaanData.length;

    if (totalItems === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #64748b; padding: 24px;">Tidak ada data entitas perusahaan yang cocok dengan filter.</td></tr>';
        renderPaginationNav('pagination-nav-perusahaan', 'pagination-info-perusahaan', 0, 1, pageSizePerusahaan, renderPerusahaanTablePage, 'entitas');
        return;
    }

    const totalPages = Math.ceil(totalItems / pageSizePerusahaan) || 1;
    const safePage = Math.min(Math.max(1, page), totalPages);
    currentPagePerusahaan = safePage;

    const startIdx = (safePage - 1) * pageSizePerusahaan;
    const pageItems = filteredPerusahaanData.slice(startIdx, startIdx + pageSizePerusahaan);

    pageItems.forEach((item, idx) => {
        const rowNumber = startIdx + idx + 1;
        let statusBadge = '';
        if (!item.has_account) {
            statusBadge = '<span class="badge-status" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; font-weight: 600;">Belum Ada Akun</span>';
        } else if (item.force_password_change) {
            statusBadge = '<span class="badge-status" style="background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; font-weight: 600;">Perlu First-Login</span>';
        } else {
            statusBadge = '<span class="badge-status" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; font-weight: 600;">Aktif & Siap</span>';
        }

        let picDisplay = '-';
        if (item.pic_nama) {
            let waLink = '';
            let cleanPhone = (item.pic_kontak || '').replace(/[^0-9]/g, '');
            if (cleanPhone.startsWith('0')) cleanPhone = '62' + cleanPhone.substring(1);
            if (cleanPhone.length >= 9) {
                waLink = ` • <a href="https://wa.me/${cleanPhone}" target="_blank" rel="noopener noreferrer" style="display: inline-flex; align-items: center; gap: 4px; color: #059669; text-decoration: none; font-weight: 600; vertical-align: middle;" title="Hubungi via WhatsApp"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg><span>WhatsApp</span></a>`;
            }
            picDisplay = `
                <div style="font-weight: 600; color: #1e293b;">${escapeHtml(item.pic_nama)}</div>
                <div style="font-size: 0.775rem; color: #64748b;">${escapeHtml(item.pic_jabatan || 'PIC')} • ${escapeHtml(item.pic_kontak || '-')}${waLink}</div>
            `;
        } else {
            picDisplay = '<span style="color: #94a3b8; font-style: italic; font-size: 0.8rem;">Belum dilengkapi (Menunggu First-Login)</span>';
        }

        const isSuperAdmin = Boolean(currentAdmin && (currentAdmin.role === 'super_admin' || currentAdmin.role === 'superadmin' || currentAdmin.is_super));

        let actionHtml = '';
        if (item.has_account) {
            actionHtml = `
                <button class="btn-action-sm btn-action-warning btn-reset-password" data-id="${item.entitas_id}" data-nama="${escapeHtml(item.entitas_nama)}" title="Reset password sementara untuk unit ini">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span>Reset Sandi</span>
                </button>
            `;
        } else {
            actionHtml = `
                <button class="btn-action-sm btn-action-primary btn-create-single" data-id="${item.entitas_id}" data-nama="${escapeHtml(item.entitas_nama)}" title="Buat akun untuk unit ini">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Buat Akun</span>
                </button>
            `;
        }

        let resetPicHtml = '';
        if (isSuperAdmin && item.pic_nama && item.pic_nama.trim() !== '') {
            resetPicHtml = `
                <button class="btn-action-sm btn-action-danger btn-reset-pic" data-id="${item.entitas_id}" title="Reset data kontak PIC untuk unit ini">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    <span>Reset PIC</span>
                </button>
            `;
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="text-align: center; color: #64748b;">${rowNumber}</td>
            <td>
                <div style="font-weight: 700; color: #004687;">${escapeHtml(item.entitas_nama)}</div>
                ${item.singkatan ? `<span style="font-size: 0.775rem; color: #64748b;">(${escapeHtml(item.singkatan)})</span>` : ''}
                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;">${escapeHtml(item.alamat || '-')}</div>
            </td>
            <td><span style="font-size: 0.8rem; font-weight: 600; color: #475569;">${escapeHtml(item.tipe ? item.tipe.toUpperCase().replace('_', ' ') : '-')}</span></td>
            <td style="font-size: 0.85rem; color: #334155;">${escapeHtml(item.parent_nama || 'Holding / Pusat')}</td>
            <td style="font-family: monospace; font-weight: bold; color: #004687; font-size: 0.9rem;">
                ${item.username ? escapeHtml(item.username) : '<span style="color:#cbd5e1;">-</span>'}
            </td>
            <td style="text-align: center;">${statusBadge}</td>
            <td>${picDisplay}</td>
            <td style="text-align: center;">
                <div style="display: flex; gap: 6px; justify-content: center; align-items: center; flex-wrap: wrap;">
                    ${actionHtml}
                    ${resetPicHtml}
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    // Render Pagination Bar
    renderPaginationNav('pagination-nav-perusahaan', 'pagination-info-perusahaan', totalItems, safePage, pageSizePerusahaan, renderPerusahaanTablePage, 'entitas');

    // Attach Event Handlers
    document.querySelectorAll('.btn-reset-password').forEach(btn => {
        btn.addEventListener('click', () => {
            const entitasId = btn.getAttribute('data-id');
            const nama = btn.getAttribute('data-nama');
            resetPerusahaanPassword(entitasId, nama);
        });
    });

    document.querySelectorAll('.btn-create-single').forEach(btn => {
        btn.addEventListener('click', () => {
            const entitasId = btn.getAttribute('data-id');
            const nama = btn.getAttribute('data-nama');
            createSinglePerusahaanAccount(entitasId, nama);
        });
    });

    document.querySelectorAll('.btn-reset-pic').forEach(btn => {
        btn.addEventListener('click', () => {
            const entitasId = parseInt(btn.getAttribute('data-id'), 10);
            openResetPicModal(entitasId);
        });
    });
}

function renderPaginationNav(containerId, infoId, totalItems, currentPage, pageSize, onPageChange, itemLabel = 'data') {
    const container = document.getElementById(containerId);
    const info = document.getElementById(infoId);
    if (!container || !info) return;

    const totalPages = Math.ceil(totalItems / pageSize) || 1;
    const safePage = Math.min(Math.max(1, currentPage), totalPages);

    const startIdx = totalItems === 0 ? 0 : (safePage - 1) * pageSize + 1;
    const endIdx = Math.min(safePage * pageSize, totalItems);

    info.innerText = `Menampilkan ${startIdx} - ${endIdx} dari ${totalItems} ${itemLabel}`;
    container.innerHTML = '';

    if (totalPages <= 1) return;

    // Tombol Sebelumnya
    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'btn-action-sm';
    prevBtn.style.cssText = 'padding: 6px 12px; font-size: 0.8rem; background: #fff; border: 1px solid #cbd5e1; color: #475569; border-radius: 6px; cursor: pointer;';
    prevBtn.innerHTML = '&larr; Sebelumnya';
    prevBtn.disabled = safePage <= 1;
    if (safePage <= 1) {
        prevBtn.style.opacity = '0.5';
        prevBtn.style.cursor = 'not-allowed';
    } else {
        prevBtn.addEventListener('click', () => onPageChange(safePage - 1));
    }
    container.appendChild(prevBtn);

    // Nomor Halaman
    const startPage = Math.max(1, safePage - 2);
    const endPage = Math.min(totalPages, safePage + 2);

    if (startPage > 1) {
        container.appendChild(createPageButton(1, safePage === 1, onPageChange));
        if (startPage > 2) {
            const dots = document.createElement('span');
            dots.innerText = '...';
            dots.style.cssText = 'padding: 0 4px; color: #94a3b8; font-size: 0.8rem;';
            container.appendChild(dots);
        }
    }

    for (let p = startPage; p <= endPage; p++) {
        container.appendChild(createPageButton(p, p === safePage, onPageChange));
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const dots = document.createElement('span');
            dots.innerText = '...';
            dots.style.cssText = 'padding: 0 4px; color: #94a3b8; font-size: 0.8rem;';
            container.appendChild(dots);
        }
        container.appendChild(createPageButton(totalPages, safePage === totalPages, onPageChange));
    }

    // Tombol Berikutnya
    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'btn-action-sm';
    nextBtn.style.cssText = 'padding: 6px 12px; font-size: 0.8rem; background: #fff; border: 1px solid #cbd5e1; color: #475569; border-radius: 6px; cursor: pointer;';
    nextBtn.innerHTML = 'Berikutnya &rarr;';
    nextBtn.disabled = safePage >= totalPages;
    if (safePage >= totalPages) {
        nextBtn.style.opacity = '0.5';
        nextBtn.style.cursor = 'not-allowed';
    } else {
        nextBtn.addEventListener('click', () => onPageChange(safePage + 1));
    }
    container.appendChild(nextBtn);
}

function createPageButton(pageNum, isActive, onClick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-action-sm';
    btn.innerText = pageNum;
    if (isActive) {
        btn.style.cssText = 'padding: 6px 11px; font-size: 0.8rem; font-weight: 700; background: #004687; border: 1px solid #004687; color: #fff; border-radius: 6px; cursor: default;';
    } else {
        btn.style.cssText = 'padding: 6px 11px; font-size: 0.8rem; background: #fff; border: 1px solid #cbd5e1; color: #475569; border-radius: 6px; cursor: pointer;';
        btn.addEventListener('click', () => onClick(pageNum));
    }
    return btn;
}

async function resetPerusahaanPassword(entitasId, nama) {
    const isConfirmed = await showAdminConfirm(
        `Reset password akun mitra "${nama}"? Sistem akan menghasilkan password sementara baru dan mewajibkan perubahan password saat login berikutnya.`,
        'Reset Password Akun',
        'warning',
        'Ya, Reset Password',
        'Batal'
    );
    if (!isConfirmed) return;

    try {
        const res = await fetch('/api/admin/kelola/perusahaan_accounts.php?action=reset_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ entitas_id: entitasId })
        });
        const data = await res.json();

        if (data.ok) {
            showCredentialModal(
                'Password Berhasil Direset',
                `
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 4px;">Kantor / Unit:</div>
                    <div style="font-weight: 700; color: #004687; font-size: 1rem; margin-bottom: 12px;">${escapeHtml(data.entitas_nama)}</div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 3px;">Username Login:</div>
                            <div style="font-family: monospace; font-weight: 800; font-size: 1.05rem; color: #1e293b; background: #fff; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">${escapeHtml(data.username)}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 3px;">Password Sementara:</div>
                            <div style="font-family: monospace; font-weight: 800; font-size: 1.05rem; color: #004687; background: #eff6ff; padding: 6px 10px; border-radius: 6px; border: 1px solid #bfdbfe;">${escapeHtml(data.temp_password)}</div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                        <button type="button" class="btn-portal-primary" id="btn-modal-copy-cred" style="padding: 6px 14px; font-size: 0.8rem;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                            <span>Salin Username & Password</span>
                        </button>
                    </div>
                </div>
                <div style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.825rem; color: #64748b; line-height: 1.4;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                    <span>Berikan kredensial sementara ini kepada PIC kantor terkait. Sistem akan langsung meminta PIC memasukkan password rahasia baru saat pertama kali login.</span>
                </div>
                `
            );
            document.getElementById('btn-modal-copy-cred')?.addEventListener('click', () => {
                const textToCopy = `Unit: ${data.entitas_nama}\nUsername: ${data.username}\nPassword: ${data.temp_password}\nLink Login: ${window.location.origin}/admin/login.html`;
                navigator.clipboard.writeText(textToCopy);
                showAdminAlert('Kredensial berhasil disalin ke clipboard!', 'success');
            });
            loadPerusahaanData();
        } else {
            await showAdminAlert(data.error || 'Gagal mereset password.', 'error');
        }
    } catch (err) {
        console.error(err);
        await showAdminAlert('Kesalahan jaringan saat reset password.', 'error');
    }
}

async function createSinglePerusahaanAccount(entitasId, nama) {
    try {
        const res = await fetch('/api/admin/kelola/perusahaan_accounts.php?action=create_single', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ entitas_id: entitasId })
        });
        const data = await res.json();

        if (data.ok) {
            showCredentialModal(
                'Akun Berhasil Dibuat',
                `
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 4px;">Kantor / Unit:</div>
                    <div style="font-weight: 700; color: #004687; font-size: 1rem; margin-bottom: 12px;">${escapeHtml(data.entitas_nama)}</div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 3px;">Username Login:</div>
                            <div style="font-family: monospace; font-weight: 800; font-size: 1.05rem; color: #1e293b; background: #fff; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">${escapeHtml(data.username)}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 3px;">Password Sementara:</div>
                            <div style="font-family: monospace; font-weight: 800; font-size: 1.05rem; color: #004687; background: #eff6ff; padding: 6px 10px; border-radius: 6px; border: 1px solid #bfdbfe;">${escapeHtml(data.temp_password)}</div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                        <button type="button" class="btn-portal-primary" id="btn-modal-single-copy" style="padding: 6px 14px; font-size: 0.8rem;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                            <span>Salin Username & Password</span>
                        </button>
                    </div>
                </div>
                <div style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.825rem; color: #64748b; line-height: 1.4;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                    <span>Akun siap digunakan oleh admin unit mitra untuk pertama kali login.</span>
                </div>
                `
            );
            document.getElementById('btn-modal-single-copy')?.addEventListener('click', () => {
                const textToCopy = `Unit: ${data.entitas_nama}\nUsername: ${data.username}\nPassword: ${data.temp_password}\nLink Login: ${window.location.origin}/admin/login.html`;
                navigator.clipboard.writeText(textToCopy);
                showAdminAlert('Kredensial berhasil disalin ke clipboard!', 'success');
            });
            loadPerusahaanData();
        } else {
            await showAdminAlert(data.error || 'Gagal membuat akun unit.', 'error');
        }
    } catch (err) {
        console.error(err);
        await showAdminAlert('Kesalahan jaringan saat membuat akun.', 'error');
    }
}

function renderGeneratedListHtml(accounts) {
    let rowsHtml = accounts.map(a => `
        <tr>
            <td style="padding: 6px; border: 1px solid #e2e8f0; font-size: 0.8rem; font-weight: 600;">${escapeHtml(a.entitas_nama)}</td>
            <td style="padding: 6px; border: 1px solid #e2e8f0; font-family: monospace; font-size: 0.85rem; font-weight: bold; color: #004687;">${escapeHtml(a.username)}</td>
            <td style="padding: 6px; border: 1px solid #e2e8f0; font-family: monospace; font-size: 0.85rem; font-weight: bold; color: #166534;">${escapeHtml(a.temp_password)}</td>
        </tr>
    `).join('');

    return `
        <div style="margin-bottom: 12px; font-size: 0.85rem; color: #334155;">
            Total <strong>${accounts.length}</strong> akun berhasil dibuat. Anda dapat mengunduh dokumen kredensial resmi untuk dibagikan ke unit.
        </div>
        <div style="max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 16px;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background: #f1f5f9; position: sticky; top: 0;">
                    <tr>
                        <th style="padding: 6px; border: 1px solid #cbd5e1; text-align: left; font-size: 0.75rem;">Nama Unit</th>
                        <th style="padding: 6px; border: 1px solid #cbd5e1; text-align: left; font-size: 0.75rem;">Username</th>
                        <th style="padding: 6px; border: 1px solid #cbd5e1; text-align: left; font-size: 0.75rem;">Password Sementara</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        </div>
        <div style="text-align: center;">
            <a href="/api/admin/kelola/perusahaan_export.php?format=print" target="_blank" class="btn-action-sm btn-action-primary" style="padding: 8px 16px; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                <span>Cetak Dokumen PDF Kredensial Resmi</span>
            </a>
        </div>
    `;
}

// ==========================================
// MODUL ADMIN KAMPUS (BLKA)
// ==========================================
function initKampusModule() {
    const formAddAdmin = document.getElementById('form-add-admin');
    if (formAddAdmin) {
        formAddAdmin.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = document.getElementById('add-email').value.trim();
            const nama = document.getElementById('add-nama').value.trim();
            const role = document.getElementById('add-role').value;

            if (!email || !nama) return;

            try {
                const res = await fetch('/api/admin/kelola/assign.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ email: email, nama: nama, role: role })
                });
                const data = await res.json();
                if (data.ok) {
                    await showAdminAlert(data.message || 'Hak akses admin berhasil ditambahkan.', 'success');
                    formAddAdmin.reset();
                    loadKampusData();
                } else {
                    await showAdminAlert(data.error || 'Gagal menambahkan admin.', 'error');
                }
            } catch (err) {
                console.error(err);
                await showAdminAlert('Kesalahan jaringan saat menambahkan admin.', 'error');
            }
        });
    }
}

async function loadKampusData() {
    try {
        const res = await fetch('/api/admin/kelola/list.php');
        const data = await res.json();

        if (data.ok) {
            rawKampusData = data.admins || [];
            document.getElementById('count-kampus').innerText = rawKampusData.length;
            currentPageKampus = 1;
            renderKampusTablePage(1, data.current_admin || currentAdmin);
        }
    } catch (err) {
        console.error('Error loadKampusData:', err);
    }
}

function renderKampusTablePage(page = 1, currAdmin = null) {
    if (!currAdmin) currAdmin = currentAdmin;
    currentPageKampus = page;
    const tbody = document.getElementById('table-admin');
    if (!tbody) return;
    tbody.innerHTML = '';

    const totalItems = rawKampusData.length;

    if (totalItems === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 24px;">Belum ada akun admin kampus terdaftar.</td></tr>';
        renderPaginationNav('pagination-nav-kampus', 'pagination-info-kampus', 0, 1, pageSizeKampus, (p) => renderKampusTablePage(p, currAdmin), 'admin');
        return;
    }

    const totalPages = Math.ceil(totalItems / pageSizeKampus) || 1;
    const safePage = Math.min(Math.max(1, page), totalPages);
    currentPageKampus = safePage;

    const startIdx = (safePage - 1) * pageSizeKampus;
    const pageItems = rawKampusData.slice(startIdx, startIdx + pageSizeKampus);

    pageItems.forEach((a, idx) => {
        const rowNumber = startIdx + idx + 1;
        const isSuper = (a.role === 'super_admin' || a.role === 'superadmin');
        const roleBadge = isSuper
            ? '<span class="badge-status" style="background: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe; font-weight: 700;">Super Admin BLKA</span>'
            : '<span class="badge-status badge-dibuka">Admin BLKA</span>';

        const isSelf = currAdmin && (parseInt(currAdmin.id) === parseInt(a.admin_id));
        const statusActive = a.aktif == 1;

        let actionBtn = '-';
        if (!isSelf && statusActive) {
            actionBtn = `
                <button class="btn-role-action btn-role-revoke btn-revoke-admin" data-id="${a.admin_id}" data-nama="${escapeHtml(a.admin_nama || 'Admin')}" title="Cabut Akses Admin">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="22" y2="13"/><line x1="22" y1="8" x2="17" y2="13"/></svg>
                    <span>Cabut Akses</span>
                </button>
            `;
        } else if (isSelf) {
            actionBtn = '<span style="font-size: 0.775rem; color: #10b981; font-weight: 700; background: #ecfdf5; padding: 4px 10px; border-radius: 12px; border: 1px solid #a7f3d0;">Akun Anda</span>';
        } else if (!statusActive) {
            actionBtn = '<span style="font-size: 0.775rem; color: #94a3b8;">Nonaktif</span>';
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="text-align: center; color: #64748b;">${rowNumber}</td>
            <td style="font-weight: 700; color: #0b3d6b;">${escapeHtml(a.admin_nama || 'Admin')}</td>
            <td style="font-family: monospace;">${escapeHtml(a.admin_email || '-')}</td>
            <td>${roleBadge}</td>
            <td style="font-size: 0.85rem; color: #64748b;">${escapeHtml(a.created_at || '-')}</td>
            <td style="text-align: center;">${actionBtn}</td>
        `;
        tbody.appendChild(tr);
    });

    renderPaginationNav('pagination-nav-kampus', 'pagination-info-kampus', totalItems, safePage, pageSizeKampus, (p) => renderKampusTablePage(p, currAdmin), 'admin');

    document.querySelectorAll('.btn-revoke-admin').forEach(btn => {
        btn.addEventListener('click', () => {
            const aid = btn.getAttribute('data-id');
            const nama = btn.getAttribute('data-nama');
            revokeAdmin(aid, nama);
        });
    });
}

async function revokeAdmin(adminId, nama) {
    const isConfirmed = await showAdminConfirm(
        `Apakah Anda yakin ingin mencabut hak akses admin milik ${nama}?`,
        'Cabut Akses Admin',
        'danger',
        'Ya, Cabut Akses',
        'Batal'
    );
    if (!isConfirmed) return;

    try {
        const res = await fetch('/api/admin/kelola/revoke.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ admin_id: adminId })
        });
        const data = await res.json();

        if (data.ok) {
            await showAdminAlert(data.message || 'Hak akses admin berhasil dicabut.', 'success');
            loadKampusData();
        } else {
            await showAdminAlert(data.error || 'Gagal mencabut akses admin.', 'error');
        }
    } catch (err) {
        console.error(err);
        await showAdminAlert('Kesalahan jaringan saat mencabut akses admin.', 'error');
    }
}

// ==========================================
// MODAL KREDENSIAL
// ==========================================
function initModalCred() {
    const modal = document.getElementById('modal-credential');
    const btnClose = document.getElementById('btn-close-modal-cred');
    const btnOk = document.getElementById('btn-ok-modal-cred');

    const closeModal = () => {
        if (modal) modal.classList.add('hidden');
    };

    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnOk) btnOk.addEventListener('click', closeModal);
}

function showCredentialModal(title, bodyHtml) {
    const modal = document.getElementById('modal-credential');
    const titleEl = document.getElementById('modal-cred-title');
    const bodyEl = document.getElementById('modal-cred-body');

    if (titleEl) titleEl.innerText = title;
    if (bodyEl) bodyEl.innerHTML = bodyHtml;
    if (modal) modal.classList.remove('hidden');
}

// ==========================================
// MODAL MERAH RESET DATA PIC (SUPER ADMIN)
// ==========================================
let pendingResetEntitasId = null;

function initModalResetPic() {
    const modal = document.getElementById('modal-reset-pic');
    const btnClose = document.getElementById('btn-close-modal-reset-pic');
    const btnCancel = document.getElementById('btn-cancel-reset-pic');
    const btnConfirm = document.getElementById('btn-confirm-reset-pic');

    const closeModal = () => {
        if (modal) modal.classList.add('hidden');
        pendingResetEntitasId = null;
    };

    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);

    if (btnConfirm) {
        btnConfirm.addEventListener('click', async () => {
            if (!pendingResetEntitasId) return;

            const btnText = document.getElementById('btn-confirm-reset-pic-text');
            const originalText = btnText ? btnText.innerText : 'Hapus & Reset PIC';

            btnConfirm.disabled = true;
            if (btnText) btnText.innerText = 'Mereset Data...';

            try {
                const res = await fetch('/api/admin/kelola/perusahaan_accounts.php?action=reset_pic', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ entitas_id: pendingResetEntitasId })
                });
                const data = await res.json();

                if (data.ok) {
                    closeModal();
                    await showAdminAlert(
                        data.message || 'Data PIC entitas berhasil direset.',
                        'success',
                        'Data PIC Berhasil Direset'
                    );
                    loadPerusahaanData();
                } else {
                    await showAdminAlert(data.error || 'Gagal mereset data PIC entitas.', 'error', 'Reset PIC Gagal');
                }
            } catch (err) {
                console.error(err);
                await showAdminAlert('Terjadi kesalahan jaringan saat mereset data PIC.', 'error');
            } finally {
                btnConfirm.disabled = false;
                if (btnText) btnText.innerText = originalText;
            }
        });
    }
}

function openResetPicModal(entitasId) {
    const item = rawPerusahaanData.find(e => e.entitas_id === entitasId);
    if (!item) return;

    pendingResetEntitasId = entitasId;

    const elName = document.getElementById('reset-pic-entity-name');
    const elType = document.getElementById('reset-pic-entity-type');
    const elNama = document.getElementById('reset-pic-val-nama');
    const elJabatan = document.getElementById('reset-pic-val-jabatan');
    const elKontak = document.getElementById('reset-pic-val-kontak');
    const elEmail = document.getElementById('reset-pic-val-email');

    if (elName) elName.innerText = item.entitas_nama + (item.singkatan ? ` (${item.singkatan})` : '');
    if (elType) elType.innerText = `Tingkat: ${(item.tipe || '-').toUpperCase().replace('_', ' ')} • Induk: ${item.parent_nama || 'Holding / Pusat'}`;
    if (elNama) elNama.innerText = item.pic_nama || '-';
    if (elJabatan) elJabatan.innerText = item.pic_jabatan || '-';
    if (elKontak) elKontak.innerText = item.pic_kontak || '-';
    if (elEmail) elEmail.innerText = item.pic_email || '-';

    const modal = document.getElementById('modal-reset-pic');
    if (modal) modal.classList.remove('hidden');
}

// ==========================================
// MODUL ADMIN PROGRAM STUDI (PRODI)
// ==========================================
function initProdiModule() {
    const searchInput = document.getElementById('filter-search-prodi');
    const statusSelect = document.getElementById('filter-status-prodi');

    if (searchInput) {
        searchInput.addEventListener('input', () => filterAndRenderProdi());
    }
    if (statusSelect) {
        statusSelect.addEventListener('change', () => filterAndRenderProdi());
    }

    const btnGenAll = document.getElementById('btn-generate-all-prodi');
    if (btnGenAll) {
        btnGenAll.addEventListener('click', () => handleGenerateAllProdi());
    }
}

async function loadProdiData() {
    try {
        const res = await fetch('/api/admin/kelola/prodi_accounts.php?action=list');
        const data = await res.json();
        if (!data.ok) return;

        rawProdiData = data.data || [];

        // Update stats
        const countBadge = document.getElementById('count-prodi');
        if (countBadge) countBadge.innerText = rawProdiData.length;

        const statTotal = document.getElementById('stat-prodi-total');
        if (statTotal) statTotal.innerText = data.stats.total_prodi;

        const statHasAcc = document.getElementById('stat-prodi-has-account');
        if (statHasAcc) statHasAcc.innerText = data.stats.total_has_account;

        const statNoAcc = document.getElementById('stat-prodi-no-account');
        if (statNoAcc) statNoAcc.innerText = data.stats.total_no_account;

        const statAktif = document.getElementById('stat-prodi-aktif');
        if (statAktif) statAktif.innerText = data.stats.total_aktif;

        filterAndRenderProdi();
    } catch (e) {
        console.error('Gagal memuat data prodi:', e);
    }
}

function filterAndRenderProdi() {
    const search = (document.getElementById('filter-search-prodi')?.value || '').toLowerCase().trim();
    const status = document.getElementById('filter-status-prodi')?.value || '';

    const filtered = rawProdiData.filter(item => {
        const nama = (item.nama_jurusan || '').toLowerCase();
        const jenjang = (item.jenjang || '').toLowerCase();
        const kode = (item.kode || '').toLowerCase();
        const username = (item.username || '').toLowerCase();

        const matchSearch = !search || nama.includes(search) || jenjang.includes(search) || kode.includes(search) || username.includes(search);

        let matchStatus = true;
        if (status === 'has_account') matchStatus = item.has_account;
        else if (status === 'no_account') matchStatus = !item.has_account;
        else if (status === 'aktif') matchStatus = item.has_account && item.admin_aktif;
        else if (status === 'nonaktif') matchStatus = item.has_account && !item.admin_aktif;

        return matchSearch && matchStatus;
    });

    const tbody = document.getElementById('table-prodi');
    if (!tbody) return;

    if (filtered.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; color: #64748b; padding: 32px 16px;">
                    Tidak ada Program Studi yang sesuai dengan filter pencarian.
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = filtered.map((item, idx) => {
        const hasAcc = item.has_account;
        const statusBadge = !hasAcc 
            ? `<span style="background: #f1f5f9; color: #64748b; font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-block;">Belum Ada Akun</span>`
            : (item.admin_aktif 
                ? `<span style="background: #dcfce7; color: #15803d; font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-block;">Aktif</span>`
                : `<span style="background: #fee2e2; color: #b91c1c; font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-block;">Nonaktif</span>`);

        const usernameDisplay = hasAcc
            ? `<span style="font-family: monospace; font-weight: 700; color: #0f172a; background: #f8fafc; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 4px;">${escapeHtml(item.username)}</span>`
            : `<span style="font-family: monospace; font-size: 0.8rem; color: #94a3b8; font-style: italic;">${escapeHtml(item.default_username)}</span>`;

        let actionBtns = '';
        if (hasAcc) {
            actionBtns = `
                <div style="display: flex; gap: 6px; justify-content: center; align-items: center;">
                    <button type="button" class="btn-action-sm btn-action-outline btn-copy-prodi" data-id="${item.jurusan_id}" title="Salin Kredensial Login">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        <span>Salin</span>
                    </button>
                    <button type="button" class="btn-action-sm btn-action-warning btn-reset-prodi" data-id="${item.jurusan_id}" data-admin="${item.admin_id}" data-nama="${escapeHtml(item.jenjang + ' ' + item.nama_jurusan)}" data-pass="${escapeHtml(item.default_password)}" title="Reset Password Akun">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                        <span>Reset</span>
                    </button>
                    <button type="button" class="btn-action-sm ${item.admin_aktif ? 'btn-action-danger' : 'btn-action-primary'} btn-toggle-prodi" data-admin="${item.admin_id}" data-aktif="${item.admin_aktif ? 1 : 0}" title="${item.admin_aktif ? 'Nonaktifkan Akun' : 'Aktifkan Akun'}">
                        ${item.admin_aktif ? 'Nonaktifkan' : 'Aktifkan'}
                    </button>
                </div>
            `;
        } else {
            actionBtns = `
                <span style="font-size: 0.775rem; color: #94a3b8; font-style: italic;">Belum terbit</span>
            `;
        }

        return `
            <tr>
                <td style="text-align: center; font-size: 0.825rem; color: #64748b;">${idx + 1}</td>
                <td style="text-align: center;"><span style="font-weight: 700; color: #004687; font-size: 0.8rem; background: #e0f2fe; padding: 2px 8px; border-radius: 4px;">${escapeHtml(item.jenjang)}</span></td>
                <td style="text-align: center; font-family: monospace; font-weight: 700; color: #475569;">${escapeHtml(item.kode)}</td>
                <td>
                    <div style="font-weight: 700; color: #0f172a; font-size: 0.9rem;">${escapeHtml(item.nama_jurusan)}</div>
                    <div style="font-size: 0.75rem; color: #64748b;">Password default: <span style="font-family: monospace; font-weight: 700; color: #0284c7;">${escapeHtml(item.default_password)}</span></div>
                </td>
                <td>${usernameDisplay}</td>
                <td style="text-align: center;">
                    <div style="font-weight: 700; color: #004687; font-size: 0.9rem;">${item.total_pendaftar}</div>
                    <div style="font-size: 0.725rem; color: #166534;">${item.total_diterima} diterima</div>
                </td>
                <td style="text-align: center;">${statusBadge}</td>
                <td>${actionBtns}</td>
            </tr>
        `;
    }).join('');

    // Attach listeners
    tbody.querySelectorAll('.btn-copy-prodi').forEach(btn => {
        btn.addEventListener('click', () => {
            const jId = parseInt(btn.getAttribute('data-id'), 10);
            const item = rawProdiData.find(p => p.jurusan_id === jId);
            if (!item) return;

            const text = `Prodi: ${item.jenjang} ${item.nama_jurusan}\nUsername: ${item.username}\nPassword Default: ${item.default_password}\nPortal Login: ${window.location.origin}/admin/login.html`;
            navigator.clipboard.writeText(text).then(() => {
                showAdminAlert('Kredensial login akun prodi berhasil disalin ke clipboard!', 'success');
            }).catch(() => {
                showAdminAlert(text, 'info', 'Salin Kredensial Prodi');
            });
        });
    });

    tbody.querySelectorAll('.btn-reset-prodi').forEach(btn => {
        btn.addEventListener('click', () => {
            const jId = parseInt(btn.getAttribute('data-id'), 10);
            const adminId = parseInt(btn.getAttribute('data-admin'), 10);
            const nama = btn.getAttribute('data-nama');
            const defaultPass = btn.getAttribute('data-pass');
            handleResetProdiPassword(jId, adminId, nama, defaultPass);
        });
    });

    tbody.querySelectorAll('.btn-toggle-prodi').forEach(btn => {
        btn.addEventListener('click', () => {
            const adminId = parseInt(btn.getAttribute('data-admin'), 10);
            const isAktif = parseInt(btn.getAttribute('data-aktif'), 10) === 1;
            handleToggleProdiStatus(adminId, isAktif);
        });
    });
}

async function handleGenerateAllProdi() {
    const isConfirmed = await showAdminConfirm(
        'Generate akun login untuk seluruh Program Studi yang belum memiliki akun? Kredensial akan dibuat menggunakan template username & password resmi.',
        'Generate Semua Akun Prodi',
        'info',
        'Ya, Generate Sekarang',
        'Batal'
    );
    if (!isConfirmed) return;

    try {
        const btn = document.getElementById('btn-generate-all-prodi');
        if (btn) {
            btn.disabled = true;
            btn.innerText = 'Memproses...';
        }

        const res = await fetch('/api/admin/kelola/prodi_accounts.php?action=generate_all', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({})
        });
        const data = await res.json();

        if (data.ok) {
            await showAdminAlert(data.message, 'success', 'Akun Prodi Berhasil Dibuat');
            loadProdiData();
        } else {
            await showAdminAlert(data.error || 'Gagal membuat akun prodi.', 'error');
        }
    } catch (e) {
        console.error(e);
        await showAdminAlert('Terjadi kesalahan sistem saat membuat akun prodi.', 'error');
    } finally {
        const btn = document.getElementById('btn-generate-all-prodi');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg><span>Generate Semua Akun Prodi</span>`;
        }
    }
}

async function handleResetProdiPassword(jurusanId, adminId, nama, defaultPass) {
    const isConfirmed = await showAdminConfirm(
        `Reset password akun prodi "${nama}" kembali ke password default ("${defaultPass}")?`,
        'Reset Password Prodi',
        'warning',
        'Ya, Reset ke Default',
        'Batal'
    );
    if (!isConfirmed) return;

    try {
        const res = await fetch('/api/admin/kelola/prodi_accounts.php?action=reset_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ jurusan_id: jurusanId, admin_id: adminId, mode: 'default' })
        });
        const data = await res.json();

        if (data.ok) {
            showCredentialModal(
                'Password Prodi Berhasil Direset',
                `
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                    <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 4px;">Program Studi:</div>
                    <div style="font-weight: 700; color: #004687; font-size: 1rem; margin-bottom: 12px;">${escapeHtml(data.prodi_nama)}</div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 3px;">Username Login:</div>
                            <div style="font-family: monospace; font-weight: 800; font-size: 1.05rem; color: #1e293b; background: #fff; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">${escapeHtml(data.username)}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 3px;">Password Baru:</div>
                            <div style="font-family: monospace; font-weight: 800; font-size: 1.05rem; color: #004687; background: #eff6ff; padding: 6px 10px; border-radius: 6px; border: 1px solid #bfdbfe;">${escapeHtml(data.new_password)}</div>
                        </div>
                    </div>
                </div>
                `
            );
            loadProdiData();
        } else {
            await showAdminAlert(data.error || 'Gagal mereset password akun prodi.', 'error');
        }
    } catch (e) {
        console.error(e);
        await showAdminAlert('Terjadi kesalahan sistem saat mereset password.', 'error');
    }
}

async function handleToggleProdiStatus(adminId, isAktif) {
    const actionLabel = isAktif ? 'menonaktifkan' : 'mengaktifkan';
    const isConfirmed = await showAdminConfirm(
        `Anda yakin ingin ${actionLabel} akun admin program studi ini?`,
        'Konfirmasi Status Akun',
        'warning',
        'Ya, Lanjutkan',
        'Batal'
    );
    if (!isConfirmed) return;

    try {
        const res = await fetch('/api/admin/kelola/prodi_accounts.php?action=toggle_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ admin_id: adminId })
        });
        const data = await res.json();
        if (data.ok) {
            await showAdminAlert(data.message, 'success');
            loadProdiData();
        } else {
            await showAdminAlert(data.error || 'Gagal mengubah status akun prodi.', 'error');
        }
    } catch (e) {
        console.error(e);
        await showAdminAlert('Terjadi kesalahan jaringan.', 'error');
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
