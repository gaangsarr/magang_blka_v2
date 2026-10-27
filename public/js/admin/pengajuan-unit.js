/**
 * public/js/admin/pengajuan-unit.js
 * Manajemen Pengajuan Kebutuhan Kuota Unit & Usulan Unit Baru Mahasiswa
 */

let currentTab = 'kuota';
let currentPage = 1;
let currentStatus = 'semua';
let currentSearch = '';
let csrfToken = null;
let currentAdminRole = '';
let parentEntitiesList = [];

document.addEventListener('DOMContentLoaded', async () => {
    await initAuthAndCsrf();
    setupEventListeners();
    await loadParentEntities();
    loadPengajuanData(1);
});

async function initAuthAndCsrf() {
    try {
        const res = await fetch('/api/admin/status.php');
        const data = await res.json();
        if (data && data.csrf_token) {
            csrfToken = data.csrf_token;
        }
        if (data && data.admin_role) {
            currentAdminRole = data.admin_role;
        }
    } catch (e) {
        console.warn('Gagal memuat status admin / CSRF:', e);
    }
}

async function loadParentEntities() {
    try {
        const res = await fetch('/api/admin/entitas/list.php?all=1');
        const json = await res.json();
        if (json.ok && json.data) {
            parentEntitiesList = json.data;
            populateParentSelects();
        }
    } catch (e) {
        console.warn('Gagal memuat list entitas parent:', e);
    }
}

function populateParentSelects() {
    const parentSelect = document.getElementById('appr-parent-id');
    const targetSelect = document.getElementById('appr-target-entitas');

    if (parentSelect) {
        parentSelect.innerHTML = '<option value="">Tidak ada (Root / Holding)</option>' +
            parentEntitiesList.map(e => `<option value="${e.id}">${escapeHtml(e.nama)} (${escapeHtml(e.tipe)})</option>`).join('');
    }

    if (targetSelect) {
        targetSelect.innerHTML = '<option value="">Pilih Unit Terdaftar...</option>' +
            parentEntitiesList.map(e => `<option value="${e.id}">${escapeHtml(e.nama)} (${escapeHtml(e.tipe)})</option>`).join('');
    }
}

function setupEventListeners() {
    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentTab = btn.getAttribute('data-tab');
            currentPage = 1;
            loadPengajuanData(1);
        });
    });

    // Search & Filter
    let searchTimer = null;
    document.getElementById('admin-search')?.addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        currentSearch = e.target.value.trim();
        searchTimer = setTimeout(() => loadPengajuanData(1), 350);
    });

    document.getElementById('admin-filter-status')?.addEventListener('change', (e) => {
        currentStatus = e.target.value;
        loadPengajuanData(1);
    });

    // Pagination
    document.getElementById('btn-admin-prev')?.addEventListener('click', () => {
        if (currentPage > 1) loadPengajuanData(currentPage - 1);
    });
    document.getElementById('btn-admin-next')?.addEventListener('click', () => {
        loadPengajuanData(currentPage + 1);
    });

    // Modal Close
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-close');
            document.getElementById(targetId)?.classList.remove('show');
        });
    });

    // Modal Overlay click close
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.classList.remove('show');
        });
    });

    // Mode select on approve modal
    document.getElementById('appr-mode-select')?.addEventListener('change', (e) => {
        const mode = e.target.value;
        document.getElementById('panel-buat-baru').style.display = (mode === 'buat_baru') ? 'block' : 'none';
        document.getElementById('panel-tautkan').style.display = (mode === 'tautkan') ? 'block' : 'none';
    });

    // Submit Approve Usulan
    document.getElementById('form-approve-usulan')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        await submitApproveUsulan();
    });

    // Submit Tolak
    document.getElementById('btn-confirm-tolak')?.addEventListener('click', async () => {
        await submitTolakAction();
    });
}

