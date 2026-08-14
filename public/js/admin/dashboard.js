/**
 * public/js/admin/dashboard.js
 * Executive Dashboard Analytics & Monitoring Component for REMATE
 */

let chartHierarkiInstance = null;
let chartPeminatanInstance = null;
let chartJurusanInstance = null;

const PALETTE = {
    navy: '#0b3d6b',
    blue: '#0284c7',
    emerald: '#10b981',
    amber: '#f59e0b',
    purple: '#8b5cf6',
    rose: '#f43f5e',
    cyan: '#06b6d4',
    slate: '#64748b'
};

const CHART_COLORS = [
    '#0b3d6b', '#0284c7', '#10b981', '#f59e0b', 
    '#6366f1', '#8b5cf6', '#ec4899', '#14b8a6', 
    '#f97316', '#3b82f6'
];

document.addEventListener('DOMContentLoaded', async () => {
    // 1. Setup Print Action
    const printBtn = document.getElementById('btn-print-dashboard');
    if (printBtn) {
        printBtn.addEventListener('click', () => {
            window.print();
        });
    }

    // 2. Load Dashboard Executive Data
    await loadDashboardData();
});

async function loadDashboardData() {
    try {
        const res = await fetch('/api/admin/dashboard.php');
        const json = await res.json();
        
        if (!res.ok || !json.ok || !json.data) {
            console.warn('Data dashboard tidak ditemukan atau kosong');
            return;
        }

        const p = json.periode || {};
        const d = json.data;

        // --- A. Render Top Executive Banner ---
        renderExecutiveBanner(p);

        // --- B. Render KPI Summary & Okupansi Kuota ---
        renderExecutiveKPI(d);

        // --- C. Render Supply & Demand Tables ---
        renderUnitPenuhTable(d.unit_penuh || []);
        renderUnitUnderTable(d.unit_butuh_mahasiswa || []);

        // --- D. Render Strategic Charts ---
        renderHierarkiChart(d.sebaran_hierarki || []);
        renderPeminatanChart(d.sebaran_peminatan || []);
        renderJurusanChart(d.sebaran_jurusan || []);

    } catch (err) {
        console.error('Gagal memuat data dashboard:', err);
    }
}

