import { showAdminAlert, showAdminConfirm, showAdminToast } from '/js/admin/common.js';

let csrfToken = null;
let selectedPeriodeId = 0;
let allPeriodeList = [];
let currentTransferData = [];
let currentPage = 1;
let perPage = 25;
let totalRecords = 0;
let totalPages = 1;

document.addEventListener('DOMContentLoaded', async () => {
    // 1. Fetch CSRF token
    try {
        const statusRes = await fetch('/api/admin/status.php');
        const statusData = await statusRes.json();
        if (statusData.authenticated && statusData.csrf_token) {
            csrfToken = statusData.csrf_token;
        }
    } catch (e) {
        console.error('Error fetching CSRF token:', e);
    }

    // 2. Initialize filter controls
    const selectPeriode = document.getElementById('filter-periode');
    const selectStatus = document.getElementById('filter-status');
    const inputSearch = document.getElementById('filter-search');
    const selectPerPage = document.getElementById('filter-per-page');

    if (selectPeriode) {
        selectPeriode.addEventListener('change', () => {
            selectedPeriodeId = parseInt(selectPeriode.value, 10) || 0;
            currentPage = 1;
            loadPemindahanData();
        });
    }

    if (selectStatus) {
        selectStatus.addEventListener('change', () => {
            currentPage = 1;
            loadPemindahanData();
        });
    }

    if (inputSearch) {
        let debounceTimer;
        inputSearch.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                currentPage = 1;
                loadPemindahanData();
            }, 300);
        });
    }

    if (selectPerPage) {
        selectPerPage.addEventListener('change', () => {
            perPage = parseInt(selectPerPage.value, 10) || 25;
            currentPage = 1;
            loadPemindahanData();
        });
    }

    // Pagination button listeners
    document.getElementById('btn-prev-pemindahan')?.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            loadPemindahanData();
        }
    });

    document.getElementById('btn-next-pemindahan')?.addEventListener('click', () => {
        if (currentPage < totalPages) {
            currentPage++;
            loadPemindahanData();
        }
    });

    // 3. Modal close buttons
    document.querySelectorAll('.btn-close-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            if (targetId) {
                document.getElementById(targetId)?.classList.add('hidden');
            }
        });
    });

    // 4. Form Intervensi Submit
    const formIntervensi = document.getElementById('form-intervensi');
    if (formIntervensi) {
        formIntervensi.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pemId = parseInt(document.getElementById('intervensi-id').value, 10);
            const action = document.getElementById('intervensi-action').value;
            const catatan = document.getElementById('intervensi-catatan').value.trim();

            const btnSubmit = document.getElementById('btn-submit-intervensi');
            btnSubmit.disabled = true;
            btnSubmit.innerText = 'Memproses...';

            try {
                const res = await fetch('/api/admin/pemindahan/intervensi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        pemindahan_id: pemId,
                        action: action,
                        catatan: catatan
                    })
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    showAdminToast(data.message || 'Intervensi pemindahan berhasil diproses.', 'success');
                    document.getElementById('modal-intervensi')?.classList.add('hidden');
                    formIntervensi.reset();
                    await loadPemindahanData();
                } else {
                    showAdminAlert(data.error || 'Gagal memproses intervensi pemindahan.', 'error');
                }
            } catch (err) {
                console.error('Error intervensi:', err);
                showAdminAlert('Terjadi kesalahan jaringan.', 'error');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.innerText = 'Proses Intervensi';
            }
        });
    }

    // 5. Initial Data Load
    await loadPemindahanData();
});