async function loadPengajuanData(page = 1) {
    currentPage = page;
    const thead = document.getElementById('admin-table-head');
    const tbody = document.getElementById('admin-table-body');

    tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">Memuat data...</td></tr>`;

    // Render table headers based on active tab
    if (currentTab === 'kuota') {
        thead.innerHTML = `
            <tr>
                <th style="width: 28%;">Unit PLN</th>
                <th style="width: 20%;">Peminat &amp; Prodi</th>
                <th style="width: 20%;">Kontak PIC Master</th>
                <th style="width: 14%;">Status</th>
                <th style="width: 18%; text-align: right;">Aksi Tindak Lanjut</th>
            </tr>
        `;
    } else {
        thead.innerHTML = `
            <tr>
                <th style="width: 24%;">Unit Diusulkan</th>
                <th style="width: 22%;">Kontak PIC</th>
                <th style="width: 20%;">Pengusul &amp; Pendukung</th>
                <th style="width: 16%;">Catatan Koordinasi</th>
                <th style="width: 18%; text-align: right;">Aksi</th>
            </tr>
        `;
    }

    try {
        const params = new URLSearchParams({
            tab: currentTab,
            status: currentStatus,
            search: currentSearch,
            page: page
        });

        const res = await fetch('/api/admin/pengajuan_unit/list.php?' + params.toString());
        const json = await res.json();

        if (!json.ok) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 40px; color: #dc2626;">${escapeHtml(json.error || 'Gagal memuat data.')}</td></tr>`;
            return;
        }

        // Update KPI Stats
        updateKpiCounters(json.kpi);

        // Update Tab Badges
        if (json.kpi) {
            document.getElementById('badge-tab-kuota').textContent = json.kpi.kuota_menunggu || 0;
            document.getElementById('badge-tab-usulan').textContent = json.kpi.usulan_menunggu || 0;
        }

        const data = json.data || [];
        if (data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">Tidak ada data pengajuan yang sesuai filter.</td></tr>`;
            document.getElementById('admin-pagination').style.display = 'none';
            return;
        }

        if (currentTab === 'kuota') {
            tbody.innerHTML = data.map(item => renderTabKuotaRow(item)).join('');
        } else {
            tbody.innerHTML = data.map(item => renderTabUsulanRow(item)).join('');
        }

        // Pagination
        const pag = json.pagination;
        if (pag && pag.total_pages > 1) {
            document.getElementById('admin-pagination').style.display = 'flex';
            document.getElementById('admin-pagination-info').textContent = `Halaman ${pag.current_page} dari ${pag.total_pages} (${pag.total_items} data)`;
            document.getElementById('btn-admin-prev').disabled = (pag.current_page <= 1);
            document.getElementById('btn-admin-next').disabled = (pag.current_page >= pag.total_pages);
        } else {
            document.getElementById('admin-pagination').style.display = 'none';
        }

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 40px; color: #dc2626;">Terjadi kesalahan saat memuat data.</td></tr>`;
    }
}

function updateKpiCounters(k) {
    if (!k) return;
    document.getElementById('stat-kuota-menunggu').textContent = k.kuota_menunggu || 0;
    document.getElementById('stat-usulan-menunggu').textContent = k.usulan_menunggu || 0;
    document.getElementById('stat-mhs-count').textContent = k.mhs_aktif_count || 0;
    document.getElementById('stat-disetujui').textContent = k.total_disetujui || 0;
}

