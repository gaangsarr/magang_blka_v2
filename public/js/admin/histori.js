let csrfToken = null;

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

    await loadHistori();
});

async function loadHistori() {
    const grid = document.getElementById('histori-grid');
    try {
        const res = await fetch('/api/admin/histori/list.php');
        const data = await res.json();

        if (data.ok) {
            grid.innerHTML = '';
            if (data.data.length === 0) {
                grid.innerHTML = `
                    <div style="text-align: center; color: #64748b; padding: 40px; grid-column: 1 / -1; background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0;">
                        Belum ada data histori periode lama. Periode yang sedang dibuka dapat dilihat pada menu Data Pendaftar.
                    </div>`;
                return;
            }

            data.data.forEach(p => {
                let badgeClass = 'badge-ditutup';
                let badgeText = 'Ditutup';
                if (p.status === 'dibuka') {
                    badgeClass = 'badge-dibuka';
                    badgeText = 'Aktif (Dibuka)';
                } else if (p.status === 'draft') {
                    badgeClass = 'badge-draft';
                    badgeText = 'Draft';
                } else if (p.status === 'diarsipkan') {
                    badgeClass = 'badge-ditutup';
                    badgeText = 'Diarsipkan';
                }

                const card = document.createElement('div');
                card.className = 'admin-card';
                card.style.margin = '0';
                card.style.display = 'flex';
                card.style.flexDirection = 'column';
                card.style.justify = 'space-between';

                card.innerHTML = `
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <h3 style="font-size: 1.1rem; color: #0b3d6b; margin: 0; font-weight: 700;">${escapeHtml(p.nama)}</h3>
                            <span class="badge-status ${badgeClass}">${badgeText}</span>
                        </div>
                        
                        <div style="font-size: 0.85rem; color: #64748b; margin-bottom: 16px; line-height: 1.6;">
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                                <span><strong>Mulai:</strong> ${p.tanggal_mulai || '-'}</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px; margin-top: 4px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>
                                <span><strong>Selesai:</strong> ${p.tanggal_selesai || '-'}</span>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; background: #f8fafc; padding: 12px; border-radius: 10px; margin-bottom: 16px;">
                            <div style="flex: 1; text-align: center;">
                                <div style="font-size: 1.25rem; font-weight: 700; color: #0b3d6b;">${p.total_pendaftar || 0}</div>
                                <div style="font-size: 0.75rem; color: #64748b;">Pendaftar</div>
                            </div>
                            <div style="flex: 1; text-align: center; border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;">
                                <div style="font-size: 1.25rem; font-weight: 700; color: #10b981;">${p.total_diterima || 0}</div>
                                <div style="font-size: 0.75rem; color: #64748b;">Diterima</div>
                            </div>
                            <div style="flex: 1; text-align: center;">
                                <div style="font-size: 1.25rem; font-weight: 700; color: #ef4444;">${p.total_ditolak || 0}</div>
                                <div style="font-size: 0.75rem; color: #64748b;">Ditolak</div>
                            </div>
                        </div>
                    </div>

                    <a href="/admin/histori-detail.html?periode_id=${p.id}" 
                       class="btn btn-primary" 
                       style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; text-decoration: none; padding: 10px; border-radius: 10px; font-weight: 600; text-align: center; background: #0b3d6b; color: #ffffff;">
                       <span>Lihat Detail Histori</span>
                       <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                `;
                grid.appendChild(card);
            });
        }
    } catch (err) {
        grid.innerHTML = '<div style="color: #ef4444; padding: 24px; text-align: center; grid-column: 1 / -1;">Gagal memuat histori.</div>';
    }
}