function renderExecutiveBanner(p) {
    const badgeStatus = document.getElementById('exec-status-periode');
    const titlePeriode = document.getElementById('exec-periode-nama');
    const jadwalPeriode = document.getElementById('exec-periode-jadwal');
    const countdownPill = document.getElementById('exec-countdown-pill');
    const announcePill = document.getElementById('exec-announcement-pill');
    const announceText = document.getElementById('exec-announcement-text');

    if (!p || !p.id) {
        if (badgeStatus) {
            badgeStatus.className = 'exec-periode-badge badge-closed';
            badgeStatus.innerText = 'TIDAK ADA PERIODE AKTIF';
        }
        if (titlePeriode) titlePeriode.innerText = 'Tidak Ada Gelombang Aktif';
        if (jadwalPeriode) jadwalPeriode.innerHTML = '<span>Pendaftaran: Tidak ada gelombang dibuka</span>';
        if (countdownPill) {
            countdownPill.className = 'exec-countdown-pill pill-countdown-closed';
            countdownPill.innerText = 'Pendaftaran Nonaktif';
        }
        if (announcePill && announceText) {
            announcePill.className = 'exec-announcement-pill pill-announce-draft';
            announceText.innerText = 'TIDAK ADA JADWAL';
        }
        return;
    }

    // Status Periode Badge
    if (badgeStatus) {
        if (p.status === 'dibuka') {
            badgeStatus.className = 'exec-periode-badge badge-active';
            badgeStatus.innerText = 'GELOMBANG DIBUKA';
        } else if (p.status === 'persiapan') {
            badgeStatus.className = 'exec-periode-badge badge-prep';
            badgeStatus.innerText = 'MASA PERSIAPAN';
        } else {
            badgeStatus.className = 'exec-periode-badge badge-closed';
            badgeStatus.innerText = 'GELOMBANG DITUTUP';
        }
    }

    // Judul Periode
    if (titlePeriode) titlePeriode.innerText = p.nama || 'Periode Magang';

    // Jadwal
    if (jadwalPeriode && p.tanggal_mulai && p.tanggal_selesai) {
        const startStr = formatDateIndo(p.tanggal_mulai);
        const endStr = formatDateIndo(p.tanggal_selesai);
        jadwalPeriode.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Pendaftaran: <strong>${startStr}</strong> s/d <strong>${endStr}</strong></span>
        `;
    }

    // Countdown Pill
    if (countdownPill) {
        if (p.is_daftar_aktif) {
            countdownPill.className = 'exec-countdown-pill pill-countdown-active';
            countdownPill.innerText = `Sisa ${p.sisa_hari_daftar} Hari Pendaftaran`;
        } else if (p.status === 'persiapan') {
            countdownPill.className = 'exec-countdown-pill pill-countdown-closed';
            countdownPill.innerText = 'Masa Persiapan Gelombang';
        } else if (p.status === 'dibuka' && p.sisa_hari_daftar === 0) {
            countdownPill.className = 'exec-countdown-pill pill-countdown-urgent';
            countdownPill.innerText = 'Hari Terakhir Pendaftaran';
        } else {
            countdownPill.className = 'exec-countdown-pill pill-countdown-closed';
            countdownPill.innerText = 'Pendaftaran Ditutup';
        }
    }

    // Announcement Pill
    if (announcePill && announceText) {
        if (p.pengumuman_dibuka) {
            announcePill.className = 'exec-announcement-pill pill-announce-live';
            announceText.innerText = 'SUDAH DIPUBLIKASIKAN';
        } else {
            announcePill.className = 'exec-announcement-pill pill-announce-draft';
            announceText.innerText = 'BELUM DIPUBLIKASIKAN';
        }
    }
}

function renderExecutiveKPI(d) {
    // 1. Total Pendaftar
    const pendaftarEl = document.getElementById('stat-pendaftar');
    if (pendaftarEl) pendaftarEl.innerText = (d.total_pendaftar || 0).toLocaleString('id-ID');

    // 2. Okupansi Kuota
    const persenOkupansiEl = document.getElementById('stat-okupansi-persen');
    const kuotaRatioEl = document.getElementById('stat-kuota-ratio');
    const progressBarEl = document.getElementById('exec-progress-bar');
    const kuotaTerisiEl = document.getElementById('stat-kuota-terisi');
    const kuotaSisaEl = document.getElementById('stat-kuota-tersisa');

    const totalK = d.total_kuota || 0;
    const terisiK = d.terisi_kuota || 0;
    const sisaK = d.sisa_kuota || 0;
    const persen = d.persen_okupansi || 0;

    if (persenOkupansiEl) persenOkupansiEl.innerText = `${persen}%`;
    if (kuotaRatioEl) kuotaRatioEl.innerText = `${terisiK.toLocaleString('id-ID')} / ${totalK.toLocaleString('id-ID')} Kuota`;
    if (kuotaTerisiEl) kuotaTerisiEl.innerText = terisiK.toLocaleString('id-ID');
    if (kuotaSisaEl) kuotaSisaEl.innerText = sisaK.toLocaleString('id-ID');
    
    if (progressBarEl) {
        const clampedPersen = Math.min(100, Math.max(0, persen));
        progressBarEl.style.width = `${clampedPersen}%`;
        if (clampedPersen >= 90) {
            progressBarEl.style.background = '#ef4444';
        } else if (clampedPersen >= 70) {
            progressBarEl.style.background = '#f59e0b';
        } else {
            progressBarEl.style.background = '#10b981';
        }
    }

    // 3. Status Breakdown
    const st = d.status_counts || {};
    const bdDiterima = document.getElementById('bd-diterima');
    const bdDipindahkan = document.getElementById('bd-dipindahkan');
    const bdMenunggu = document.getElementById('bd-menunggu');
    const bdDitolak = document.getElementById('bd-ditolak');

    if (bdDiterima) bdDiterima.innerText = (st.diterima || 0).toLocaleString('id-ID');
    if (bdDipindahkan) bdDipindahkan.innerText = (st.dipindahkan || 0).toLocaleString('id-ID');
    if (bdMenunggu) bdMenunggu.innerText = (st.menunggu || 0).toLocaleString('id-ID');
    if (bdDitolak) bdDitolak.innerText = (st.ditolak || 0).toLocaleString('id-ID');
}

function renderUnitPenuhTable(list) {
    const tbody = document.getElementById('tbody-unit-penuh');
    const countBadge = document.getElementById('badge-unit-penuh-count');
    if (!tbody) return;

    if (countBadge) countBadge.innerText = `${list.length} Unit`;

    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted" style="padding: 24px;">Tidak ada unit yang penuh 100%.</td></tr>';
        return;
    }

    tbody.innerHTML = list.map(item => `
        <tr>
            <td style="font-weight: 600; color: #0b3d6b;">${escapeHtml(item.nama)}</td>
            <td><span class="badge-hierarki-mini">${formatHierarchyType(item.tipe)}</span></td>
            <td style="text-align: right; font-weight: 700;">${item.kuota_total}</td>
            <td style="text-align: center;">
                <span class="badge-status-pill pill-full">100% Penuh</span>
            </td>
        </tr>
    `).join('');
}

function renderUnitUnderTable(list) {
    const tbody = document.getElementById('tbody-unit-under');
    const countBadge = document.getElementById('badge-unit-under-count');
    if (!tbody) return;

    if (countBadge) countBadge.innerText = `${list.length} Unit`;

    if (!list || list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted" style="padding: 24px;">Semua kuota unit telah terpenuhi.</td></tr>';
        return;
    }

    tbody.innerHTML = list.map(item => `
        <tr>
            <td style="font-weight: 600; color: #0b3d6b;">${escapeHtml(item.nama)}</td>
            <td><span class="badge-hierarki-mini">${formatHierarchyType(item.tipe)}</span></td>
            <td style="text-align: right; font-size: 0.85rem; color: #64748b;">${item.terisi} / ${item.kuota_total}</td>
            <td style="text-align: right; font-weight: 700; color: #10b981; font-size: 0.95rem;">
                ${item.kuota_tersisa} Kursi Tersedia
            </td>
        </tr>
    `).join('');
}

function renderHierarkiChart(dataList) {
    const canvas = document.getElementById('hierarkiChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    if (chartHierarkiInstance) chartHierarkiInstance.destroy();

    const labels = dataList.map(d => d.label);
    const counts = dataList.map(d => d.jumlah_unit);
    const totalUnit = counts.reduce((a, b) => a + b, 0);

    if (totalUnit === 0) {
        chartHierarkiInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Belum ada unit magang'],
                datasets: [{ data: [1], backgroundColor: ['#e2e8f0'] }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        return;
    }

    chartHierarkiInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: [
                    '#0b3d6b', // Holding
                    '#0284c7', // Subholding
                    '#8b5cf6', // Anak Perusahaan
                    '#10b981', // Unit Induk
                    '#f59e0b'  // Unit Pelaksana
                ],
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 10,
                        font: { size: 11, weight: '600' }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const val = context.raw || 0;
                            const pct = totalUnit > 0 ? Math.round((val / totalUnit) * 100) : 0;
                            return ` ${context.label}: ${val} Kantor (${pct}%)`;
                        }
                    }
                }
            },
            cutout: '62%'
        }
    });
}

function renderPeminatanChart(dataList) {
    const canvas = document.getElementById('peminatanChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    if (chartPeminatanInstance) chartPeminatanInstance.destroy();

    if (!dataList || dataList.length === 0) {
        chartPeminatanInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Belum ada data peminatan'],
                datasets: [{ label: 'Peminat', data: [0], backgroundColor: '#cbd5e1' }]
            },
            options: { indexAxis: 'y', responsive: true, scales: { x: { beginAtZero: true } } }
        });
        return;
    }

    const labels = dataList.map(item => item.peminatan);
    const counts = dataList.map(item => item.jumlah);

    chartPeminatanInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Jumlah Mahasiswa',
                data: counts,
                backgroundColor: '#0284c7',
                borderRadius: 6,
                maxBarThickness: 22
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                },
                y: {
                    ticks: {
                        font: { size: 11, weight: '600' }
                    }
                }
            }
        }
    });
}

function renderJurusanChart(dataList) {
    const canvas = document.getElementById('jurusanChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    if (chartJurusanInstance) chartJurusanInstance.destroy();
    
    if (!dataList || dataList.length === 0) {
        chartJurusanInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Belum ada pendaftar'],
                datasets: [{ label: 'Jumlah Pendaftar', data: [0], backgroundColor: '#cbd5e1' }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
        return;
    }

    const labels = dataList.map(item => item.jurusan);
    const counts = dataList.map(item => item.jumlah);
    const bgColors = dataList.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]);

    chartJurusanInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Jumlah Pendaftar',
                data: counts,
                backgroundColor: bgColors,
                borderRadius: 6,
                maxBarThickness: 32
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                },
                x: {
                    ticks: {
                        font: { size: 11, weight: '600' }
                    }
                }
            }
        }
    });
}

// --- Helpers ---
function formatHierarchyType(tipe) {
    const map = {
        'holding': 'Holding',
        'subholding': 'Subholding',
        'anak_perusahaan': 'Anak Perusahaan',
        'unit_induk': 'Unit Induk',
        'unit_pelaksana': 'Unit Pelaksana'
    };
    return map[tipe] || tipe;
}

function formatDateIndo(dateStr) {
    if (!dateStr) return '-';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const d = parseInt(parts[2], 10);
    const m = parseInt(parts[1], 10) - 1;
    const y = parts[0];
    return `${d} ${months[m] || ''} ${y}`;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