// Render Row for Tab 1: Permintaan Kuota
function renderTabKuotaRow(item) {
    const prodiBadges = Object.entries(item.prodi_summary || {}).map(([prodi, cnt]) => `
        <span style="display:inline-block; background:#F1F5F9; border:1px solid #E2E8F0; padding:2px 6px; border-radius:4px; font-size:0.7rem; margin:1px;">
            ${escapeHtml(prodi)}: <strong>${cnt}</strong>
        </span>
    `).join('');

    // PIC Contact with WhatsApp direct button
    let picInfo = '<span style="color:#94a3b8; font-size:0.775rem;">PIC belum terdata</span>';
    if (item.pic_nama || item.pic_kontak) {
        let waBtn = '';
        if (item.pic_kontak) {
            const cleanWa = item.pic_kontak.replace(/\D+/g, '');
            waBtn = `
                <a href="https://wa.me/${cleanWa}?text=Halo%20${encodeURIComponent(item.pic_nama || '')},%20saya%20Admin%20BLKA%20ITPLN%20terkait%20koordinasi%20kuota%20magang" target="_blank" class="btn-wa-chat" style="margin-top:4px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19L7.55 18.83L4.44 19.65L5.27 16.61L5.09 16.31C4.27 15.02 3.81 13.48 3.81 11.91C3.81 7.37 7.5 3.67 12.05 3.67Z"/></svg>
                    <span>Chat WA PIC</span>
                </a>
            `;
        }
        picInfo = `
            <div style="font-weight:600; font-size:0.825rem;">${escapeHtml(item.pic_nama || '-')}</div>
            <div style="font-size:0.75rem; color:#64748b;">${escapeHtml(item.pic_kontak || '-')}</div>
            ${waBtn}
        `;
    }

    // Status pill
    let statusPill = '';
    if (item.count_menunggu > 0) {
        statusPill = `<span class="status-badge badge-wait">${item.count_menunggu} Menunggu</span>`;
    } else if (item.count_diproses > 0) {
        statusPill = `<span class="status-badge badge-proc">${item.count_diproses} Diproses</span>`;
    } else if (item.count_disetujui > 0) {
        statusPill = `<span class="status-badge badge-appr">Kuota Dibuka</span>`;
    } else {
        statusPill = `<span class="status-badge badge-rejt">Ditolak</span>`;
    }

    // Subrow of students
    const studentRows = (item.pemohon_list || []).map(m => {
        let studentAction = '';
        if (m.status === 'menunggu') {
            studentAction = `
                <button type="button" class="btn-table-sm btn-sm-outline" onclick="markSingleStatus(${m.id}, 'diproses')" title="Tandai diproses">Proses</button>
                <button type="button" class="btn-table-sm btn-sm-danger" onclick="openModalTolak(${m.id}, 'single')" title="Tolak">Tolak</button>
            `;
        } else if (m.status === 'diproses') {
            studentAction = `
                <button type="button" class="btn-table-sm btn-sm-outline" style="border-color:#f59e0b; color:#b45309;" onclick="markSingleStatus(${m.id}, 'menunggu')" title="Kembalikan ke status Menunggu jika salah pencet">&#8634; Batal Proses</button>
                <button type="button" class="btn-table-sm btn-sm-danger" onclick="openModalTolak(${m.id}, 'single')" title="Tolak">Tolak</button>
            `;
        } else if (m.status === 'ditolak') {
            studentAction = `
                <button type="button" class="btn-table-sm btn-sm-outline" onclick="markSingleStatus(${m.id}, 'menunggu')" title="Buka kembali pengajuan ini">&#8634; Buka Kembali</button>
            `;
        } else if (m.status === 'disetujui') {
            studentAction = `<span style="font-size:0.75rem; color:#047857; font-weight:700;">✓ Kuota Dibuka</span>`;
        }

        const alasanNote = m.alasan_admin ? `<div style="font-size:0.7rem; color:#dc2626; margin-top:2px;">Catatan: ${escapeHtml(m.alasan_admin)}</div>` : '';

        return `
        <tr>
            <td style="font-weight:600;">${escapeHtml(m.nama)}</td>
            <td><code>${escapeHtml(m.nim)}</code></td>
            <td>${escapeHtml(m.jurusan)}</td>
            <td>
                <span class="status-badge ${m.status === 'disetujui' ? 'badge-appr' : (m.status === 'diproses' ? 'badge-proc' : (m.status === 'ditolak' ? 'badge-rejt' : 'badge-wait'))}">${escapeHtml(m.status)}</span>
                ${alasanNote}
            </td>
            <td style="color:#64748b; font-size:0.725rem; font-variant-numeric:tabular-nums;">${escapeHtml(m.created_at)}</td>
            <td style="text-align:right;">
                <div style="display:flex; justify-content:flex-end; gap:4px; flex-wrap:wrap;">
                    ${studentAction}
                </div>
            </td>
        </tr>
    `;
    }).join('');

    const subrowId = `subrow-kuota-${item.entitas_id}`;

    return `
        <tr>
            <td>
                <div style="font-weight:700; color:#0f172a; font-size:0.9rem;">
                    ${escapeHtml(item.nama)}
                    ${item.singkatan ? `<span style="font-size:0.7rem; background:#f1f5f9; padding:2px 5px; border-radius:4px; border:1px solid #e2e8f0; margin-left:4px;">${escapeHtml(item.singkatan)}</span>` : ''}
                </div>
                <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">${escapeHtml(item.alamat || '-')}</div>
            </td>
            <td>
                <div style="font-weight:700; font-size:0.875rem; margin-bottom:4px; color:#0f172a;">
                    ${item.total_pemohon} Mahasiswa
                </div>
                <div>${prodiBadges}</div>
            </td>
            <td>${picInfo}</td>
            <td>${statusPill}</td>
            <td style="text-align: right;">
                <div style="display:flex; justify-content:flex-end; gap:6px; flex-wrap:wrap;">
                    <button type="button" class="btn-table-sm btn-sm-outline" onclick="toggleSubrow('${subrowId}', this)">
                        Detail Pemohon &darr;
                    </button>
                    <a href="/admin/unit.html" class="btn-table-sm btn-sm-primary" title="Buka Setup Kuota">
                        Atur Kuota
                    </a>
                    ${item.count_menunggu > 0 ? `
                        <button type="button" class="btn-table-sm btn-sm-outline" onclick="markBulkStatus(${item.entitas_id}, 'diproses')" title="Tandai sedang diproses/koordinasi">
                            Proses Semua
                        </button>
                        <button type="button" class="btn-table-sm btn-sm-danger" onclick="openModalTolak(${item.entitas_id}, 'bulk_entitas')" title="Tolak seluruh pengajuan unit ini">
                            Tolak Semua
                        </button>
                    ` : ''}
                    ${item.count_diproses > 0 ? `
                        <button type="button" class="btn-table-sm btn-sm-outline" style="border-color:#f59e0b; color:#b45309;" onclick="markBulkStatus(${item.entitas_id}, 'menunggu')" title="Kembalikan status pemohon ke Menunggu jika salah pencet">
                            &#8634; Batal Proses
                        </button>
                        <button type="button" class="btn-table-sm btn-sm-danger" onclick="openModalTolak(${item.entitas_id}, 'bulk_entitas')" title="Tolak pengajuan unit ini">
                            Tolak
                        </button>
                    ` : ''}
                    ${(item.count_menunggu === 0 && item.count_diproses === 0 && item.count_ditolak > 0) ? `
                        <button type="button" class="btn-table-sm btn-sm-outline" onclick="markBulkStatus(${item.entitas_id}, 'menunggu')" title="Buka kembali pengajuan yang pernah ditolak">
                            &#8634; Buka Kembali
                        </button>
                    ` : ''}
                </div>
            </td>
        </tr>
        <tr id="${subrowId}" class="students-subrow" style="display: none;">
            <td colspan="5" class="students-subrow-card">
                <div style="font-size:0.75rem; font-weight:700; color:#475569; margin-bottom:8px; text-transform:uppercase;">
                    Daftar Mahasiswa Pemohon Kuota:
                </div>
                <table class="subtable-students">
                    <thead>
                        <tr>
                            <th>Nama Mahasiswa</th>
                            <th>NIM</th>
                            <th>Program Studi</th>
                            <th>Status</th>
                            <th>Waktu Pengajuan</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${studentRows}
                    </tbody>
                </table>
            </td>
        </tr>
    `;
}

