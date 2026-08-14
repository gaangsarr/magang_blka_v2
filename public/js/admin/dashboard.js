document.addEventListener('DOMContentLoaded', async () => {
    // 1. Cek Auth Admin
    const statusRes = await fetch('/api/admin/status.php');
    const statusData = await statusRes.json();
    
    if (!statusRes.ok || !statusData.authenticated) {
        window.location.href = '/admin/login.html';
        return;
    }
    
    const adminNameElem = document.getElementById('admin-name');
    if (adminNameElem && statusData.admin && statusData.admin.nama) {
        adminNameElem.innerText = statusData.admin.nama;
    }

    // 2. Load Dashboard Stats & Charts Data
    try {
        const res = await fetch('/api/admin/dashboard.php');
        const json = await res.json();
        
        if (res.ok && json.data) {
            document.getElementById('stat-pendaftar').innerText = json.data.total_pendaftar;
            document.getElementById('stat-kuota-total').innerText = json.data.total_kuota;
            document.getElementById('stat-kuota-tersisa').innerText = json.data.sisa_kuota;

            const periodeNama = json.periode_nama ? json.periode_nama : 'Tidak Ada Periode Aktif';
            const badgeJ = document.getElementById('badge-periode-jurusan');
            const badgeU = document.getElementById('badge-periode-unit');
            if (badgeJ) badgeJ.innerText = periodeNama;
            if (badgeU) badgeU.innerText = periodeNama;
            
            renderJurusanChart(json.data.sebaran_jurusan || []);
            renderUnitChart(json.data.top_unit || []);
        }
    } catch (err) {
        console.error('Gagal memuat data dashboard:', err);
    }
});

const chartPalette = [
    '#0b3d6b', '#0284c7', '#10b981', '#f59e0b', 
    '#6366f1', '#8b5cf6', '#ec4899', '#14b8a6', 
    '#f97316', '#3b82f6'
];

function renderJurusanChart(dataList) {
    const ctx = document.getElementById('jurusanChart').getContext('2d');
    
    if (!dataList || dataList.length === 0) {
        new Chart(ctx, {
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
    const bgColors = dataList.map((_, i) => chartPalette[i % chartPalette.length]);

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Jumlah Pendaftar',
                data: counts,
                backgroundColor: bgColors,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
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

function renderUnitChart(dataList) {
    const ctx = document.getElementById('unitChart').getContext('2d');
    
    if (!dataList || dataList.length === 0) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Belum ada data unit'],
                datasets: [{ label: 'Jumlah Peminat', data: [0], backgroundColor: '#cbd5e1' }]
            },
            options: { indexAxis: 'y', responsive: true, scales: { x: { beginAtZero: true } } }
        });
        return;
    }

    const labels = dataList.map(item => item.unit);
    const counts = dataList.map(item => item.jumlah);

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Jumlah Peminat',
                data: counts,
                backgroundColor: '#0284c7',
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
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