async function loadPemindahanData() {
    const tbody = document.getElementById('table-pemindahan');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #64748b; padding: 32px;">Memuat data pemindahan...</td></tr>';

    const statusApproval = document.getElementById('filter-status')?.value || '';
    const search = document.getElementById('filter-search')?.value.trim() || '';

    let url = `/api/admin/pemindahan/list.php?page=${currentPage}&per_page=${perPage}`;
    if (selectedPeriodeId > 0) url += `&periode_id=${selectedPeriodeId}`;
    if (statusApproval) url += `&status_approval=${encodeURIComponent(statusApproval)}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;

    try {
        const res = await fetch(url);
        const data = await res.json();

        if (data.ok) {
            const activePeriode = data.periode || data.periode_terpilih;

            // Populate periode dropdown if not yet populated
            if (allPeriodeList.length === 0 && data.all_periode) {
                allPeriodeList = data.all_periode;
                const sel = document.getElementById('filter-periode');
                if (sel) {
                    sel.innerHTML = '';
                    if (allPeriodeList.length === 0) {
                        sel.innerHTML = '<option value="">Tidak ada periode</option>';
                    } else {
                        allPeriodeList.forEach(p => {
                            const opt = document.createElement('option');
                            opt.value = p.id;
                            opt.textContent = `${p.nama} (${(p.status || '').toUpperCase()})`;
                            if (activePeriode && parseInt(p.id) === parseInt(activePeriode.id)) {
                                opt.selected = true;
                                selectedPeriodeId = p.id;
                            }
                            sel.appendChild(opt);
                        });
                    }
                }
            } else if (activePeriode) {
                const sel = document.getElementById('filter-periode');
                if (sel && sel.value != activePeriode.id) {
                    sel.value = activePeriode.id;
                }
            }

            // Update info periode
            const infoP = document.getElementById('info-periode');
            if (infoP) {
                if (activePeriode) {
                    const st = (activePeriode.status || '').toLowerCase().trim();
                    let badgeClass = 'badge-persiapan';
                    if (st === 'dibuka') badgeClass = 'badge-dibuka';
                    else if (st === 'ditutup') badgeClass = 'badge-ditutup';

                    infoP.innerHTML = `Periode: <strong style="color: #0b3d6b;">${escapeHtml(activePeriode.nama)}</strong> &bull; Status: <span class="badge-status ${badgeClass}">${st.toUpperCase()}</span>`;
                } else {
                    infoP.innerHTML = '<span style="color: #64748b;">Tidak ada periode aktif</span>';
                }
            }

            // Update stats
            if (data.stats) {
                document.getElementById('stat-total-transfer').innerText = data.stats.total || 0;
                document.getElementById('stat-pending-transfer').innerText = data.stats.pending || 0;
                document.getElementById('stat-approved-transfer').innerText = data.stats.disetujui || 0;
                document.getElementById('stat-rejected-transfer').innerText = data.stats.ditolak || 0;
            }

            // Update pagination state
            if (data.pagination) {
                totalRecords = Number(data.pagination.total ?? (data.total ?? 0));
                totalPages = Number(data.pagination.total_pages ?? 1);
                currentPage = Number(data.pagination.page ?? 1);
            } else {
                totalRecords = Number(data.total ?? (data.data ? data.data.length : 0));
                totalPages = Math.ceil(totalRecords / perPage) || 1;
            }

            currentTransferData = data.data || [];
            const badgeCount = document.getElementById('filtered-count-badge');
            if (badgeCount) badgeCount.innerText = `${currentTransferData.length} Baris (${totalRecords} Total)`;

            renderTable(currentTransferData);
            renderPagination();
        } else {
            const infoP = document.getElementById('info-periode');
            if (infoP) infoP.innerHTML = '<span style="color: #dc2626;">Gagal memuat periode</span>';
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: #dc2626; padding: 32px;">${escapeHtml(data.error || 'Gagal memuat data.')}</td></tr>`;
            renderPaginationEmpty();
        }
    } catch (err) {
        console.error('Error loadPemindahanData:', err);
        const infoP = document.getElementById('info-periode');
        if (infoP) infoP.innerHTML = '<span style="color: #dc2626;">Gagal memuat periode</span>';
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #dc2626; padding: 32px;">Terjadi kesalahan jaringan saat memuat data.</td></tr>';
        renderPaginationEmpty();
    }
}