// Render Row for Tab 2: Usulan Unit Baru
function renderTabUsulanRow(item) {
    const isApproved = (item.status === 'disetujui');
    const isWaiting = (item.status === 'menunggu');

    let statusPill = '';
    if (item.status === 'menunggu') statusPill = '<span class="status-badge badge-wait">Menunggu</span>';
    else if (item.status === 'diproses') statusPill = '<span class="status-badge badge-proc">Diproses</span>';
    else if (item.status === 'disetujui') statusPill = '<span class="status-badge badge-appr">Disetujui</span>';
    else if (item.status === 'ditolak') statusPill = '<span class="status-badge badge-rejt">Ditolak</span>';

    // WA Button
    let waBtn = '';
    if (item.pic_kontak) {
        const cleanWa = item.pic_kontak.replace(/\D+/g, '');
        waBtn = `
            <a href="https://wa.me/${cleanWa}?text=Halo%20${encodeURIComponent(item.pic_nama || '')},%20kami%20dari%20BLKA%20ITPLN%20terkait%20usulan%20magang" target="_blank" class="btn-wa-chat" style="margin-top:4px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19L7.55 18.83L4.44 19.65L5.27 16.61L5.09 16.31C4.27 15.02 3.81 13.48 3.81 11.91C3.81 7.37 7.5 3.67 12.05 3.67Z"/></svg>
                <span>Chat WA PIC</span>
            </a>
        `;
    }

    // Action buttons
    let actionButtons = '';
    if (isWaiting) {
        actionButtons = `
            <button type="button" class="btn-table-sm btn-sm-outline" onclick="markSingleStatus(${item.id}, 'diproses')" title="Tandai sedang diproses/koordinasi PIC">
                Proses
            </button>
            <button type="button" class="btn-table-sm btn-sm-primary" onclick="openModalApproveUsulan(${item.id}, '${escapeJs(item.nama_unit)}', '${escapeJs(item.alamat)}', '${escapeJs(item.pic_nama)}', '${escapeJs(item.pic_kontak)}')">
                Setujui Unit
            </button>
            <button type="button" class="btn-table-sm btn-sm-danger" onclick="openModalTolak(${item.id}, 'single')">
                Tolak
            </button>
        `;
    } else if (item.status === 'diproses') {
        actionButtons = `
            <button type="button" class="btn-table-sm btn-sm-primary" onclick="openModalApproveUsulan(${item.id}, '${escapeJs(item.nama_unit)}', '${escapeJs(item.alamat)}', '${escapeJs(item.pic_nama)}', '${escapeJs(item.pic_kontak)}')">
                Setujui Unit
            </button>
            <button type="button" class="btn-table-sm btn-sm-outline" style="border-color:#f59e0b; color:#b45309;" onclick="markSingleStatus(${item.id}, 'menunggu')" title="Kembalikan ke status Menunggu jika salah pencet">
                &#8634; Batal Proses
            </button>
            <button type="button" class="btn-table-sm btn-sm-danger" onclick="openModalTolak(${item.id}, 'single')">
                Tolak
            </button>
        `;
    } else if (item.status === 'ditolak') {
        actionButtons = `
            <button type="button" class="btn-table-sm btn-sm-outline" onclick="markSingleStatus(${item.id}, 'menunggu')" title="Buka kembali pengajuan ini ke status Menunggu">
                &#8634; Buka Kembali
            </button>
            <button type="button" class="btn-table-sm btn-sm-primary" onclick="openModalApproveUsulan(${item.id}, '${escapeJs(item.nama_unit)}', '${escapeJs(item.alamat)}', '${escapeJs(item.pic_nama)}', '${escapeJs(item.pic_kontak)}')">
                Setujui Unit
            </button>
        `;
    } else if (isApproved && item.entitas_id_hasil) {
        if (!item.has_admin_account && currentAdminRole === 'super_admin') {
            actionButtons = `
                <button type="button" class="btn-table-sm btn-sm-primary" style="background:#0284c7;" onclick="createAdminPerusahaanAccount(${item.entitas_id_hasil}, '${escapeJs(item.nama_unit)}')">
                    Buat Akun Portal
                </button>
            `;
        } else if (item.has_admin_account) {
            actionButtons = `<span style="font-size:0.75rem; color:#047857; font-weight:700;">✓ Akun Aktif (${escapeHtml(item.admin_perusahaan_username)})</span>`;
        }
    }

    return `
        <tr>
            <td>
                <div style="font-weight:700; color:#0f172a; font-size:0.9rem;">${escapeHtml(item.nama_unit)}</div>
                <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">${escapeHtml(item.alamat || '-')}</div>
                <div style="margin-top:6px;">${statusPill}</div>
            </td>
            <td>
                <div style="font-weight:600; font-size:0.825rem;">${escapeHtml(item.pic_nama || '-')}</div>
                <div style="font-size:0.75rem; color:#64748b;">${escapeHtml(item.pic_kontak || '-')}</div>
                ${item.pic_email ? `<div style="font-size:0.725rem; color:#0284c7;">${escapeHtml(item.pic_email)}</div>` : ''}
                ${waBtn}
            </td>
            <td>
                <div style="font-weight:700; font-size:0.825rem;">
                    ${escapeHtml(item.pengusul.nama)} (${escapeHtml(item.pengusul.nim)})
                </div>
                <div style="font-size:0.75rem; color:#64748b;">${escapeHtml(item.pengusul.jurusan)}</div>
                <div style="font-size:0.725rem; color:#0d9488; font-weight:600; margin-top:4px;">
                    Didukung ${item.jumlah_pendukung} Mahasiswa
                </div>
            </td>
            <td>
                <div style="font-size:0.775rem; color:#334155; line-height:1.4; max-height:80px; overflow-y:auto; background:#f8fafc; padding:6px 8px; border-radius:6px; border:1px solid #e2e8f0;">
                    ${escapeHtml(item.catatan || '-')}
                </div>
            </td>
            <td style="text-align: right;">
                <div style="display:flex; justify-content:flex-end; gap:6px; flex-wrap:wrap;">
                    ${actionButtons}
                </div>
            </td>
        </tr>
    `;
}

