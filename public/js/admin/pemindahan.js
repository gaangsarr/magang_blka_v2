import { showAdminAlert, showAdminConfirm, showAdminToast } from '/js/admin/common.js';

let csrfToken = null;
let selectedPeriodeId = 0;
let allPeriodeList = [];
let currentTransferData = [];

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

    if (selectPeriode) {
        selectPeriode.addEventListener('change', () => {
            selectedPeriodeId = parseInt(selectPeriode.value, 10) || 0;
            loadPemindahanData();
        });
    }

    if (selectStatus) {
        selectStatus.addEventListener('change', () => {
            loadPemindahanData();
        });
    }

    if (inputSearch) {
        let debounceTimer;
        inputSearch.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                loadPemindahanData();
            }, 300);
        });
    }

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

    let url = `/api/admin/pemindahan/list.php?per_page=100`;
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

            currentTransferData = data.data || [];
            const badgeCount = document.getElementById('filtered-count-badge');
            if (badgeCount) badgeCount.innerText = `${currentTransferData.length} Data Ditampilkan`;

            renderTable(currentTransferData);
        } else {
            const infoP = document.getElementById('info-periode');
            if (infoP) infoP.innerHTML = '<span style="color: #dc2626;">Gagal memuat periode</span>';
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: #dc2626; padding: 32px;">${escapeHtml(data.error || 'Gagal memuat data.')}</td></tr>`;
        }
    } catch (err) {
        console.error('Error loadPemindahanData:', err);
        const infoP = document.getElementById('info-periode');
        if (infoP) infoP.innerHTML = '<span style="color: #dc2626;">Gagal memuat periode</span>';
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #dc2626; padding: 32px;">Terjadi kesalahan jaringan saat memuat data.</td></tr>';
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

        tr.innerHTML = `
            <td style="text-align: center; color: #64748b;">${idx + 1}</td>
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
