import { showAdminAlert, showAdminConfirm } from './common.js';

document.addEventListener('DOMContentLoaded', () => {
    initKelolaAdmin();
});

function initKelolaAdmin() {
    loadKelolaData();

    const formAddAdmin = document.getElementById('form-add-admin') || document.getElementById('form-add-dosen');
    if (formAddAdmin) {
        formAddAdmin.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = document.getElementById('add-email').value.trim();
            const nama = document.getElementById('add-nama').value.trim();
            const role = document.getElementById('add-role').value;

            if (!email || !nama) return;

            try {
                const res = await fetch('/api/admin/kelola/assign.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: email, nama: nama, role: role })
                });
                const data = await res.json();
                if (data.ok) {
                    await showAdminAlert(data.message || 'Hak akses admin berhasil ditambahkan.', 'success');
                    formAddAdmin.reset();
                    loadKelolaData();
                } else {
                    await showAdminAlert(data.error || 'Gagal menambahkan admin.', 'error');
                }
            } catch (err) {
                console.error(err);
                await showAdminAlert('Kesalahan jaringan saat menambahkan admin.', 'error');
            }
        });
    }
}

async function loadKelolaData() {
    try {
        const res = await fetch('/api/admin/kelola/list.php');
        if (res.status === 403) {
            await showAdminAlert('Akses ditolak. Halaman ini khusus untuk Super Admin.', 'error');
            window.location.href = '/admin/pengaturan.html';
            return;
        }
        if (res.status === 401) {
            window.location.href = '/admin/login.html';
            return;
        }

        const data = await res.json();

        if (data.ok) {
            if (data.current_admin) {
                const nameEl = document.getElementById('admin-name');
                if (nameEl) nameEl.innerText = data.current_admin.nama || 'Super Admin';
            }

            renderAdminTable(data.admins, data.current_admin);
        } else {
            await showAdminAlert(data.error || 'Gagal memuat data kelola admin.', 'error');
        }
    } catch (err) {
        console.error('[kelola-admin.js] Error:', err);
    }
}

function renderAdminTable(admins, currentAdmin) {
    const tbody = document.getElementById('table-admin');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!admins || admins.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 24px;">Belum ada akun admin terdaftar.</td></tr>';
        return;
    }

    admins.forEach((a, idx) => {
        const isSuper = (a.role === 'super_admin' || a.role === 'superadmin');
        const roleBadge = isSuper
            ? '<span class="badge-status" style="background: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe; font-weight: 700;">Super Admin</span>'
            : '<span class="badge-status badge-dibuka">Admin BLKA</span>';

        const isSelf = currentAdmin && (parseInt(currentAdmin.id) === parseInt(a.admin_id));
        const statusActive = a.aktif == 1;

        let actionBtn = '-';
        if (!isSelf && statusActive) {
            actionBtn = `
                <button class="btn-role-action btn-role-revoke btn-revoke-admin" data-id="${a.admin_id}" data-nama="${escapeHtml(a.admin_nama || 'Admin')}" title="Cabut Akses Admin">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="22" y2="13"/><line x1="22" y1="8" x2="17" y2="13"/></svg>
                    <span>Cabut Akses</span>
                </button>
            `;
        } else if (isSelf) {
            actionBtn = '<span style="font-size: 0.775rem; color: #10b981; font-weight: 700; background: #ecfdf5; padding: 4px 10px; border-radius: 12px; border: 1px solid #a7f3d0;">Akun Anda</span>';
        } else if (!statusActive) {
            actionBtn = '<span style="font-size: 0.775rem; color: #94a3b8;">Nonaktif</span>';
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td style="font-weight: 700; color: #0b3d6b;">${escapeHtml(a.admin_nama || 'Admin')}</td>
            <td style="font-family: monospace;">${escapeHtml(a.admin_email || '-')}</td>
            <td>${roleBadge}</td>
            <td style="font-size: 0.85rem; color: #64748b;">${escapeHtml(a.created_at || '-')}</td>
            <td style="text-align: center;">${actionBtn}</td>
        `;
        tbody.appendChild(tr);
    });

    document.querySelectorAll('.btn-revoke-admin').forEach(btn => {
        btn.addEventListener('click', () => {
            const aid = btn.getAttribute('data-id');
            const nama = btn.getAttribute('data-nama');
            revokeAdmin(aid, nama);
        });
    });
}

async function revokeAdmin(adminId, nama) {
    const isConfirmed = await showAdminConfirm(
        `Apakah Anda yakin ingin mencabut hak akses admin milik ${nama}?`,
        'Cabut Akses Admin',
        'danger',
        'Ya, Cabut Akses',
        'Batal'
    );
    if (!isConfirmed) return;

    try {
        const res = await fetch('/api/admin/kelola/revoke.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ admin_id: adminId })
        });
        const data = await res.json();

        if (data.ok) {
            await showAdminAlert(data.message || 'Hak akses admin berhasil dicabut.', 'success');
            loadKelolaData();
        } else {
            await showAdminAlert(data.error || 'Gagal mencabut akses admin.', 'error');
        }
    } catch (err) {
        console.error(err);
        await showAdminAlert('Kesalahan jaringan saat mencabut akses admin.', 'error');
    }
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