window.toggleSubrow = function(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    const isHidden = (el.style.display === 'none' || getComputedStyle(el).display === 'none');
    el.style.display = isHidden ? 'table-row' : 'none';
    if (btn) {
        btn.innerHTML = isHidden ? 'Tutup Pemohon &uarr;' : 'Detail Pemohon &darr;';
    }
};

// -----------------------------------------------------------------
// Actions: Update Status (Diproses / Tolak / Revert Menunggu)
// -----------------------------------------------------------------
window.markSingleStatus = async function(id, status) {
    let actionText = `menandai pengajuan ini '${status}'`;
    if (status === 'menunggu') actionText = 'mengembalikan pengajuan ini ke status Menunggu (batal proses)';
    else if (status === 'diproses') actionText = 'menandai pengajuan ini sedang Diproses';

    if (!confirm(`Apakah Anda yakin ingin ${actionText}?`)) return;

    try {
        const res = await fetch('/api/admin/pengajuan_unit/update_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                mode: 'single',
                id: id,
                status: status
            })
        });
        const data = await res.json();
        if (data.ok) {
            alert(data.message || 'Status berhasil diperbarui.');
            loadPengajuanData(currentPage);
        } else {
            alert(data.error || 'Gagal memperbarui status.');
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
    }
};

window.markBulkStatus = async function(entitasId, status) {
    let actionText = `seluruh pengajuan kuota unit ini menjadi '${status}'`;
    if (status === 'menunggu') actionText = 'mengembalikan seluruh pemohon unit ini ke status Menunggu (batal proses)';
    else if (status === 'diproses') actionText = 'menandai seluruh pemohon unit ini sedang Diproses';

    if (!confirm(`Apakah Anda yakin ingin ${actionText}?`)) return;

    try {
        const res = await fetch('/api/admin/pengajuan_unit/update_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                mode: 'bulk_entitas',
                entitas_id: entitasId,
                status: status
            })
        });
        const data = await res.json();
        if (data.ok) {
            alert(data.message || 'Status berhasil diperbarui.');
            loadPengajuanData(currentPage);
        } else {
            alert(data.error || 'Gagal memperbarui status.');
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
    }
};

