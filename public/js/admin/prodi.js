/**
 * public/js/admin/prodi.js
 * Logic for INTERN ITPLN Admin Program Studi (Prodi) Portal
 */

(function () {
    'use strict';

    // State
    const state = {
        admin: null,
        csrfToken: '',
        selectedPeriodeId: null,
        allPeriode: [],
        currentTab: 'tab-dashboard',
        mhs: {
            page: 1,
            perPage: 25,
            totalPages: 1,
            totalRecords: 0,
            search: '',
            status: '',
            program: '',
            debounceTimer: null
        },
        formasi: {
            rawList: [],
            search: '',
            tipe: '',
            debounceTimer: null
        }
    };

    // Elements
    const el = {
        headerProdiNama: document.getElementById('header-prodi-nama'),
        headerProdiJenjang: document.getElementById('header-prodi-jenjang'),
        heroProdiTitle: document.getElementById('hero-prodi-title'),
        heroProdiSubtitle: document.getElementById('hero-prodi-subtitle'),
        selectPeriode: document.getElementById('select-periode'),
        badgeStatusPeriode: document.getElementById('badge-status-periode'),
        btnExportExcel: document.getElementById('btn-export-excel'),
        counterTabMhs: document.getElementById('counter-tab-mhs'),
        counterTabFormasi: document.getElementById('counter-tab-formasi'),
        counterTabPeminatan: document.getElementById('counter-tab-peminatan'),
        
        // Tab buttons
        tabBtns: document.querySelectorAll('.prodi-tab-btn'),
        tabPanes: document.querySelectorAll('.tab-pane'),
        btnSeeAllMhs: document.getElementById('btn-see-all-mhs'),

        // Dashboard elements
        kpiTotal: document.getElementById('kpi-total-pendaftar'),
        kpiDiterima: document.getElementById('kpi-diterima'),
        kpiDiproses: document.getElementById('kpi-diproses'),
        kpiCadangan: document.getElementById('kpi-cadangan'),
        kpiDitolak: document.getElementById('kpi-ditolak'),
        listProgram: document.getElementById('list-program-breakdown'),
        listTopUnits: document.getElementById('list-top-units'),
        listPeminatanStat: document.getElementById('list-peminatan-stat'),
        listRecent: document.getElementById('list-recent-pendaftar'),

        // Mahasiswa Tab elements
        mhsSearch: document.getElementById('mhs-search'),
        mhsFilterStatus: document.getElementById('mhs-filter-status'),
        mhsFilterProgram: document.getElementById('mhs-filter-program'),
        btnResetFilter: document.getElementById('btn-reset-filter'),
        mhsTotalCount: document.getElementById('mhs-total-count'),
        tableMhsBody: document.getElementById('table-mhs-body'),
        mhsPaginationInfo: document.getElementById('mhs-pagination-info'),
        btnMhsPrev: document.getElementById('btn-mhs-prev'),
        btnMhsNext: document.getElementById('btn-mhs-next'),
        mhsPageCurrent: document.getElementById('mhs-page-current'),

        // Formasi Tab elements
        gridPeminatan: document.getElementById('grid-peminatan'),
        tableFormasiBody: document.getElementById('table-formasi-body'),
        formasiSearch: document.getElementById('formasi-search'),
        formasiFilterTipe: document.getElementById('formasi-filter-tipe'),
        btnResetFormasi: document.getElementById('btn-reset-formasi'),
        formasiCount: document.getElementById('formasi-count'),

        // Detail Modal elements
        modalDetail: document.getElementById('modal-detail-mhs'),
        btnCloseDetail: document.getElementById('btn-close-detail'),
        btnCloseDetailFooter: document.getElementById('btn-close-detail-footer'),
        detailModalName: document.getElementById('detail-modal-name'),
        detailModalNim: document.getElementById('detail-modal-nim'),
        detailStatusBadge: document.getElementById('detail-status-badge'),
        detailSubmittedAt: document.getElementById('detail-submitted-at'),
        detailCatatanBox: document.getElementById('detail-catatan-admin-box'),
        detailCatatanAdmin: document.getElementById('detail-catatan-admin'),
        detailNama: document.getElementById('detail-nama'),
        detailNim: document.getElementById('detail-nim'),
        detailJk: document.getElementById('detail-jk'),
        detailAngkatan: document.getElementById('detail-angkatan'),
        detailIpk: document.getElementById('detail-ipk'),
        detailSks: document.getElementById('detail-sks'),
        detailEmail: document.getElementById('detail-email'),
        detailNohp: document.getElementById('detail-nohp'),
        detailUnit: document.getElementById('detail-unit'),
        detailProgram: document.getElementById('detail-program'),
        detailUnitAlamat: document.getElementById('detail-unit-alamat'),
        detailPeminatanChips: document.getElementById('detail-peminatan-chips'),
        detailAlamat: document.getElementById('detail-alamat'),
        detailKota: document.getElementById('detail-kota'),
        detailProvinsi: document.getElementById('detail-provinsi'),
        linkCv: document.getElementById('link-cv'),
        linkTranskrip: document.getElementById('link-transkrip'),
        linkPorto: document.getElementById('link-porto'),
        noDocsNotice: document.getElementById('no-docs-notice'),

        // Password Modal elements
        modalPassword: document.getElementById('modal-password'),
        btnOpenPassword: document.getElementById('btn-open-password'),
        btnClosePassword: document.getElementById('btn-close-password'),
        btnCancelPassword: document.getElementById('btn-cancel-password'),
        formPassword: document.getElementById('form-change-password'),
        inputCurrentPass: document.getElementById('input-current-pass'),
        inputNewPass: document.getElementById('input-new-pass'),
        inputConfirmPass: document.getElementById('input-confirm-pass'),
        btnSubmitPassword: document.getElementById('btn-submit-password'),

        // Logout & Toast
        btnLogout: document.getElementById('btn-logout'),
        toast: document.getElementById('prodi-toast'),
        toastMsg: document.getElementById('toast-message')
    };

    // Helper: Escape HTML
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Helper: Show Toast
    function showToast(message, type = 'success') {
        el.toastMsg.textContent = message;
        el.toast.className = `prodi-toast ${type} show`;
        setTimeout(() => {
            el.toast.className = 'prodi-toast';
        }, 3500);
    }

    // Helper: Status Badge HTML
    function getStatusBadge(status) {
        const s = (status || 'menunggu').toLowerCase();
        let cls = 'status-badge-diproses';
        let label = 'DALAM PROSES';

        if (s === 'diterima') {
            cls = 'status-badge-diterima';
            label = 'LOLOS (DITERIMA)';
        } else if (s === 'ditolak') {
            cls = 'status-badge-ditolak';
            label = 'TIDAK LOLOS';
        } else if (s === 'cadangan') {
            cls = 'status-badge-cadangan';
            label = 'CADANGAN';
        } else if (s === 'dipindahkan') {
            cls = 'status-badge-dipindahkan';
            label = 'DIPINDAHKAN';
        }

        return `<span class="status-badge ${cls}">${label}</span>`;
    }

    // Helper: Format Date
    function formatDate(dateStr) {
        if (!dateStr) return '-';
        try {
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            return d.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }) + ' WIB';
        } catch (_) {
            return dateStr;
        }
    }

    // Helper: Format Durasi Program Magang (e.g. 1_bulan -> 1 Bulan)
    function formatProgram(prog) {
        if (!prog) return '-';
        const p = String(prog).toLowerCase().trim();
        if (p === '1_bulan' || p === '1 bulan') return '1 Bulan';
        if (p === '3_bulan' || p === '3 bulan') return '3 Bulan';
        if (p === '4_bulan' || p === '4 bulan') return '4 Bulan';
        if (p === '5_bulan' || p === '5 bulan') return '5 Bulan';
        if (p === '6_bulan' || p === '6 bulan') return '6 Bulan';
        return String(prog).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    // Helper: Update Period Status Pill
    function updatePeriodeStatusBadge(status) {
        const s = (status || '').toLowerCase();
        let text = 'Tidak Aktif';
        let cls = 'badge-status-ditutup';

        if (s === 'dibuka') {
            text = 'Periode Dibuka';
            cls = 'badge-status-dibuka';
        } else if (s === 'persiapan') {
            text = 'Persiapan Seleksi';
            cls = 'badge-status-persiapan';
        }

        el.badgeStatusPeriode.className = `badge-status-pill ${cls}`;
        el.badgeStatusPeriode.textContent = text;
    }

    // Initialize: Check Auth & Profile
    async function init() {
        try {
            // Ambil session CSRF
            const statusRes = await fetch('/api/admin/status.php');
            const statusData = await statusRes.json();
            if (statusData && statusData.csrf_token) {
                state.csrfToken = statusData.csrf_token;
            }

            // Ambil profile admin
            const meRes = await fetch('/api/admin/me.php');
            if (meRes.status === 401 || meRes.status === 403) {
                window.location.href = '/admin/login.html';
                return;
            }
            const meData = await meRes.json();
            if (!meData.ok || !meData.admin) {
                window.location.href = '/admin/login.html';
                return;
            }

            state.admin = meData.admin;

            // Jika bukan admin_prodi (kecuali super_admin yang mungkin sedang review), redirect
            if (state.admin.role !== 'admin_prodi' && !state.admin.is_super) {
                if (state.admin.role === 'admin_perusahaan') {
                    window.location.href = '/perusahaan/index.html';
                } else {
                    window.location.href = '/admin/index.html';
                }
                return;
            }

            // Update UI Profil Prodi
            const prodi = state.admin.jurusan;
            if (prodi) {
                el.headerProdiJenjang.textContent = prodi.jenjang || 'S1';
                el.headerProdiNama.textContent = prodi.nama || 'Program Studi';
                el.heroProdiTitle.textContent = `Monitoring Magang — ${prodi.jenjang || 'S1'} ${prodi.nama}`;
                el.heroProdiSubtitle.textContent = `Kode Program Studi: ${prodi.kode || '-'} | Institut Teknologi PLN`;
                document.title = `Portal ${prodi.jenjang || 'S1'} ${prodi.nama} — INTERN ITPLN`;
            } else {
                el.headerProdiJenjang.textContent = 'PRODI';
                el.headerProdiNama.textContent = state.admin.nama;
            }

            // Bind Event Listeners
            bindEvents();

            // Load initial dashboard data
            await loadDashboardData();

        } catch (err) {
            console.error('Initialization error:', err);
            showToast('Gagal memuat sesi akun. Silakan refresh halaman.', 'error');
        }
    }

    // Bind Event Listeners
    function bindEvents() {
        // Tab switching
        el.tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetTab = btn.getAttribute('data-tab');
                switchTab(targetTab);
            });
        });

        // "Lihat Semua" button on dashboard
        if (el.btnSeeAllMhs) {
            el.btnSeeAllMhs.addEventListener('click', () => {
                switchTab('tab-mahasiswa');
            });
        }

        // Period dropdown change
        el.selectPeriode.addEventListener('change', () => {
            state.selectedPeriodeId = el.selectPeriode.value ? parseInt(el.selectPeriode.value, 10) : null;
            // Update period status badge from cached allPeriode
            const curP = state.allPeriode.find(p => p.id == state.selectedPeriodeId);
            if (curP) {
                updatePeriodeStatusBadge(curP.status);
            }
            refreshCurrentTab();
        });

        // Export Excel button
        el.btnExportExcel.addEventListener('click', () => {
            const pid = state.selectedPeriodeId || '';
            const status = el.mhsFilterStatus.value || '';
            const prog = el.mhsFilterProgram.value || '';
            const url = `/api/prodi/mahasiswa/export.php?periode_id=${pid}&status=${encodeURIComponent(status)}&program=${encodeURIComponent(prog)}`;
            window.location.href = url;
        });

        // Mahasiswa filters
        el.mhsSearch.addEventListener('input', () => {
            clearTimeout(state.mhs.debounceTimer);
            state.mhs.debounceTimer = setTimeout(() => {
                state.mhs.search = el.mhsSearch.value.trim();
                state.mhs.page = 1;
                loadMahasiswaData();
            }, 300);
        });

        el.mhsFilterStatus.addEventListener('change', () => {
            state.mhs.status = el.mhsFilterStatus.value;
            state.mhs.page = 1;
            loadMahasiswaData();
        });

        el.mhsFilterProgram.addEventListener('change', () => {
            state.mhs.program = el.mhsFilterProgram.value;
            state.mhs.page = 1;
            loadMahasiswaData();
        });

        el.btnResetFilter.addEventListener('click', () => {
            el.mhsSearch.value = '';
            el.mhsFilterStatus.value = '';
            el.mhsFilterProgram.value = '';
            state.mhs.search = '';
            state.mhs.status = '';
            state.mhs.program = '';
            state.mhs.page = 1;
            loadMahasiswaData();
        });

        // Formasi filters
        if (el.formasiSearch) {
            el.formasiSearch.addEventListener('input', () => {
                clearTimeout(state.formasi.debounceTimer);
                state.formasi.debounceTimer = setTimeout(() => {
                    state.formasi.search = el.formasiSearch.value.trim().toLowerCase();
                    filterAndRenderFormasi();
                }, 200);
            });
        }

        if (el.formasiFilterTipe) {
            el.formasiFilterTipe.addEventListener('change', () => {
                state.formasi.tipe = el.formasiFilterTipe.value;
                filterAndRenderFormasi();
            });
        }

        if (el.btnResetFormasi) {
            el.btnResetFormasi.addEventListener('click', () => {
                if (el.formasiSearch) el.formasiSearch.value = '';
                if (el.formasiFilterTipe) el.formasiFilterTipe.value = '';
                state.formasi.search = '';
                state.formasi.tipe = '';
                filterAndRenderFormasi();
            });
        }

        // Pagination
        el.btnMhsPrev.addEventListener('click', () => {
            if (state.mhs.page > 1) {
                state.mhs.page--;
                loadMahasiswaData();
            }
        });

        el.btnMhsNext.addEventListener('click', () => {
            if (state.mhs.page < state.mhs.totalPages) {
                state.mhs.page++;
                loadMahasiswaData();
            }
        });

        // Modal Detail Close
        el.btnCloseDetail.addEventListener('click', closeDetailModal);
        el.btnCloseDetailFooter.addEventListener('click', closeDetailModal);
        el.modalDetail.addEventListener('click', (e) => {
            if (e.target === el.modalDetail) closeDetailModal();
        });

        // Modal Password Open & Close
        el.btnOpenPassword.addEventListener('click', openPasswordModal);
        el.btnClosePassword.addEventListener('click', closePasswordModal);
        el.btnCancelPassword.addEventListener('click', closePasswordModal);
        el.modalPassword.addEventListener('click', (e) => {
            if (e.target === el.modalPassword) closePasswordModal();
        });

        // Form Change Password Submit
        el.formPassword.addEventListener('submit', handlePasswordChange);

        // Logout
        el.btnLogout.addEventListener('click', handleLogout);
    }

    // Switch Tab
    function switchTab(tabId) {
        state.currentTab = tabId;

        el.tabBtns.forEach(btn => {
            const isMatch = btn.getAttribute('data-tab') === tabId;
            btn.classList.toggle('active', isMatch);
            btn.setAttribute('aria-selected', isMatch ? 'true' : 'false');
        });

        el.tabPanes.forEach(pane => {
            pane.classList.toggle('active', pane.id === tabId);
        });

        refreshCurrentTab();
    }

    function refreshCurrentTab() {
        if (state.currentTab === 'tab-dashboard') {
            loadDashboardData();
        } else if (state.currentTab === 'tab-mahasiswa') {
            loadMahasiswaData();
        } else if (state.currentTab === 'tab-formasi') {
            loadFormasiData();
        } else if (state.currentTab === 'tab-peminatan') {
            loadPeminatanData();
        }
    }

    // Populate Period Select options
    function renderPeriodeOptions(allPeriode, selectedId) {
        state.allPeriode = allPeriode || [];
        el.selectPeriode.innerHTML = '';

        if (!allPeriode || allPeriode.length === 0) {
            el.selectPeriode.innerHTML = '<option value="">Belum Ada Periode</option>';
            return;
        }

        allPeriode.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            let statusLabel = p.status ? ` (${p.status.toUpperCase()})` : '';
            opt.textContent = `${p.nama}${statusLabel}`;
            if (p.id == selectedId) {
                opt.selected = true;
            }
            el.selectPeriode.appendChild(opt);
        });
    }

    // ========================================================
    // LOAD DASHBOARD DATA
    // ========================================================
    async function loadDashboardData() {
        try {
            const pidQuery = state.selectedPeriodeId ? `?periode_id=${state.selectedPeriodeId}` : '';
            const res = await fetch(`/api/prodi/dashboard.php${pidQuery}`);
            const data = await res.json();

            if (!data.ok) {
                showToast(data.error || 'Gagal memuat dashboard.', 'error');
                return;
            }

            // Sync Period
            if (data.periode_terpilih) {
                state.selectedPeriodeId = parseInt(data.periode_terpilih.id, 10);
                updatePeriodeStatusBadge(data.periode_terpilih.status);
            }
            if (data.all_periode && el.selectPeriode.children.length <= 1) {
                renderPeriodeOptions(data.all_periode, state.selectedPeriodeId);
            }

            // KPIs
            const s = data.stats || {};
            el.kpiTotal.textContent = (s.total_pendaftar || 0).toLocaleString('id-ID');
            el.kpiDiterima.textContent = (s.diterima || 0).toLocaleString('id-ID');
            el.kpiDiproses.textContent = (s.diproses || 0).toLocaleString('id-ID');
            el.kpiCadangan.textContent = (s.cadangan || 0) + (s.dipindahkan || 0);
            el.kpiDitolak.textContent = (s.ditolak || 0).toLocaleString('id-ID');
            el.counterTabMhs.textContent = (s.total_pendaftar || 0).toLocaleString('id-ID');

            // 1. Program Breakdown
            renderProgramBreakdown(data.breakdown_program || [], s.total_pendaftar || 0);

            // 2. Top Units
            renderTopUnits(data.top_units || [], s.total_pendaftar || 0);

            // 3. Peminatan Stat
            renderPeminatanStat(data.peminatan_stat || [], s.total_pendaftar || 0);

            // 4. Recent Pendaftar
            renderRecentPendaftar(data.recent_pendaftar || []);

            // Prefetch counters for Formasi and Peminatan tabs
            fetch(`/api/prodi/peminatan/list.php${pidQuery}`)
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        if (el.counterTabFormasi && d.formasi_unit) {
                            el.counterTabFormasi.style.display = 'inline-block';
                            el.counterTabFormasi.textContent = d.formasi_unit.length.toLocaleString('id-ID');
                        }
                        if (el.counterTabPeminatan && d.peminatan_list) {
                            el.counterTabPeminatan.style.display = 'inline-block';
                            el.counterTabPeminatan.textContent = d.peminatan_list.length.toLocaleString('id-ID');
                        }
                    }
                })
                .catch(() => {});

        } catch (err) {
            console.error('Error loading dashboard data:', err);
            showToast('Terjadi kesalahan jaringan saat memuat dashboard.', 'error');
        }
    }

    function renderProgramBreakdown(list, totalAll) {
        if (!list || list.length === 0) {
            el.listProgram.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.8125rem;">Belum ada data pendaftar pada periode ini.</div>';
            return;
        }

        let html = '';
        list.forEach(item => {
            const cnt = parseInt(item.total, 10) || 0;
            const pct = totalAll > 0 ? Math.round((cnt / totalAll) * 100) : 0;
            html += `
                <div class="stat-bar-item">
                    <div class="stat-bar-header">
                        <span>${esc(formatProgram(item.nama_program))}</span>
                        <span><strong>${cnt}</strong> mhs (${pct}%)</span>
                    </div>
                    <div class="stat-bar-track">
                        <div class="stat-bar-fill" style="width: ${pct}%;"></div>
                    </div>
                </div>
            `;
        });
        el.listProgram.innerHTML = html;
    }

    function renderTopUnits(list, totalAll) {
        if (!list || list.length === 0) {
            el.listTopUnits.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.8125rem;">Belum ada pendaftaran unit pada periode ini.</div>';
            return;
        }

        let html = '';
        list.forEach(u => {
            const cnt = parseInt(u.total_pelamar, 10) || 0;
            const dit = parseInt(u.total_diterima, 10) || 0;
            const pct = totalAll > 0 ? Math.round((cnt / totalAll) * 100) : 0;
            const unitName = u.singkatan ? `${u.nama || u.unit_nama} (${u.singkatan})` : (u.nama || u.unit_nama);

            html += `
                <div class="stat-bar-item">
                    <div class="stat-bar-header">
                        <span style="font-weight: 700; color: var(--color-brand-blue);">${esc(unitName)}</span>
                        <span><strong>${cnt}</strong> pelamar | <span style="color: #065F46; font-weight: 700;">${dit} diterima</span></span>
                    </div>
                    <div class="stat-bar-track">
                        <div class="stat-bar-fill" style="width: ${pct}%; background-color: var(--color-brand-blue);"></div>
                    </div>
                </div>
            `;
        });
        el.listTopUnits.innerHTML = html;
    }

    function renderPeminatanStat(list, totalAll) {
        if (!list || list.length === 0) {
            el.listPeminatanStat.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.8125rem;">Belum ada pilihan peminatan pada periode ini.</div>';
            return;
        }

        let html = '';
        list.forEach(p => {
            const cnt = parseInt(p.total_peminat, 10) || 0;
            const pct = totalAll > 0 ? Math.min(100, Math.round((cnt / totalAll) * 100)) : 0;
            html += `
                <div class="stat-bar-item">
                    <div class="stat-bar-header">
                        <span>${esc(p.nama_peminatan)}</span>
                        <span><strong>${cnt}</strong> mahasiswa</span>
                    </div>
                    <div class="stat-bar-track">
                        <div class="stat-bar-fill" style="width: ${pct}%; background-color: #0284C7;"></div>
                    </div>
                </div>
            `;
        });
        el.listPeminatanStat.innerHTML = html;
    }

    function renderRecentPendaftar(list) {
        if (!list || list.length === 0) {
            el.listRecent.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.8125rem;">Belum ada data pendaftar terbaru.</div>';
            return;
        }

        let html = '';
        list.forEach(m => {
            html += `
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--color-border-subtle); gap: 10px;">
                    <div>
                        <div style="font-weight: 700; color: var(--color-text-main); font-size: 0.875rem;">${esc(m.nama)}</div>
                        <div style="font-size: 0.75rem; color: var(--color-text-muted);">
                            NIM: <span class="cell-mono">${esc(m.nim)}</span> &bull; ${esc(m.unit_nama)}
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        ${getStatusBadge(m.status)}
                        <button type="button" class="btn-table-action btn-quick-detail" data-id="${m.id}" title="Lihat Profil Lengkap">Detail</button>
                    </div>
                </div>
            `;
        });
        el.listRecent.innerHTML = html;

        // Bind quick detail buttons
        el.listRecent.querySelectorAll('.btn-quick-detail').forEach(b => {
            b.addEventListener('click', () => {
                const id = b.getAttribute('data-id');
                openDetailModal(id);
            });
        });
    }

    // ========================================================
    // LOAD MAHASISWA TAB DATA
    // ========================================================
    async function loadMahasiswaData() {
        el.tableMhsBody.innerHTML = `
            <tr>
                <td colspan="11" style="text-align: center; padding: 32px; color: var(--color-text-muted);">
                    Memuat data pendaftar mahasiswa...
                </td>
            </tr>
        `;

        try {
            const params = new URLSearchParams({
                periode_id: state.selectedPeriodeId || '',
                page: state.mhs.page,
                per_page: state.mhs.perPage,
                search: state.mhs.search,
                status: state.mhs.status,
                program: state.mhs.program
            });

            const res = await fetch(`/api/prodi/mahasiswa/list.php?${params.toString()}`);
            const data = await res.json();

            if (!data.ok) {
                showToast(data.error || 'Gagal memuat daftar mahasiswa.', 'error');
                return;
            }

            state.mhs.totalRecords = data.pagination.total;
            state.mhs.totalPages = data.pagination.total_pages;
            state.mhs.page = data.pagination.page;

            el.mhsTotalCount.textContent = state.mhs.totalRecords.toLocaleString('id-ID');
            el.mhsPageCurrent.textContent = `${state.mhs.page} / ${state.mhs.totalPages || 1}`;
            el.btnMhsPrev.disabled = (state.mhs.page <= 1);
            el.btnMhsNext.disabled = (state.mhs.page >= state.mhs.totalPages);

            const startIdx = state.mhs.totalRecords > 0 ? (state.mhs.page - 1) * state.mhs.perPage + 1 : 0;
            const endIdx = Math.min(state.mhs.page * state.mhs.perPage, state.mhs.totalRecords);
            el.mhsPaginationInfo.textContent = `Menampilkan ${startIdx} - ${endIdx} dari ${state.mhs.totalRecords} data`;

            renderMahasiswaTable(data.data || [], startIdx);

        } catch (err) {
            console.error('Error loading mahasiswa data:', err);
            el.tableMhsBody.innerHTML = `
                <tr>
                    <td colspan="11" style="text-align: center; padding: 24px; color: #991B1B;">
                        Terjadi kesalahan jaringan saat memuat pendaftar.
                    </td>
                </tr>
            `;
        }
    }

    function renderMahasiswaTable(rows, startNo) {
        if (!rows || rows.length === 0) {
            el.tableMhsBody.innerHTML = `
                <tr>
                    <td colspan="11" style="text-align: center; padding: 36px; color: var(--color-text-muted);">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 8px; opacity: 0.5;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <div>Tidak ada data mahasiswa yang sesuai dengan filter pencarian.</div>
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        let no = startNo;
        rows.forEach(r => {
            const jkBadge = (r.jenis_kelamin === 'L' || r.jenis_kelamin === 'Laki-laki') ? 'L' : 'P';
            const unitName = r.unit_singkatan ? `${esc(r.unit_nama)} (${esc(r.unit_singkatan)})` : esc(r.unit_nama);

            let docsHtml = '<div style="display: flex; gap: 4px; justify-content: center; flex-wrap: wrap;">';
            if (r.cv_path) {
                docsHtml += `<a href="/api/admin/cv/download.php?pendaftaran_id=${r.id}" target="_blank" class="btn-doc-link" title="Unduh / Lihat CV">CV</a>`;
            }
            if (r.transkrip_path) {
                docsHtml += `<a href="/api/admin/transkrip/download.php?pendaftaran_id=${r.id}" target="_blank" class="btn-doc-link" title="Unduh / Lihat Transkrip Nilai">Trx</a>`;
            }
            if (r.porto_path) {
                const isPortoLink = typeof r.porto_path === 'string' && (r.porto_path.startsWith('http://') || r.porto_path.startsWith('https://'));
                const portoUrl = isPortoLink ? r.porto_path : `/api/admin/porto/download.php?pendaftaran_id=${r.id}`;
                docsHtml += `<a href="${esc(portoUrl)}" target="_blank" rel="noopener noreferrer" class="btn-doc-link" title="${isPortoLink ? 'Buka Link Portofolio: ' + esc(r.porto_path) : 'Unduh / Lihat Portofolio (PDF)'}">Porto</a>`;
            }
            if (!r.cv_path && !r.transkrip_path && !r.porto_path) {
                docsHtml += `<span style="color: var(--color-text-light); font-size: 0.75rem;">-</span>`;
            }
            docsHtml += '</div>';

            html += `
                <tr>
                    <td style="text-align: center;" class="cell-mono">${no++}</td>
                    <td>
                        <div style="font-weight: 700; color: var(--color-text-main); font-size: 0.875rem;">${esc(r.nama)}</div>
                        <div style="font-size: 0.75rem; color: var(--color-text-muted); display: flex; gap: 6px; align-items: center; margin-top: 2px;">
                            <span class="cell-mono font-bold" style="color: var(--color-brand-blue);">${esc(r.nim)}</span>
                            <span>&bull;</span>
                            <span>${esc(r.email)}</span>
                        </div>
                    </td>
                    <td style="text-align: center;"><span style="font-weight: 700; color: ${jkBadge === 'L' ? '#0369A1' : '#BE185D'};">${jkBadge}</span></td>
                    <td style="text-align: center;" class="cell-mono">${esc(r.angkatan || '-')}</td>
                    <td style="text-align: center;">
                        <span class="cell-mono font-bold" style="color: #065F46;">${parseFloat(r.ipk || 0).toFixed(2)}</span>
                        <span style="color: var(--color-text-light);"> / </span>
                        <span class="cell-mono">${esc(r.jumlah_sks || 0)}</span>
                    </td>
                    <td><span style="font-weight: 600;">${esc(formatProgram(r.program))}</span></td>
                    <td><span style="font-weight: 700; color: var(--color-brand-blue);">${unitName}</span></td>
                    <td>
                        <span style="font-size: 0.75rem; color: var(--color-text-main);">${esc(r.peminatan_list || '-')}</span>
                    </td>
                    <td style="text-align: center;">${docsHtml}</td>
                    <td style="text-align: center;">
                        ${getStatusBadge(r.status)}
                        ${r.catatan_admin ? `<div style="font-size: 0.7rem; color: var(--color-text-muted); margin-top: 3px; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${esc(r.catatan_admin)}">${esc(r.catatan_admin)}</div>` : ''}
                    </td>
                    <td style="text-align: center;">
                        <button type="button" class="btn-table-action btn-mhs-detail" data-id="${r.id}" title="Lihat Profil Lengkap">Detail</button>
                    </td>
                </tr>
            `;
        });

        el.tableMhsBody.innerHTML = html;

        // Bind detail buttons
        el.tableMhsBody.querySelectorAll('.btn-mhs-detail').forEach(b => {
            b.addEventListener('click', () => {
                const id = b.getAttribute('data-id');
                openDetailModal(id);
            });
        });
    }

    // ========================================================
    // LOAD FORMASI & KUOTA UNIT PLN TAB DATA
    // ========================================================
    async function loadFormasiData() {
        el.tableFormasiBody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 32px; color: var(--color-text-muted);">
                    Memuat formasi kuota unit PLN...
                </td>
            </tr>
        `;

        try {
            const pidQuery = state.selectedPeriodeId ? `?periode_id=${state.selectedPeriodeId}` : '';
            const res = await fetch(`/api/prodi/peminatan/list.php${pidQuery}`);
            const data = await res.json();

            if (!data.ok) {
                showToast(data.error || 'Gagal memuat formasi.', 'error');
                return;
            }

            state.formasi.rawList = data.formasi_unit || [];
            if (el.counterTabFormasi) {
                el.counterTabFormasi.style.display = 'inline-block';
                el.counterTabFormasi.textContent = state.formasi.rawList.length.toLocaleString('id-ID');
            }
            if (el.counterTabPeminatan && data.peminatan_list) {
                el.counterTabPeminatan.style.display = 'inline-block';
                el.counterTabPeminatan.textContent = data.peminatan_list.length.toLocaleString('id-ID');
            }

            filterAndRenderFormasi();

        } catch (err) {
            console.error('Error loading formasi data:', err);
            showToast('Terjadi kesalahan jaringan saat memuat data formasi.', 'error');
        }
    }

    // ========================================================
    // LOAD BIDANG PEMINATAN TAB DATA
    // ========================================================
    async function loadPeminatanData() {
        el.gridPeminatan.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.8125rem;">Memuat daftar peminatan...</div>';

        try {
            const pidQuery = state.selectedPeriodeId ? `?periode_id=${state.selectedPeriodeId}` : '';
            const res = await fetch(`/api/prodi/peminatan/list.php${pidQuery}`);
            const data = await res.json();

            if (!data.ok) {
                showToast(data.error || 'Gagal memuat peminatan.', 'error');
                return;
            }

            renderPeminatanCards(data.peminatan_list || []);

            if (el.counterTabFormasi && data.formasi_unit) {
                el.counterTabFormasi.style.display = 'inline-block';
                el.counterTabFormasi.textContent = data.formasi_unit.length.toLocaleString('id-ID');
            }
            if (el.counterTabPeminatan && data.peminatan_list) {
                el.counterTabPeminatan.style.display = 'inline-block';
                el.counterTabPeminatan.textContent = data.peminatan_list.length.toLocaleString('id-ID');
            }

        } catch (err) {
            console.error('Error loading peminatan data:', err);
            showToast('Terjadi kesalahan jaringan saat memuat data peminatan.', 'error');
        }
    }

    function filterAndRenderFormasi() {
        const query = (state.formasi.search || '').trim().toLowerCase();
        const tipe = state.formasi.tipe || '';

        const raw = state.formasi.rawList || [];
        const filtered = raw.filter(f => {
            if (tipe !== '' && f.tipe_kuota !== tipe) {
                return false;
            }
            if (query !== '') {
                const name = (f.unit_nama || '').toLowerCase();
                const abbr = (f.singkatan || '').toLowerCase();
                const addr = (f.alamat || '').toLowerCase();
                if (!name.includes(query) && !abbr.includes(query) && !addr.includes(query)) {
                    return false;
                }
            }
            return true;
        });

        if (el.formasiCount) {
            el.formasiCount.textContent = filtered.length.toLocaleString('id-ID');
        }

        renderFormasiTable(filtered, query !== '' || tipe !== '');
    }

    function renderPeminatanCards(list) {
        if (!list || list.length === 0) {
            el.gridPeminatan.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.8125rem;">Belum ada peminatan yang ditautkan ke Program Studi ini.</div>';
            return;
        }

        let html = '';
        list.forEach(p => {
            const pelamar = parseInt(p.total_pelamar_periode, 10) || 0;
            const isAktif = (parseInt(p.aktif, 10) === 1);

            html += `
                <div class="peminatan-card">
                    <div>
                        <div class="peminatan-card-title">
                            <span>${esc(p.nama)}</span>
                            <span class="status-badge ${isAktif ? 'status-badge-diterima' : 'status-badge-ditolak'}" style="font-size: 0.65rem;">
                                ${isAktif ? 'AKTIF' : 'NON-AKTIF'}
                            </span>
                        </div>
                        <p class="peminatan-card-desc">${esc(p.deskripsi || 'Tidak ada deskripsi rinci untuk bidang peminatan ini.')}</p>
                    </div>
                    <div class="peminatan-card-footer">
                        <span>Peminat dari Prodi:</span>
                        <strong style="color: var(--color-brand-blue); font-size: 0.875rem;" class="cell-mono">${pelamar} Mahasiswa</strong>
                    </div>
                </div>
            `;
        });
        el.gridPeminatan.innerHTML = html;
    }

    function renderFormasiTable(list, isFiltered = false) {
        if (!list || list.length === 0) {
            const emptyMessage = isFiltered
                ? 'Tidak ada formasi unit PLN yang sesuai dengan filter pencarian.'
                : 'Belum ada formasi unit PLN yang membuka kuota untuk Program Studi ini pada periode terpilih.';

            el.tableFormasiBody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align: center; padding: 36px; color: var(--color-text-muted);">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 8px; opacity: 0.5;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <div>${emptyMessage}</div>
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        let no = 1;
        list.forEach(f => {
            const unitName = f.singkatan ? `${esc(f.unit_nama)} (${esc(f.singkatan)})` : esc(f.unit_nama);
            const isBreakdown = (f.tipe_kuota === 'breakdown');
            const tipeText = isBreakdown ? 'Khusus Prodi' : 'Umum / Terbuka';
            const tipeCls = isBreakdown ? 'color: #0369A1; font-weight: 700;' : 'color: var(--color-text-muted);';

            const kuotaProdi = isBreakdown ? (parseInt(f.kuota_prodi, 10) || 0) : (parseInt(f.kuota_unit_total, 10) || 0);
            const sisaProdi = isBreakdown ? (parseInt(f.kuota_prodi_tersisa, 10) || 0) : '-';
            const pelamar = parseInt(f.pelamar_dari_prodi, 10) || 0;
            const diterima = parseInt(f.diterima_dari_prodi, 10) || 0;

            const pct = kuotaProdi > 0 ? Math.min(100, Math.round((diterima / kuotaProdi) * 100)) : 0;

            html += `
                <tr>
                    <td style="text-align: center;" class="cell-mono">${no++}</td>
                    <td>
                        <div style="font-weight: 700; color: var(--color-brand-blue); font-size: 0.875rem;">${unitName}</div>
                    </td>
                    <td>
                        <span style="font-size: 0.75rem; color: var(--color-text-muted);">${esc(f.alamat || '-')}</span>
                    </td>
                    <td style="text-align: center;">
                        <span style="${tipeCls}; font-size: 0.75rem;">${tipeText}</span>
                    </td>
                    <td style="text-align: center;" class="cell-mono font-bold">${kuotaProdi}</td>
                    <td style="text-align: center;" class="cell-mono">${sisaProdi}</td>
                    <td style="text-align: center;" class="cell-mono">${pelamar} mhs</td>
                    <td style="text-align: center;" class="cell-mono">
                        <span style="font-weight: 700; color: #065F46;">${diterima} mhs</span>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div class="stat-bar-track" style="flex: 1; height: 6px;">
                                <div class="stat-bar-fill" style="width: ${pct}%; background-color: #065F46;"></div>
                            </div>
                            <span class="cell-mono" style="font-size: 0.72rem; color: var(--color-text-muted); min-width: 30px;">${pct}%</span>
                        </div>
                    </td>
                </tr>
            `;
        });
        el.tableFormasiBody.innerHTML = html;
    }

    // ========================================================
    // MODAL DETAIL MAHASISWA
    // ========================================================
    async function openDetailModal(pendaftaranId) {
        try {
            el.modalDetail.classList.add('open');
            el.detailModalName.textContent = 'Memuat Data...';
            el.detailModalNim.textContent = 'NIM: -';

            const res = await fetch(`/api/prodi/mahasiswa/detail.php?id=${pendaftaranId}`);
            const json = await res.json();

            if (!json.ok || !json.data) {
                showToast(json.error || 'Gagal memuat detail mahasiswa.', 'error');
                closeDetailModal();
                return;
            }

            const d = json.data;
            el.detailModalName.textContent = d.nama || 'Detail Mahasiswa';
            el.detailModalNim.innerHTML = `NIM: <span class="cell-mono font-bold" style="color: #38BDF8;">${esc(d.nim)}</span> &bull; <span>${esc(d.jenjang_jurusan || 'S1')} ${esc(d.nama_jurusan || '')}</span>`;
            el.detailStatusBadge.innerHTML = getStatusBadge(d.status);
            el.detailSubmittedAt.textContent = formatDate(d.submitted_at);

            if (d.catatan_admin && d.catatan_admin.trim() !== '') {
                el.detailCatatanBox.style.display = 'block';
                el.detailCatatanAdmin.textContent = d.catatan_admin;
            } else {
                el.detailCatatanBox.style.display = 'none';
            }

            el.detailNama.textContent = d.nama || '-';
            el.detailNim.textContent = d.nim || '-';
            el.detailJk.textContent = (d.jenis_kelamin === 'L' || d.jenis_kelamin === 'Laki-laki') ? 'Laki-laki (L)' : 'Perempuan (P)';
            el.detailAngkatan.textContent = d.angkatan || '-';
            el.detailIpk.textContent = parseFloat(d.ipk || 0).toFixed(2);
            el.detailSks.textContent = `${d.jumlah_sks || 0} SKS`;
            el.detailEmail.textContent = d.email || '-';
            el.detailNohp.textContent = d.no_hp || '-';

            const unitFull = d.unit_singkatan ? `${d.unit_nama} (${d.unit_singkatan})` : d.unit_nama;
            el.detailUnit.textContent = unitFull || '-';
            el.detailProgram.textContent = formatProgram(d.program);
            el.detailUnitAlamat.textContent = d.unit_alamat || '-';

            // Peminatan chips
            if (d.peminatan_list && d.peminatan_list.length > 0) {
                el.detailPeminatanChips.innerHTML = d.peminatan_list.map(p => 
                    `<span style="background: #E0F2FE; color: #0369A1; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">${esc(p.nama)}</span>`
                ).join(' ');
            } else {
                el.detailPeminatanChips.textContent = '-';
            }

            el.detailAlamat.textContent = d.alamat || '-';
            el.detailKota.textContent = d.kota || '-';
            el.detailProvinsi.textContent = d.provinsi || '-';

            // Document links
            let hasDocs = false;
            if (d.cv_path) {
                el.linkCv.style.display = 'inline-flex';
                el.linkCv.href = `/api/admin/cv/download.php?pendaftaran_id=${d.id}`;
                hasDocs = true;
            } else {
                el.linkCv.style.display = 'none';
            }

            if (d.transkrip_path) {
                el.linkTranskrip.style.display = 'inline-flex';
                el.linkTranskrip.href = `/api/admin/transkrip/download.php?pendaftaran_id=${d.id}`;
                hasDocs = true;
            } else {
                el.linkTranskrip.style.display = 'none';
            }

            if (d.porto_path) {
                el.linkPorto.style.display = 'inline-flex';
                const isPortoLink = typeof d.porto_path === 'string' && (d.porto_path.startsWith('http://') || d.porto_path.startsWith('https://'));
                el.linkPorto.href = isPortoLink ? d.porto_path : `/api/admin/porto/download.php?pendaftaran_id=${d.id}`;
                el.linkPorto.title = isPortoLink ? `Buka Tautan Portofolio: ${d.porto_path}` : 'Unduh / Lihat Portofolio (PDF)';
                hasDocs = true;
            } else {
                el.linkPorto.style.display = 'none';
            }

            el.noDocsNotice.style.display = hasDocs ? 'none' : 'inline';

        } catch (err) {
            console.error('Error opening detail modal:', err);
            showToast('Gagal memuat detail mahasiswa.', 'error');
            closeDetailModal();
        }
    }

    function closeDetailModal() {
        el.modalDetail.classList.remove('open');
    }

    // ========================================================
    // MODAL UBAH PASSWORD
    // ========================================================
    function openPasswordModal() {
        el.formPassword.reset();
        el.modalPassword.classList.add('open');
        el.inputCurrentPass.focus();
    }

    function closePasswordModal() {
        el.modalPassword.classList.remove('open');
    }

    async function handlePasswordChange(e) {
        e.preventDefault();

        const currentPass = el.inputCurrentPass.value;
        const newPass = el.inputNewPass.value;
        const confirmPass = el.inputConfirmPass.value;

        if (newPass.length < 6) {
            showToast('Password baru minimal 6 karakter.', 'error');
            return;
        }

        if (newPass !== confirmPass) {
            showToast('Konfirmasi password baru tidak cocok.', 'error');
            return;
        }

        el.btnSubmitPassword.disabled = true;
        el.btnSubmitPassword.textContent = 'Menyimpan...';

        try {
            const res = await fetch('/api/prodi/profil/change_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrfToken
                },
                body: JSON.stringify({
                    current_password: currentPass,
                    new_password: newPass,
                    confirm_password: confirmPass
                })
            });

            const data = await res.json();
            if (!data.ok) {
                showToast(data.error || 'Gagal mengubah password.', 'error');
                return;
            }

            showToast(data.message || 'Password berhasil diperbarui!', 'success');
            closePasswordModal();

        } catch (err) {
            console.error('Error changing password:', err);
            showToast('Terjadi kesalahan jaringan saat mengubah password.', 'error');
        } finally {
            el.btnSubmitPassword.disabled = false;
            el.btnSubmitPassword.textContent = 'Simpan Password';
        }
    }

    // ========================================================
    // LOGOUT
    // ========================================================
    async function handleLogout() {
        if (!confirm('Apakah Anda yakin ingin keluar dari Portal Program Studi?')) {
            return;
        }

        try {
            await fetch('/api/admin/logout.php', { method: 'POST' });
        } catch (_) {}

        window.location.href = '/admin/login.html';
    }

    // Start
    document.addEventListener('DOMContentLoaded', init);

})();