function renderTable(list) {
    const tbody = document.getElementById('table-pemindahan');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #64748b; padding: 32px;">Tidak ada data pemindahan peserta yang sesuai filter.</td></tr>';
        return;
    }

    list.forEach((item, idx) => {
        const tr = document.createElement('tr');

        // Status Badge
        let statusBadge = '';
        if (item.status_approval === 'menunggu_approval') {
            statusBadge = '<span class="badge-status badge-persiapan" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">Menunggu Approval</span>';
        } else if (item.status_approval === 'disetujui') {
            statusBadge = '<span class="badge-status badge-dibuka" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Disetujui Mitra</span>';
        } else if (item.status_approval === 'ditolak') {
            statusBadge = '<span class="badge-status badge-ditutup" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">Ditolak Mitra</span>';
        } else if (item.status_approval === 'force_blka') {
            statusBadge = '<span class="badge-status badge-info" style="background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff;">Ditetapkan BLKA</span>';
        }

        const rawDate = item.created_at || item.tanggal_diajukan || item.waktu_pengajuan;
        const dateStr = rawDate ? new Date(rawDate).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-';
        const jurNama = item.jurusan_nama || item.nama_jurusan || '-';

        let actionHtml = '';
        if (item.status_approval === 'menunggu_approval') {
            actionHtml = `
                <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: center; flex-wrap: nowrap;">
                    <button type="button" class="btn-portal-primary btn-blka-approve" data-id="${item.id}" style="padding: 5px 10px; font-size: 0.75rem; background: #16a34a; border-color: #16a34a;" title="Paksa Setujui Pemindahan Ini (Bypass Unit Tujuan)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <span>Paksa Terima</span>
                    </button>
                    <button type="button" class="btn-portal-outline btn-blka-reject" data-id="${item.id}" style="padding: 5px 10px; font-size: 0.75rem; color: #dc2626; border-color: #fecaca; background: #fef2f2;" title="Batalkan Pemindahan & Kembalikan ke Unit Asal">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <span>Paksa Tolak</span>
                    </button>
                </div>
            `;
        } else {
            actionHtml = `<span style="font-size: 0.8rem; color: #64748b; font-weight: 600;">Selesai</span>`;
        }

        const rowNumber = (currentPage - 1) * perPage + idx + 1;

        tr.innerHTML = `
            <td style="text-align: center; color: #64748b;">${rowNumber}</td>
            <td>
                <div style="font-weight: 700; color: #0b3d6b;">${escapeHtml(item.mahasiswa_nama || item.nama || '-')}</div>
                <div style="font-family: monospace; font-size: 0.8rem; color: #64748b;">NIM: ${escapeHtml(item.nim || '-')}</div>
            </td>
            <td>
                <div style="font-weight: 600; color: #1e293b;">${escapeHtml(jurNama)}</div>
            </td>
            <td>
                <div style="font-weight: 600; color: #475569;">${escapeHtml(item.unit_asal_nama || 'Unit Lain')}</div>
            </td>
            <td>
                <div style="font-weight: 700; color: #0284c7;">${escapeHtml(item.unit_tujuan_nama || 'Unit Lain')}</div>
            </td>
            <td style="font-size: 0.825rem; color: #334155; max-width: 200px;">
                <div>${escapeHtml(item.alasan_pemindahan || '-')}</div>
                ${item.approval_catatan ? `<div style="margin-top: 4px; font-size: 0.75rem; color: #64748b;"><strong>Catatan:</strong> ${escapeHtml(item.approval_catatan)}</div>` : ''}
            </td>
            <td style="text-align: center;">${statusBadge}</td>
            <td style="text-align: center; font-size: 0.8rem; color: #64748b;">${dateStr}</td>
            <td style="text-align: center; white-space: nowrap;">${actionHtml}</td>
        `;
        tbody.appendChild(tr);
    });

    tbody.querySelectorAll('.btn-blka-approve').forEach(btn => {
        btn.addEventListener('click', () => {
            const pemId = parseInt(btn.getAttribute('data-id'), 10);
            const item = currentTransferData.find(t => t.id === pemId);
            if (item) openIntervensiModal(item, 'force_approve');
        });
    });

    tbody.querySelectorAll('.btn-blka-reject').forEach(btn => {
        btn.addEventListener('click', () => {
            const pemId = parseInt(btn.getAttribute('data-id'), 10);
            const item = currentTransferData.find(t => t.id === pemId);
            if (item) openIntervensiModal(item, 'force_reject');
        });
    });
}