window.openModalTolak = function(targetId, mode) {
    document.getElementById('tolak-target-id').value = targetId;
    document.getElementById('tolak-target-mode').value = mode;
    document.getElementById('tolak-alasan').value = '';
    document.getElementById('modal-tolak').classList.add('show');
};

async function submitTolakAction() {
    const targetId = parseInt(document.getElementById('tolak-target-id').value, 10);
    const mode = document.getElementById('tolak-target-mode').value;
    const alasan = document.getElementById('tolak-alasan').value.trim();

    if (!alasan) {
        alert('Alasan penolakan wajib diisi.');
        return;
    }

    const payload = {
        mode: mode,
        status: 'ditolak',
        alasan_admin: alasan
    };

    if (mode === 'bulk_entitas') {
        payload.entitas_id = targetId;
    } else {
        payload.id = targetId;
    }

    try {
        const res = await fetch('/api/admin/pengajuan_unit/update_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.ok) {
            alert(data.message || 'Pengajuan berhasil ditolak.');
            document.getElementById('modal-tolak').classList.remove('show');
            loadPengajuanData(currentPage);
        } else {
            alert(data.error || 'Gagal menolak pengajuan.');
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
    }
}

// -----------------------------------------------------------------
// Actions: Approve Usulan Unit Baru
// -----------------------------------------------------------------
window.openModalApproveUsulan = function(id, nama, alamat, picNama, picWa) {
    document.getElementById('appr-usulan-id').value = id;
    document.getElementById('appr-nama-unit').value = nama || '';
    document.getElementById('appr-alamat').value = alamat || '';
    document.getElementById('appr-pic-nama').value = picNama || '';
    document.getElementById('appr-pic-wa').value = picWa || '';
    document.getElementById('appr-singkatan').value = '';

    document.getElementById('appr-mode-select').value = 'buat_baru';
    document.getElementById('panel-buat-baru').style.display = 'block';
    document.getElementById('panel-tautkan').style.display = 'none';

    document.getElementById('modal-approve-usulan').classList.add('show');
};

async function submitApproveUsulan() {
    const id = parseInt(document.getElementById('appr-usulan-id').value, 10);
    const mode = document.getElementById('appr-mode-select').value;
    const btn = document.getElementById('btn-submit-approve');

    const payload = {
        id: id,
        mode: mode
    };

    if (mode === 'buat_baru') {
        payload.nama = document.getElementById('appr-nama-unit').value.trim();
        payload.singkatan = document.getElementById('appr-singkatan').value.trim();
        payload.tipe = document.getElementById('appr-tipe').value;
        payload.parent_id = document.getElementById('appr-parent-id').value || null;
        payload.alamat = document.getElementById('appr-alamat').value.trim();
        payload.pic_nama = document.getElementById('appr-pic-nama').value.trim();
        payload.pic_kontak = document.getElementById('appr-pic-wa').value.trim();

        if (!payload.nama || !payload.tipe) {
            alert('Nama resmi unit dan tipe hierarki wajib diisi.');
            return;
        }
    } else {
        payload.entitas_id = parseInt(document.getElementById('appr-target-entitas').value, 10);
        if (!payload.entitas_id) {
            alert('Pilih unit terdaftar yang ditautkan.');
            return;
        }
    }

    btn.disabled = true;
    btn.textContent = 'Memproses...';

    try {
        const res = await fetch('/api/admin/pengajuan_unit/approve_usulan.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.ok) {
            alert(data.message || 'Usulan unit berhasil disetujui.');
            document.getElementById('modal-approve-usulan').classList.remove('show');
            loadPengajuanData(currentPage);
        } else {
            alert(data.error || 'Gagal menyetujui usulan unit.');
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Setujui & Simpan';
    }
}

// -----------------------------------------------------------------
// Actions: Buat Akun Admin Perusahaan (Super Admin Only)
// -----------------------------------------------------------------
window.createAdminPerusahaanAccount = async function(entitasId, unitNama) {
    if (!confirm(`Buat akun admin mitra portal untuk entitas '${unitNama}'?`)) return;

    try {
        const res = await fetch('/api/admin/kelola/perusahaan_accounts.php?action=create_single', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ entitas_id: entitasId })
        });
        const data = await res.json();
        if (data.ok) {
            alert(`Akun Berhasil Dibuat!\n\nUsername: ${data.username}\nPassword Sementara: ${data.temp_password}\n\nSilakan catat kredensial ini.`);
            loadPengajuanData(currentPage);
        } else {
            alert(data.error || 'Gagal membuat akun admin perusahaan.');
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
    }
};

// Utilities
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeJs(str) {
    if (!str) return '';
    return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
}
