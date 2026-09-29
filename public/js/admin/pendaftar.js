document.addEventListener('DOMContentLoaded', async () => {
    const statusRes = await fetch('/api/admin/status.php');
    const statusData = await statusRes.json();
    if (!statusRes.ok || !statusData.authenticated) {
        window.location.href = '/admin/login.html';
        return;
    }

    let allData = [];
    let isPeriodeSelectPopulated = false;
    let currentPage = 1;
    let perPage = 25;
    let totalPages = 1;
    let totalRecords = 0;
    let currentSearch = '';
    let searchDebounceTimer = null;
    let selectedPeriodeId = null;

    async function loadPendaftar(targetPeriodeId = null, page = 1) {
        const tbody = document.getElementById('table-pendaftar');
        const selectEl = document.getElementById('select-periode-pendaftar');

        if (targetPeriodeId) {
            selectedPeriodeId = targetPeriodeId;
        }

        currentPage = page;

        try {
            const params = new URLSearchParams();
            if (selectedPeriodeId) params.set('periode_id', selectedPeriodeId);
            params.set('page', currentPage);
            params.set('per_page', perPage);
            if (currentSearch) params.set('search', currentSearch);

            const url = `/api/admin/pendaftar/list.php?${params.toString()}`;
            const res = await fetch(url);
            const data = await res.json();
            
            if (data.ok) {
                // Populate period select dropdown
                if (data.all_periode && selectEl && !isPeriodeSelectPopulated) {
                    selectEl.innerHTML = '';
                    data.all_periode.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        opt.innerText = `${p.nama} (${(p.status || 'DRAFT').toUpperCase()})`;
                        if (data.periode_terpilih && parseInt(p.id) === parseInt(data.periode_terpilih.id)) {
                            opt.selected = true;
                            selectedPeriodeId = p.id;
                        }
                        selectEl.appendChild(opt);
                    });

                    isPeriodeSelectPopulated = true;
                    selectEl.addEventListener('change', (e) => {
                        selectedPeriodeId = e.target.value;
                        loadPendaftar(selectedPeriodeId, 1);
                    });
                }

                // Update Info Periode Text cleanly
                const infoEl = document.getElementById('info-periode');
                const btnExport = document.getElementById('btn-export-pendaftar');
                const p = data.periode_terpilih;

                if (btnExport && p) {
                    btnExport.href = `/api/admin/pendaftar/export.php?periode_id=${p.id}`;
                }

                if (!p) {
                    infoEl.innerHTML = '<span style="color: #ef4444; font-weight: 600;">Belum ada periode magang yang dibuat.</span>';
                } else {
                    const st = (p.status || '').toLowerCase().trim();
                    if (st === 'dibuka') {
                        infoEl.innerHTML = `<span style="color: #059669; font-weight: 700;">● Periode Aktif: ${escapeHtml(p.nama)} (DIBUKA - MAHASISWA BISA DAFTAR)</span>`;
                    } else if (st === 'persiapan') {
                        infoEl.innerHTML = `<span style="color: #d97706; font-weight: 700;">● Periode Persiapan: ${escapeHtml(p.nama)} (PERSIAPAN - SETTING KUOTA)</span>`;
                    } else {
                        infoEl.innerHTML = `<span style="color: #64748b; font-weight: 600;">(Tidak Ada Periode Aktif) Periode Terpilih: ${escapeHtml(p.nama)} [STATUS: ${st.toUpperCase()}]</span>`;
                    }
                }

                allData = data.data || [];
                
                // Update Pagination Info
                if (data.pagination) {
                    totalRecords = data.pagination.total;
                    totalPages = data.pagination.total_pages;
                    currentPage = data.pagination.page;
                    updatePaginationControls();
                }

                renderTable(allData);
            }
        } catch (err) {
            console.error(err);
            tbody.innerHTML = '<tr><td colspan="6" class="text-merah" style="padding: var(--space-3); text-align: center;">Gagal memuat data.</td></tr>';
        }
    }

    function updatePaginationControls() {
        const infoEl = document.getElementById('pagination-info');
        const pageNumEl = document.getElementById('pagination-page-num');
        const btnPrev = document.getElementById('btn-prev-page');
        const btnNext = document.getElementById('btn-next-page');

        const start = totalRecords === 0 ? 0 : (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, totalRecords);

        infoEl.innerHTML = totalRecords === 0 ? 'Menampilkan <strong>0</strong> data' : `Menampilkan <strong>${start}–${end}</strong> dari <strong>${totalRecords}</strong> data`;
        pageNumEl.innerText = `Halaman ${currentPage} / ${totalPages || 1}`;

        btnPrev.disabled = (currentPage <= 1);
        btnNext.disabled = (currentPage >= totalPages || totalPages === 0);

    }

    document.getElementById('btn-prev-page')?.addEventListener('click', () => {
        if (currentPage > 1) {
            loadPendaftar(selectedPeriodeId, currentPage - 1);
        }
    });

    document.getElementById('btn-next-page')?.addEventListener('click', () => {
        if (currentPage < totalPages) {
            loadPendaftar(selectedPeriodeId, currentPage + 1);
        }
    });

    function escapeHtml(unsafe) {
        if (!unsafe && unsafe !== 0) return '';
        return String(unsafe)
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    function renderTable(dataArray) {
        const tbody = document.getElementById('table-pendaftar');
        tbody.innerHTML = '';
        
        if (!dataArray || dataArray.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="color: #64748b; padding: 24px; text-align: center;">Tidak ada data pendaftar.</td></tr>';
            return;
        }
        
        dataArray.forEach((p, idx) => {
            let progBadge = '<span style="font-weight: 600; font-size: 0.8rem; color: #4338ca; background: #e0e7ff; padding: 4px 10px; border-radius: 6px;">5 Bulan (KRS)</span>';
            if (p.program === '1_bulan') {
                progBadge = '<span style="font-weight: 600; font-size: 0.8rem; color: #0b3d6b; background: #e0f2fe; padding: 4px 10px; border-radius: 6px;">1 Bulan</span>';
            } else if (p.program === '3_bulan') {
                progBadge = '<span style="font-weight: 600; font-size: 0.8rem; color: #92400e; background: #fef3c7; padding: 4px 10px; border-radius: 6px;">3 Bulan</span>';
            } else if (p.program === '4_bulan') {
                progBadge = '<span style="font-weight: 600; font-size: 0.8rem; color: #1e1b4b; background: #ede9fe; padding: 4px 10px; border-radius: 6px;">4 Bulan</span>';
            }

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-size: 0.825rem; color: #64748b; font-family: monospace;">${escapeHtml(p.created_at)}</td>
                <td style="font-family: monospace; font-weight: 700; color: #0b3d6b;">${escapeHtml(p.nim)}</td>
                <td>
                    <div style="font-weight: 700; color: #0f172a;">${escapeHtml(p.nama)}</div>
                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">${escapeHtml(p.email)} • ${escapeHtml(p.no_hp || '-')}</div>
                </td>
                <td>${progBadge}</td>
                <td style="font-weight: 600; color: #0b3d6b;">${escapeHtml(p.unit_nama)}</td>
                <td style="text-align: center;">
                    <button type="button" class="btn-role-action btn-detail-item" data-idx="${idx}" style="padding: 6px 14px; font-size: 0.8rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span>Detail</span>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });

        // Attach modal listeners
        document.querySelectorAll('.btn-detail-item').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const idx = parseInt(btn.getAttribute('data-idx'));
                if (dataArray[idx]) {
                    showDetailModal(dataArray[idx]);
                }
            });
        });
    }

    function showDetailModal(p) {
        document.getElementById('detail-mhs-nama').innerText = p.nama || 'Pendaftar';
        document.getElementById('detail-mhs-nim').innerText = p.nim || '-';
        const progMapDetail = {
            '1_bulan': 'Program 1 Bulan',
            '3_bulan': 'Program 3 Bulan',
            '4_bulan': 'Program 4 Bulan',
            '5_bulan': 'Program 5 Bulan (KRS)'
        };
        document.getElementById('detail-mhs-program').innerText = progMapDetail[p.program] || p.program || '-';
        
        document.getElementById('detail-jurusan').innerText = p.nama_jurusan || '-';
        document.getElementById('detail-angkatan').innerText = p.angkatan ? `Angkatan 20${p.angkatan}` : '-';
        document.getElementById('detail-ipk').innerText = p.ipk ? `${p.ipk} / 4.00` : '-';
        document.getElementById('detail-sks').innerText = p.jumlah_sks ? `${p.jumlah_sks} SKS` : '-';
        document.getElementById('detail-email').innerText = p.email || '-';
        document.getElementById('detail-nohp').innerText = p.no_hp || '-';

        // Peminatan tags
        const pemContainer = document.getElementById('detail-peminatan');
        if (p.peminatan_list) {
            const pems = p.peminatan_list.split(',').map(s => s.trim()).filter(Boolean);
            pemContainer.innerHTML = pems.map(pem => `<span class="detail-pill-tag">${escapeHtml(pem)}</span>`).join('');
        } else {
            pemContainer.innerHTML = '<span style="color: #94a3b8; font-style: italic; font-size: 0.85rem;">Tidak ada peminatan khusus yang dipilih</span>';
        }

        const fullAlamat = [
            p.alamat_domisili,
            p.rt ? `RT ${p.rt}` : '',
            p.rw ? `RW ${p.rw}` : '',
            p.kelurahan ? `Kel. ${p.kelurahan}` : '',
            p.kecamatan ? `Kec. ${p.kecamatan}` : '',
            p.kota,
            p.provinsi
        ].filter(Boolean).join(', ');

        document.getElementById('detail-alamat').innerText = fullAlamat || 'Alamat domisili belum diisi';
        document.getElementById('detail-lat').innerText = p.latitude || '-';
        document.getElementById('detail-lng').innerText = p.longitude || '-';

        const mapLink = document.getElementById('detail-map-link');
        if (p.latitude && p.longitude && parseFloat(p.latitude) !== 0 && parseFloat(p.longitude) !== 0) {
            mapLink.href = `https://www.openstreetmap.org/?mlat=${p.latitude}&mlon=${p.longitude}#map=16/${p.latitude}/${p.longitude}`;
            mapLink.style.display = 'inline-flex';
        } else {
            mapLink.style.display = 'none';
        }

        document.getElementById('detail-unit-nama').innerText = p.unit_nama || 'Belum Ditetapkan';
        
        const jarakEl = document.getElementById('detail-jarak');
        if (p.jarak_km !== null && p.jarak_km !== undefined && !isNaN(parseFloat(p.jarak_km))) {
            jarakEl.innerText = `${parseFloat(p.jarak_km).toFixed(2)} km`;
        } else {
            jarakEl.innerText = '-';
        }

        const badgeContainer = document.getElementById('detail-status-badge');
        let statusBadge = '<span class="badge-status" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">Diajukan</span>';
        const st = (p.status_penetapan || '').toLowerCase().trim();
        if (st === 'diterima') {
            statusBadge = '<span class="badge-status badge-dibuka">Diterima</span>';
        } else if (st === 'dipindahkan') {
            statusBadge = '<span class="badge-status" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">Dipindahkan</span>';
        } else if (st === 'ditolak') {
            statusBadge = '<span class="badge-status" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;">Ditolak</span>';
        }
        badgeContainer.innerHTML = statusBadge;

        const isDocUrl = (path) => path && typeof path === 'string' && (path.startsWith('http://') || path.startsWith('https://'));
        const extLinkIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
        const pdfFileIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 12 15 15"/></svg>';

        // 1. Transkrip Nilai Preview Modal
        const wrapTranskrip = document.getElementById('detail-wrapper-transkrip');
        const btnTranskrip = document.getElementById('detail-btn-transkrip');
        const filenameEl = document.getElementById('detail-transkrip-filename');
        const isTranskripLink = isDocUrl(p.transkrip_path);
        const transkripTargetUrl = isTranskripLink ? p.transkrip_path : `/api/admin/transkrip/download.php?pendaftaran_id=${p.id}`;
        const transkripFile = isTranskripLink ? 'Tautan Transkrip Nilai (Google Drive / Publik)' : (p.transkrip_filename || (p.transkrip_path ? p.transkrip_path.split('/').pop() : `${p.nim}.pdf`));

        if (p.id && p.transkrip_path) {
            if (wrapTranskrip) wrapTranskrip.style.display = 'flex';
            if (filenameEl) {
                if (isTranskripLink) {
                    filenameEl.innerHTML = `<a href="${escapeHtml(transkripTargetUrl)}" target="_blank" rel="noopener noreferrer" style="color: #0284c7; text-decoration: underline; word-break: break-all;">${escapeHtml(p.transkrip_path)}</a>`;
                } else {
                    filenameEl.innerText = transkripFile;
                }
            }
            if (btnTranskrip) {
                btnTranskrip.style.display = 'inline-flex';
                btnTranskrip.href = transkripTargetUrl;
                btnTranskrip.innerHTML = isTranskripLink 
                    ? `${extLinkIcon}<span>Buka Link Transkrip</span>` 
                    : `${pdfFileIcon}<span>Lihat Transkrip PDF</span>`;
                btnTranskrip.title = isTranskripLink ? 'Buka Tautan: ' + p.transkrip_path : 'Pratinjau Berkas PDF';
                btnTranskrip.onclick = null;
            }
        } else {
            if (wrapTranskrip) wrapTranskrip.style.display = 'none';
            if (btnTranskrip) {
                btnTranskrip.style.display = 'none';
                btnTranskrip.removeAttribute('href');
                btnTranskrip.onclick = null;
            }
        }

        // 2. Curriculum Vitae (CV) Preview Modal
        const wrapCv = document.getElementById('detail-wrapper-cv');
        const btnCv = document.getElementById('detail-btn-cv');
        const filenameCvEl = document.getElementById('detail-cv-filename');
        const isCvLink = isDocUrl(p.cv_path);
        const cvTargetUrl = isCvLink ? p.cv_path : `/api/admin/cv/download.php?pendaftaran_id=${p.id}`;
        const cvFile = isCvLink ? 'Tautan Curriculum Vitae (Google Drive / Publik)' : (p.cv_filename || (p.cv_path ? p.cv_path.split('/').pop() : `${p.nim}_CV.pdf`));

        if (p.id && p.cv_path) {
            if (wrapCv) wrapCv.style.display = 'flex';
            if (filenameCvEl) {
                if (isCvLink) {
                    filenameCvEl.innerHTML = `<a href="${escapeHtml(cvTargetUrl)}" target="_blank" rel="noopener noreferrer" style="color: #047857; text-decoration: underline; word-break: break-all;">${escapeHtml(p.cv_path)}</a>`;
                } else {
                    filenameCvEl.innerText = cvFile;
                }
            }
            if (btnCv) {
                btnCv.style.display = 'inline-flex';
                btnCv.href = cvTargetUrl;
                btnCv.innerHTML = isCvLink 
                    ? `${extLinkIcon}<span>Buka Link CV</span>` 
                    : `${pdfFileIcon}<span>Lihat CV PDF</span>`;
                btnCv.title = isCvLink ? 'Buka Tautan: ' + p.cv_path : 'Pratinjau Berkas PDF';
                btnCv.onclick = null;
            }
        } else {
            if (wrapCv) wrapCv.style.display = 'none';
            if (btnCv) {
                btnCv.style.display = 'none';
                btnCv.removeAttribute('href');
                btnCv.onclick = null;
            }
        }

        // 3. Portofolio Preview Modal
        const wrapPorto = document.getElementById('detail-wrapper-porto');
        const btnPorto = document.getElementById('detail-btn-porto');
        const filenamePortoEl = document.getElementById('detail-porto-filename');
        const isPortoLink = isDocUrl(p.porto_path);
        const portoTargetUrl = isPortoLink ? p.porto_path : `/api/admin/porto/download.php?pendaftaran_id=${p.id}`;
        const portoFile = isPortoLink ? 'Tautan Portofolio (Google Drive / Publik)' : (p.porto_filename || (p.porto_path ? p.porto_path.split('/').pop() : `${p.nim}_Porto.pdf`));

        if (p.id && p.porto_path) {
            if (wrapPorto) wrapPorto.style.display = 'flex';
            if (filenamePortoEl) {
                if (isPortoLink) {
                    filenamePortoEl.innerHTML = `<a href="${escapeHtml(portoTargetUrl)}" target="_blank" rel="noopener noreferrer" style="color: #6d28d9; text-decoration: underline; word-break: break-all;">${escapeHtml(p.porto_path)}</a>`;
                } else {
                    filenamePortoEl.innerText = portoFile;
                }
            }
            if (btnPorto) {
                btnPorto.style.display = 'inline-flex';
                btnPorto.href = portoTargetUrl;
                btnPorto.innerHTML = isPortoLink 
                    ? `${extLinkIcon}<span>Buka Link Portofolio</span>` 
                    : `${pdfFileIcon}<span>Lihat Portofolio PDF</span>`;
                btnPorto.title = isPortoLink ? 'Buka Tautan: ' + p.porto_path : 'Pratinjau Berkas PDF';
                btnPorto.onclick = null;
            }
        } else {
            if (wrapPorto) wrapPorto.style.display = 'none';
            if (btnPorto) {
                btnPorto.style.display = 'none';
                btnPorto.removeAttribute('href');
                btnPorto.onclick = null;
            }
        }

        const modal = document.getElementById('modal-detail-pendaftar');
        if (modal) modal.classList.add('active');
    }

    const closeModal = () => {
        const modal = document.getElementById('modal-detail-pendaftar');
        if (modal) modal.classList.remove('active');
    };

    document.getElementById('btn-close-modal-detail')?.addEventListener('click', closeModal);
    document.getElementById('btn-close-modal-detail-bottom')?.addEventListener('click', closeModal);

    // Close on clicking modal backdrop outside card
    const modalOverlay = document.getElementById('modal-detail-pendaftar');
    if (modalOverlay) {
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) {
                closeModal();
            }
        });
    }

    // Close on pressing Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // Filter feature with Server-side Search (Debounced 300ms)
    document.getElementById('filter-input')?.addEventListener('input', (e) => {
        clearTimeout(searchDebounceTimer);
        currentSearch = (e.target.value || '').trim();
        searchDebounceTimer = setTimeout(() => {
            loadPendaftar(selectedPeriodeId, 1);
        }, 300);
    });

    loadPendaftar();
});