function renderPagination() {
    const infoEl = document.getElementById('pagination-pemindahan-info');
    const pagesContainer = document.getElementById('pagination-pemindahan-pages');
    const btnPrev = document.getElementById('btn-prev-pemindahan');
    const btnNext = document.getElementById('btn-next-pemindahan');

    if (!infoEl || !pagesContainer || !btnPrev || !btnNext) return;

    const start = totalRecords === 0 ? 0 : (currentPage - 1) * perPage + 1;
    const end = Math.min(currentPage * perPage, totalRecords);

    infoEl.innerHTML = totalRecords === 0
        ? 'Menampilkan <strong>0</strong> data'
        : `Menampilkan <strong>${start}–${end}</strong> dari <strong>${totalRecords}</strong> data`;

    btnPrev.disabled = (currentPage <= 1);
    btnNext.disabled = (currentPage >= totalPages || totalPages === 0);

    pagesContainer.innerHTML = '';

    if (totalPages <= 1) {
        if (totalRecords > 0) {
            const badge = document.createElement('span');
            badge.className = 'admin-pagination-badge';
            badge.innerText = `Halaman 1 / 1`;
            pagesContainer.appendChild(badge);
        }
        return;
    }

    const pages = getPageNumbers(currentPage, totalPages);
    pages.forEach(p => {
        if (p === '...') {
            const span = document.createElement('span');
            span.className = 'admin-pagination-page-btn ellipsis';
            span.innerText = '…';
            pagesContainer.appendChild(span);
        } else {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `admin-pagination-page-btn ${p === currentPage ? 'active' : ''}`;
            btn.innerText = p;
            if (p !== currentPage) {
                btn.addEventListener('click', () => {
                    currentPage = p;
                    loadPemindahanData();
                });
            }
            pagesContainer.appendChild(btn);
        }
    });
}

function renderPaginationEmpty() {
    const infoEl = document.getElementById('pagination-pemindahan-info');
    const pagesContainer = document.getElementById('pagination-pemindahan-pages');
    const btnPrev = document.getElementById('btn-prev-pemindahan');
    const btnNext = document.getElementById('btn-next-pemindahan');

    if (infoEl) infoEl.innerHTML = 'Menampilkan <strong>0</strong> data';
    if (pagesContainer) pagesContainer.innerHTML = '';
    if (btnPrev) btnPrev.disabled = true;
    if (btnNext) btnNext.disabled = true;
}

function getPageNumbers(current, total) {
    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const pages = [];
    pages.push(1);

    if (current > 3) {
        pages.push('...');
    }

    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    if (current < total - 2) {
        pages.push('...');
    }

    pages.push(total);
    return pages;
}

function openIntervensiModal(item, action) {
    const modal = document.getElementById('modal-intervensi');
    if (!modal) return;

    document.getElementById('intervensi-id').value = item.id;
    document.getElementById('intervensi-action').value = action;
    document.getElementById('intervensi-mhs').innerText = `${item.mahasiswa_nama} (NIM: ${item.nim || '-'})`;
    document.getElementById('intervensi-unit-asal').innerText = item.unit_asal_nama || '-';
    document.getElementById('intervensi-unit-tujuan').innerText = item.unit_tujuan_nama || '-';
    document.getElementById('intervensi-catatan').value = '';

    const titleEl = document.getElementById('intervensi-modal-title');
    const alertEl = document.getElementById('intervensi-alert');
    const btnSubmit = document.getElementById('btn-submit-intervensi');

    if (action === 'force_approve') {
        titleEl.innerText = 'Intervensi: Paksa Terima Pemindahan';
        titleEl.style.color = '#16a34a';
        alertEl.className = 'alert alert-info';
        alertEl.innerText = 'Tindakan ini akan langsung menetapkan mahasiswa ke unit tujuan tanpa menunggu konfirmasi dari mitra perusahaan.';
        alertEl.classList.remove('hidden');
        btnSubmit.style.background = '#16a34a';
        btnSubmit.style.borderColor = '#16a34a';
        btnSubmit.innerHTML = '<span>Paksa Terima Pemindahan</span>';
    } else {
        titleEl.innerText = 'Intervensi: Paksa Tolak & Kembalikan';
        titleEl.style.color = '#dc2626';
        alertEl.className = 'alert alert-error';
        alertEl.innerText = 'Tindakan ini akan memulihkan kuota unit tujuan (+1) dan otomatis mengembalikan status mahasiswa ke unit asalnya.';
        alertEl.classList.remove('hidden');
        btnSubmit.style.background = '#dc2626';
        btnSubmit.style.borderColor = '#dc2626';
        btnSubmit.innerHTML = '<span>Paksa Tolak Pemindahan</span>';
    }

    modal.classList.remove('hidden');
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
