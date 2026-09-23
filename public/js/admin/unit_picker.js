/**
 * public/js/admin/unit_picker.js
 * Searchable Unit Picker Cards with Real-time Search, Quota Info, Haversine Distance Sorting, and Recommendation Scoring
 * Digunakan bersama oleh Admin BLKA (penetapan.js) dan Mitra Perusahaan (dashboard.js).
 */

function escapeHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

/**
 * Hitung jarak garis lurus (km) antara 2 koordinat (Haversine Formula)
 */
function calculateDistanceKm(lat1, lon1, lat2, lon2) {
    if (lat1 === null || lon1 === null || lat2 === null || lon2 === null) return null;
    const l1 = parseFloat(lat1);
    const ln1 = parseFloat(lon1);
    const l2 = parseFloat(lat2);
    const ln2 = parseFloat(lon2);
    if (isNaN(l1) || isNaN(ln1) || isNaN(l2) || isNaN(ln2) || (l1 === 0 && ln1 === 0) || (l2 === 0 && ln2 === 0)) {
        return null;
    }
    const R = 6371; // Radius bumi dalam km
    const dLat = (l2 - l1) * Math.PI / 180;
    const dLon = (ln2 - ln1) * Math.PI / 180;
    const a = 
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(l1 * Math.PI / 180) * Math.cos(l2 * Math.PI / 180) * 
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

/**
 * Memastikan style unit picker selalu disuntikkan ke dokumen (anti-cache & universal)
 */
function ensureUnitPickerStyles() {
    if (document.getElementById('unit-picker-injected-styles')) return;

    const styleEl = document.createElement('style');
    styleEl.id = 'unit-picker-injected-styles';
    styleEl.textContent = `
        /* --- UNIT PICKER CORE --- */
        .unit-picker-box {
            display: flex !important;
            flex-direction: column !important;
            gap: 10px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .unit-algo-banner {
            background: #f0fdf4 !important;
            border: 1px solid #bbf7d0 !important;
            border-radius: 12px !important;
            padding: 10px 14px !important;
            font-size: 0.8rem !important;
            color: #166534 !important;
            display: flex !important;
            align-items: flex-start !important;
            gap: 10px !important;
            line-height: 1.45 !important;
            box-sizing: border-box !important;
        }

        .unit-algo-banner svg {
            flex-shrink: 0 !important;
            margin-top: 2px !important;
            color: #16a34a !important;
        }

        .unit-picker-search-wrap {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .unit-picker-search-icon {
            position: absolute !important;
            left: 14px !important;
            color: #94a3b8 !important;
            pointer-events: none !important;
        }

        .unit-picker-search-input {
            width: 100% !important;
            height: 42px !important;
            padding: 8px 38px 8px 40px !important;
            border: 1.5px solid #cbd5e1 !important;
            border-radius: 12px !important;
            font-size: 0.875rem !important;
            color: #0f172a !important;
            background: #ffffff !important;
            outline: none !important;
            transition: all 0.2s ease !important;
            box-sizing: border-box !important;
            font-family: inherit !important;
        }

        .unit-picker-search-input:focus {
            border-color: #0b3d6b !important;
            box-shadow: 0 0 0 3px rgba(11, 61, 107, 0.12) !important;
        }

        .unit-picker-search-clear {
            position: absolute !important;
            right: 12px !important;
            background: none !important;
            border: none !important;
            color: #94a3b8 !important;
            font-size: 1.3rem !important;
            cursor: pointer !important;
            padding: 4px !important;
            display: none;
            line-height: 1 !important;
        }

        .unit-picker-search-clear:hover {
            color: #ef4444 !important;
        }

        .unit-picker-stats-bar {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            font-size: 0.775rem !important;
            color: #64748b !important;
            padding: 0 4px !important;
        }

        /* Container Kartu dengan Scrollbar Jelas & Selalu Tampil */
        .unit-cards-scroll {
            height: 250px !important;
            max-height: 250px !important;
            overflow-y: scroll !important;
            overflow-x: hidden !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 10px !important;
            padding-right: 6px !important;
            border-radius: 12px !important;
            scrollbar-width: thin !important;
            scrollbar-color: #0b3d6b #f1f5f9 !important;
            box-sizing: border-box !important;
        }

        .unit-cards-scroll::-webkit-scrollbar {
            width: 7px !important;
        }

        .unit-cards-scroll::-webkit-scrollbar-track {
            background: #f1f5f9 !important;
            border-radius: 10px !important;
        }

        .unit-cards-scroll::-webkit-scrollbar-thumb {
            background: #0b3d6b !important;
            border-radius: 10px !important;
        }

        .unit-cards-scroll::-webkit-scrollbar-thumb:hover {
            background: #072744 !important;
        }

        /* Kartu Unit */
        .unit-card-item {
            border: 1.5px solid #e2e8f0 !important;
            border-radius: 14px !important;
            padding: 12px 14px !important;
            background: #ffffff !important;
            cursor: pointer !important;
            transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1) !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
            position: relative !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04) !important;
            box-sizing: border-box !important;
            text-align: left !important;
        }

        .unit-card-item:hover {
            border-color: #0284c7 !important;
            background: #f8fafc !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06) !important;
        }

        .unit-card-item.selected {
            border-color: #0b3d6b !important;
            background: #f0f9ff !important;
            box-shadow: 0 0 0 2px rgba(11, 61, 107, 0.25) !important;
        }

        .unit-card-header {
            display: flex !important;
            align-items: flex-start !important;
            justify-content: space-between !important;
            gap: 10px !important;
        }

        .unit-card-title {
            font-size: 0.875rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            line-height: 1.35 !important;
            flex: 1 !important;
        }

        .unit-card-parent {
            font-size: 0.75rem !important;
            color: #64748b !important;
            margin-top: -3px !important;
        }

        .unit-card-meta {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            flex-wrap: wrap !important;
        }

        /* Badge Base (Semua Badge Rounded Pill) */
        .badge-card {
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            font-size: 0.725rem !important;
            font-weight: 700 !important;
            padding: 4px 10px !important;
            border-radius: 9999px !important; /* Full Pill Rounded */
            white-space: nowrap !important;
            line-height: 1.25 !important;
            box-sizing: border-box !important;
        }

        /* Badge Rekomendasi */
        .badge-recom-match {
            background: #ecfdf5 !important;
            color: #047857 !important;
            border: 1px solid #a7f3d0 !important;
        }

        .badge-recom-partial {
            background: #fefce8 !important;
            color: #a16207 !important;
            border: 1px solid #fef08a !important;
        }

        .badge-recom-none {
            background: #f1f5f9 !important;
            color: #64748b !important;
            border: 1px solid #e2e8f0 !important;
        }

        /* Badge Jarak Haversine */
        .badge-distance {
            background: #eff6ff !important;
            color: #1d4ed8 !important;
            border: 1px solid #bfdbfe !important;
        }

        /* Badge Kuota */
        .badge-kuota-available {
            background: #dcfce7 !important;
            color: #166534 !important;
            border: 1px solid #bbf7d0 !important;
        }

        .badge-kuota-limited {
            background: #fef3c7 !important;
            color: #92400e !important;
            border: 1px solid #fde68a !important;
        }

        .badge-kuota-full {
            background: #fee2e2 !important;
            color: #991b1b !important;
            border: 1px solid #fecaca !important;
        }

        .unit-card-prodi-count {
            font-size: 0.75rem !important;
            color: #64748b !important;
            font-weight: 600 !important;
        }

        /* Prodi Chips List */
        .unit-card-prodi-list {
            display: flex !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 5px !important;
            margin-top: 2px !important;
        }

        .prodi-chip {
            font-size: 0.7rem !important;
            font-weight: 500 !important;
            padding: 3px 8px !important;
            border-radius: 6px !important;
            background: #f8fafc !important;
            color: #334155 !important;
            border: 1px solid #e2e8f0 !important;
            white-space: nowrap !important;
            line-height: 1.2 !important;
        }

        .prodi-chip.matched {
            background: #eff6ff !important;
            color: #1d4ed8 !important;
            font-weight: 700 !important;
            border: 1px solid #bfdbfe !important;
        }

        .unit-picker-empty {
            text-align: center !important;
            padding: 36px 16px !important;
            color: #94a3b8 !important;
            font-size: 0.85rem !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
        }
    `;
    document.head.appendChild(styleEl);
}

/**
 * Inisialisasi Searchable Unit Picker
 * @param {Object} cfg
 * @param {string} cfg.searchInputId - ID input teks pencarian
 * @param {string} cfg.clearBtnId - ID tombol reset/clear teks pencarian
 * @param {string} cfg.statsCountId - ID label statistik jumlah unit
 * @param {string} cfg.cardsContainerId - ID container daftar kartu unit
 * @param {string} cfg.hiddenInputId - ID input hidden yang menyimpan upp_id terpilih
 * @param {Array}  cfg.units - Array objek data unit
 * @param {Object} cfg.context - Konteks { isBulk: boolean, mhs?: Object, selectedMhs?: Array, requiredCount?: number }
 * @param {Function} [cfg.onSelect] - Callback ketika kartu dipilih (u, isSelected)
 */
export function setupUnitPicker({
    searchInputId,
    clearBtnId,
    statsCountId,
    cardsContainerId,
    hiddenInputId,
    units = [],
    context = {},
    onSelect = null
}) {
    ensureUnitPickerStyles();

    const searchInput = document.getElementById(searchInputId);
    const clearBtn = document.getElementById(clearBtnId);
    const statsCount = document.getElementById(statsCountId);
    const container = document.getElementById(cardsContainerId);
    const hiddenInput = document.getElementById(hiddenInputId);

    if (!container || !hiddenInput) return;

    // Reset hidden input & UI pencarian
    hiddenInput.value = '';
    if (searchInput) searchInput.value = '';
    if (clearBtn) clearBtn.style.display = 'none';

    const isBulk = Boolean(context.isBulk);
    const mhs = context.mhs || null;
    const selectedMhs = context.selectedMhs || [];
    const requiredCount = isBulk ? (context.requiredCount || selectedMhs.length || 1) : 1;

    let targetJurusanId = 0;
    let targetJurusanNama = '';
    let bulkJurusanIds = [];

    // Deteksi koordinat mahasiswa (titik pin peta)
    let studentLat = null;
    let studentLng = null;
    let hasStudentCoords = false;

    if (!isBulk && mhs) {
        targetJurusanId = parseInt(mhs.jurusan_id, 10) || 0;
        targetJurusanNama = mhs.jurusan_nama || '';

        if (mhs.latitude && mhs.longitude) {
            const pLat = parseFloat(mhs.latitude);
            const pLng = parseFloat(mhs.longitude);
            if (!isNaN(pLat) && !isNaN(pLng) && (pLat !== 0 || pLng !== 0)) {
                studentLat = pLat;
                studentLng = pLng;
                hasStudentCoords = true;
            }
        }
    } else if (isBulk && selectedMhs.length > 0) {
        bulkJurusanIds = Array.from(new Set(selectedMhs.map(m => parseInt(m.jurusan_id, 10)).filter(id => !isNaN(id) && id > 0)));

        // Ambil rata-rata titik koordinat rombongan
        const validCoords = selectedMhs
            .map(m => ({ lat: parseFloat(m.latitude), lng: parseFloat(m.longitude) }))
            .filter(c => !isNaN(c.lat) && !isNaN(c.lng) && (c.lat !== 0 || c.lng !== 0));

        if (validCoords.length > 0) {
            studentLat = validCoords.reduce((sum, c) => sum + c.lat, 0) / validCoords.length;
            studentLng = validCoords.reduce((sum, c) => sum + c.lng, 0) / validCoords.length;
            hasStudentCoords = true;
        }
    }

    // Update label petunjuk sorting di stats bar
    if (statsCount && statsCount.parentElement) {
        let sortHintEl = statsCount.parentElement.querySelector('.unit-picker-sort-hint');
        if (!sortHintEl) {
            sortHintEl = document.createElement('span');
            sortHintEl.className = 'unit-picker-sort-hint';
            sortHintEl.style.fontSize = '0.725rem';
            sortHintEl.style.fontWeight = '600';
            statsCount.parentElement.appendChild(sortHintEl);
        }
        if (hasStudentCoords) {
            sortHintEl.style.color = '#1d4ed8';
            sortHintEl.innerHTML = `Terdekat dari Lokasi ${isBulk ? 'Rombongan' : 'Mahasiswa'}`;
        } else {
            sortHintEl.style.color = '#0284c7';
            sortHintEl.innerHTML = `Rekomendasi Terbaik di Atas`;
        }
    }

    // Filter & hitung skor kecocokan serta jarak tiap unit
    const scoredUnits = [];

    for (let i = 0; i < units.length; i++) {
        const u = units[i];
        if (u.is_self) continue; // Jangan pindahkan ke unit sendiri

        const uppId = parseInt(u.upp_id, 10);
        const name = u.nama_unit || u.nama || 'Unit Pelaksana';
        const singkatan = u.singkatan || '';
        const parentName = u.nama_parent || '';
        const alamat = u.alamat || '';
        const totalQuota = u.kuota_total !== null && u.kuota_total !== undefined ? parseInt(u.kuota_total, 10) : null;
        const sisaQuota = u.kuota_tersisa !== null && u.kuota_tersisa !== undefined ? parseInt(u.kuota_tersisa, 10) : null;
        const prodiList = Array.isArray(u.prodi_list) ? u.prodi_list : [];
        const prodiIds = Array.isArray(u.prodi_ids) ? u.prodi_ids.map(Number) : [];
        const hasProdiRestriction = prodiIds.length > 0;

        // Hitung jarak Haversine dari lokasi mahasiswa ke kantor unit
        let distanceKm = null;
        let distanceFormatted = '';
        if (hasStudentCoords) {
            const uLat = u.latitude !== null && u.latitude !== undefined ? parseFloat(u.latitude) : null;
            const uLng = u.longitude !== null && u.longitude !== undefined ? parseFloat(u.longitude) : null;
            if (uLat !== null && uLng !== null && !isNaN(uLat) && !isNaN(uLng) && (uLat !== 0 || uLng !== 0)) {
                distanceKm = calculateDistanceKm(studentLat, studentLng, uLat, uLng);
                if (distanceKm !== null) {
                    if (distanceKm < 1) {
                        distanceFormatted = `${Math.round(distanceKm * 1000)} m`;
                    } else if (distanceKm < 10) {
                        distanceFormatted = `${distanceKm.toFixed(1)} km`;
                    } else {
                        distanceFormatted = `${Math.round(distanceKm)} km`;
                    }
                }
            }
        }

        let rank = 5;
        let badgeType = 'none'; // 'match', 'partial', 'none'
        let badgeText = '';
        let effectiveSisa = sisaQuota !== null ? sisaQuota : 9999;

        if (!isBulk) {
            // Mode Individual Relocate
            const prodiMatch = !hasProdiRestriction || (targetJurusanId > 0 && prodiIds.includes(targetJurusanId));

            if (u.tipe_kuota === 'breakdown' && targetJurusanId > 0) {
                const pInfo = prodiList.find(p => parseInt(p.jurusan_id, 10) === targetJurusanId);
                if (pInfo && pInfo.kuota_tersisa !== null && pInfo.kuota_tersisa !== undefined) {
                    effectiveSisa = Math.min(effectiveSisa, parseInt(pInfo.kuota_tersisa, 10));
                }
            }

            const hasQuota = effectiveSisa > 0;

            if (hasQuota && prodiMatch) {
                rank = 1;
                badgeType = 'match';
                badgeText = hasProdiRestriction ? 'Rekomendasi Utama (Prodi Cocok)' : 'Rekomendasi (Kuota Tersedia)';
            } else if (hasQuota && !prodiMatch) {
                rank = 2;
                badgeType = 'partial';
                badgeText = 'Kuota Tersedia • Prodi Berbeda';
            } else if (!hasQuota && prodiMatch) {
                rank = 3;
                badgeType = 'none';
                badgeText = 'Kuota Penuh • Prodi Cocok';
            } else {
                rank = 4;
                badgeType = 'none';
                badgeText = 'Kuota Penuh & Berbeda Prodi';
            }
        } else {
            // Mode Bulk Relocate
            let matchedCount = 0;
            if (!hasProdiRestriction) {
                matchedCount = bulkJurusanIds.length;
            } else {
                matchedCount = bulkJurusanIds.filter(jid => prodiIds.includes(jid)).length;
            }

            const allProdiMatch = bulkJurusanIds.length === 0 || matchedCount === bulkJurusanIds.length;
            const partialProdiMatch = matchedCount > 0;
            const quotaSufficient = effectiveSisa >= requiredCount;
            const hasAnyQuota = effectiveSisa > 0;

            if (allProdiMatch && quotaSufficient) {
                rank = 1;
                badgeType = 'match';
                badgeText = 'Sangat Direkomendasikan (100% Prodi & Kuota Cukup)';
            } else if (allProdiMatch && hasAnyQuota) {
                rank = 2;
                badgeType = 'partial';
                badgeText = `Semua Prodi Cocok (Sisa: ${sisaQuota} / Butuh: ${requiredCount})`;
            } else if (partialProdiMatch && quotaSufficient) {
                rank = 3;
                badgeType = 'partial';
                badgeText = `${matchedCount}/${bulkJurusanIds.length} Prodi Cocok • Kuota Cukup`;
            } else if (partialProdiMatch && hasAnyQuota) {
                rank = 4;
                badgeType = 'partial';
                badgeText = `${matchedCount}/${bulkJurusanIds.length} Prodi Cocok • Sisa ${sisaQuota}`;
            } else {
                rank = 5;
                badgeType = 'none';
                badgeText = (sisaQuota !== null && sisaQuota <= 0) ? 'Kuota Penuh' : 'Kurang Cocok';
            }
        }

        const prodiNames = prodiList.map(p => p.nama_jurusan || '').join(' ').toLowerCase();
        const searchCorpus = `${name} ${singkatan} ${parentName} ${alamat} ${prodiNames}`.toLowerCase();

        scoredUnits.push({
            raw: u,
            upp_id: uppId,
            name,
            singkatan,
            parentName,
            alamat,
            totalQuota,
            sisaQuota,
            effectiveSisa,
            distanceKm,
            distanceFormatted,
            prodiList,
            prodiIds,
            hasProdiRestriction,
            rank,
            badgeType,
            badgeText,
            searchCorpus
        });
    }

    // Urutkan:
    // 1. Rank (Kesesuaian Prodi & Ketersediaan Kuota)
    // 2. Jarak terdekat dari lokasi/pin mahasiswa
    // 3. Sisa kuota terbanyak
    // 4. Abjad nama unit
    scoredUnits.sort((a, b) => {
        if (a.rank !== b.rank) return a.rank - b.rank;

        // Jika pada kelompok rank yang sama, prioritaskan unit terdekat
        if (a.distanceKm !== null && b.distanceKm !== null) {
            if (Math.abs(a.distanceKm - b.distanceKm) > 0.05) {
                return a.distanceKm - b.distanceKm;
            }
        } else if (a.distanceKm !== null && b.distanceKm === null) {
            return -1;
        } else if (a.distanceKm === null && b.distanceKm !== null) {
            return 1;
        }

        if (a.effectiveSisa !== b.effectiveSisa) return b.effectiveSisa - a.effectiveSisa;
        return a.name.localeCompare(b.name);
    });

    let selectedUppId = null;

    function renderCards(query = '') {
        const q = query.trim().toLowerCase();
        let filtered = scoredUnits;
        if (q) {
            filtered = scoredUnits.filter(item => item.searchCorpus.includes(q));
        }

        if (statsCount) {
            if (q) {
                statsCount.innerText = `Menampilkan ${Math.min(filtered.length, 100)} dari ${filtered.length} unit yang cocok`;
            } else {
                statsCount.innerText = `Menampilkan ${Math.min(filtered.length, 100)} dari ${filtered.length} total unit`;
            }
        }

        if (filtered.length === 0) {
            container.innerHTML = `
                <div class="unit-picker-empty">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.8"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="m9 9 4 4"/><path d="m13 9-4 4"/></svg>
                    <div style="font-weight: 600; color: #475569; margin-top: 8px;">Tidak ada unit yang cocok</div>
                    <div style="font-size: 0.775rem; color: #94a3b8; margin-top: 2px;">Coba gunakan kata kunci pencarian yang lain</div>
                </div>
            `;
            return;
        }

        const maxDisplay = 100;
        const displayList = filtered.slice(0, maxDisplay);
        const cardHtmls = [];

        for (let i = 0; i < displayList.length; i++) {
            const u = displayList[i];
            const isSelected = (selectedUppId === u.upp_id);

            // Kuota badge
            let kuotaClass = 'badge-kuota-available';
            let kuotaText = `Sisa Kuota: ${u.sisaQuota !== null ? u.sisaQuota : 'Tersedia'}${u.totalQuota !== null ? ` / ${u.totalQuota}` : ''}`;
            if (u.sisaQuota !== null && u.sisaQuota <= 0) {
                kuotaClass = 'badge-kuota-full';
                kuotaText = `Kuota Penuh (0/${u.totalQuota || 0})`;
            } else if (u.sisaQuota !== null && u.sisaQuota <= 2) {
                kuotaClass = 'badge-kuota-limited';
                kuotaText = `Sisa Terbatas (${u.sisaQuota}/${u.totalQuota || 0})`;
            }

            // Subtitle
            let subTitle = '';
            if (u.parentName && u.parentName !== u.name) {
                subTitle = `Induk: ${escapeHtml(u.parentName)}`;
            } else if (u.alamat) {
                subTitle = escapeHtml(u.alamat);
            }

            // Badge rekomendasi
            let badgeHtml = '';
            if (u.badgeType === 'match') {
                badgeHtml = `<span class="badge-card badge-recom-match"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> ${escapeHtml(u.badgeText)}</span>`;
            } else if (u.badgeType === 'partial') {
                badgeHtml = `<span class="badge-card badge-recom-partial">${escapeHtml(u.badgeText)}</span>`;
            } else {
                badgeHtml = `<span class="badge-card badge-recom-none">${escapeHtml(u.badgeText)}</span>`;
            }

            // Badge jarak
            let distanceBadgeHtml = '';
            if (u.distanceFormatted) {
                distanceBadgeHtml = `
                    <span class="badge-card badge-distance" title="Perkiraan jarak garis lurus dari lokasi pin mahasiswa">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2a8 8 0 0 0-8 8c0 5.25 8 12 8 12s8-6.75 8-12a8 8 0 0 0-8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                        ${escapeHtml(u.distanceFormatted)}
                    </span>
                `;
            }

            // Prodi chips
            let prodiSectionHtml = '';
            if (u.prodiList.length > 0) {
                const chips = [];
                const maxChips = 3;
                for (let j = 0; j < Math.min(u.prodiList.length, maxChips); j++) {
                    const p = u.prodiList[j];
                    let isMatchedProdi = false;
                    if (!isBulk && targetJurusanId > 0 && parseInt(p.jurusan_id, 10) === targetJurusanId) {
                        isMatchedProdi = true;
                    } else if (isBulk && bulkJurusanIds.includes(parseInt(p.jurusan_id, 10))) {
                        isMatchedProdi = true;
                    }
                    chips.push(`<span class="prodi-chip ${isMatchedProdi ? 'matched' : ''}">${escapeHtml(p.nama_jurusan)}${p.kuota_tersisa !== null && p.kuota_tersisa !== undefined ? ` (${p.kuota_tersisa})` : ''}</span>`);
                }
                if (u.prodiList.length > maxChips) {
                    chips.push(`<span class="prodi-chip">+${u.prodiList.length - maxChips} prodi</span>`);
                }
                prodiSectionHtml = `<div class="unit-card-prodi-list">${chips.join('')}</div>`;
            } else {
                prodiSectionHtml = `<div class="unit-card-prodi-list"><span class="prodi-chip" style="background:#f1f5f9; color:#64748b;">Terbuka untuk Semua Jurusan</span></div>`;
            }

            cardHtmls.push(`
                <div class="unit-card-item ${isSelected ? 'selected' : ''}" data-upp-id="${u.upp_id}">
                    <div class="unit-card-header">
                        <div class="unit-card-title">
                            ${escapeHtml(u.name)}
                            ${u.singkatan ? `<span style="font-weight: 500; color: #64748b; font-size: 0.85em;">(${escapeHtml(u.singkatan)})</span>` : ''}
                        </div>
                        ${badgeHtml}
                    </div>
                    ${subTitle ? `<div class="unit-card-parent">${subTitle}</div>` : ''}
                    <div class="unit-card-meta">
                        <span class="badge-card ${kuotaClass}">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            ${escapeHtml(kuotaText)}
                        </span>
                        ${distanceBadgeHtml}
                        <span class="unit-card-prodi-count">
                            ${u.prodiList.length > 0 ? `${u.prodiList.length} Prodi Diterima` : 'Semua Prodi'}
                        </span>
                    </div>
                    ${prodiSectionHtml}
                </div>
            `);
        }

        if (filtered.length > maxDisplay) {
            cardHtmls.push(`
                <div style="text-align: center; padding: 12px; font-size: 0.8rem; color: #64748b; font-style: italic; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1; margin-top: 4px;">
                    Menampilkan 100 unit teratas yang paling relevan. Ketik kata kunci di atas untuk mencari unit spesifik lainnya.
                </div>
            `);
        }

        container.innerHTML = cardHtmls.join('');

        // Pasang event listener klik pada kartu
        container.querySelectorAll('.unit-card-item').forEach(card => {
            card.addEventListener('click', () => {
                const uppId = parseInt(card.getAttribute('data-upp-id'), 10);
                const matchedItem = scoredUnits.find(item => item.upp_id === uppId);

                if (selectedUppId === uppId) {
                    // Batalkan pilihan (Deselect)
                    selectedUppId = null;
                    hiddenInput.value = '';
                    card.classList.remove('selected');
                    if (typeof onSelect === 'function') onSelect(null, false);
                } else {
                    selectedUppId = uppId;
                    hiddenInput.value = String(uppId);
                    container.querySelectorAll('.unit-card-item.selected').forEach(c => c.classList.remove('selected'));
                    card.classList.add('selected');
                    if (typeof onSelect === 'function') onSelect(matchedItem ? matchedItem.raw : null, true);
                }
            });
        });
    }

    // Render awal
    renderCards('');

    // Listener pencarian dengan debounce 150ms
    if (searchInput) {
        let debounce = null;
        searchInput.oninput = (e) => {
            const val = e.target.value;
            if (clearBtn) clearBtn.style.display = val ? 'flex' : 'none';
            clearTimeout(debounce);
            debounce = setTimeout(() => {
                renderCards(val);
            }, 150);
        };
    }

    if (clearBtn) {
        clearBtn.onclick = () => {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            clearBtn.style.display = 'none';
            renderCards('');
        };
    }
}
