let csrfToken = null;
let jurusanChartInstance = null;
let unitChartInstance = null;
let statusChartInstance = null;

function escapeHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', async () => {
    // Auth check
    const statusRes = await fetch('/api/admin/status.php');
    const statusData = await statusRes.json();
    if (statusData.csrf_token) csrfToken = statusData.csrf_token;
    if (!statusRes.ok || !statusData.authenticated) {
        window.location.href = '/admin/login.html';
        return;
    }

    const urlParams = new URLSearchParams(window.location.search);
    const periodeId = urlParams.get('periode_id');

    if (!periodeId) {
        alert('Parameter periode_id tidak ditemukan.');
        window.location.href = '/admin/histori.html';
        return;
    }

    // Export button handler
    const btnExport = document.getElementById('btn-export-histori');
    if (btnExport) {
        btnExport.addEventListener('click', () => {
            window.location.href = `/api/admin/histori/export.php?periode_id=${periodeId}`;
        });
    }

    await loadHistoriDetail(periodeId);
});

async function loadHistoriDetail(periodeId) {
    try {
        const res = await fetch(`/api/admin/histori/detail.php?periode_id=${periodeId}`);
        const data = await res.json();

        if (data.ok) {
            // Header
            document.getElementById('judul-periode').innerText = `Histori: ${data.periode.nama}`;
            document.getElementById('subjudul-periode').innerText = `Status Periode: ${(data.periode.status || 'DRAFT').toUpperCase()} | Tanggal Mulai: ${data.periode.tanggal_mulai || '-'} — ${data.periode.tanggal_selesai || '-'}`;

            // Stat Cards
            document.getElementById('stat-total').innerText = data.stats.total;
            document.getElementById('stat-diterima').innerText = data.stats.diterima !== undefined ? data.stats.diterima : data.stats.diverifikasi;
            document.getElementById('stat-ditolak').innerText = data.stats.ditolak;
            document.getElementById('stat-dipindahkan').innerText = data.stats.dipindahkan;

            // Render Charts (isolated so network/CDN issues won't block table render)
            try {
                if (typeof Chart !== 'undefined') {
                    renderJurusanChart(data.per_jurusan);
                    renderUnitChart(data.top_units);
                    renderStatusChart(data.per_status);
                }
            } catch (chartErr) {
                console.warn('Gagal memuat grafik:', chartErr);
            }

            // Table
            const tbody = document.getElementById('table-pendaftar');
            tbody.innerHTML = '';

            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #64748b; padding: 24px;">Belum ada data pendaftar pada periode ini.</td></tr>';
                return;
            }

            data.data.forEach((r, idx) => {
                const progText = r.program === '1_bulan' ? 'Magang 1 Bulan' : 'Magang 5 Bulan (KRS)';
                const isMoved = (r.is_dipindahkan == 1 || r.status === 'dipindahkan');

                let statusBadge = '<span class="badge-status badge-draft">Diajukan</span>';
                if (isMoved) {
                    statusBadge = '<span class="badge-status" style="background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; font-weight: 700;">Dipindahkan</span>';
                } else if (r.status === 'diverifikasi' || r.status === 'diterima') {
                    statusBadge = '<span class="badge-status badge-dibuka">Diterima</span>';
                } else if (r.status === 'ditolak') {
                    statusBadge = '<span class="badge-status badge-ditutup">Ditolak</span>';
                }

                const unitDisplay = (r.unit_asal_nama && r.unit_asal_nama !== r.unit_nama)
                    ? `<span style="font-size: 0.8rem; color: #64748b; text-decoration: line-through;">${escapeHtml(r.unit_asal_nama)}</span><br><span style="color: #4338ca; font-weight: 600;">➔ ${escapeHtml(r.unit_nama)}</span>`
                    : escapeHtml(r.unit_nama || '-');

                const tr = document.createElement('tr');
                if (isMoved) {
                    tr.style.backgroundColor = 'rgba(99, 102, 241, 0.04)';
                }
                tr.innerHTML = `
                    <td>${idx + 1}</td>
                    <td style="font-family: monospace;">${escapeHtml(r.nim || '-')}</td>
                    <td style="font-weight: 700; color: #0b3d6b;">${escapeHtml(r.nama || '-')}</td>
                    <td>${escapeHtml(r.jurusan || '-')}</td>
                    <td>${progText}</td>
                    <td>${unitDisplay}</td>
                    <td>${statusBadge}</td>
                    <td style="font-size: 0.85rem; color: #475569;">${escapeHtml(r.alamat_domisili || '-')}</td>
                    <td style="font-size: 0.85rem; color: #64748b;">${escapeHtml(r.submitted_at || '-')}</td>
                `;
                tbody.appendChild(tr);
            });
        } else {
            alert(data.error || 'Gagal memuat detail histori.');
        }
    } catch (err) {
        console.error(err);
        alert('Kesalahan jaringan saat memuat detail histori.');
    }
}

function renderJurusanChart(perJurusan) {
    const ctx = document.getElementById('jurusanChart')?.getContext('2d');
    if (!ctx) return;

    if (jurusanChartInstance) jurusanChartInstance.destroy();

    const labels = Object.keys(perJurusan);
    const counts = Object.values(perJurusan);

    jurusanChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: ['#0b3d6b', '#0284c7', '#38bdf8', '#818cf8', '#a78bfa', '#f43f5e', '#fb7185', '#34d399'],
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
            }
        }
    });
}

function renderUnitChart(topUnits) {
    const ctx = document.getElementById('unitChart')?.getContext('2d');
    if (!ctx) return;

    if (unitChartInstance) unitChartInstance.destroy();

    const labels = Object.keys(topUnits);
    const counts = Object.values(topUnits);

    unitChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Pendaftar',
                data: counts,
                backgroundColor: '#0b3d6b',
                borderRadius: 6
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
                x: { beginAtZero: true, ticks: { precision: 0 } }
            }
        }
    });
}

function renderStatusChart(perStatus) {
    const ctx = document.getElementById('statusChart')?.getContext('2d');
    if (!ctx) return;

    if (statusChartInstance) statusChartInstance.destroy();

    const labels = Object.keys(perStatus || {});
    const counts = Object.values(perStatus || {});

    const labelColorMap = {
        'Diajukan': '#f59e0b',
        'Diterima': '#10b981',
        'Ditolak': '#ef4444',
        'Dipindahkan': '#6366f1'
    };
    const colors = labels.map(lbl => labelColorMap[lbl] || '#94a3b8');

    statusChartInstance = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: colors,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
            }
        }
    });
}
