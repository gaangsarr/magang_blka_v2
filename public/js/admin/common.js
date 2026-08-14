/**
 * public/js/admin/common.js
 * Auto-load logged-in admin profile in topbar across all admin pages
 */

document.addEventListener('DOMContentLoaded', () => {
    loadAdminProfileHeader();
    setupNavGroups();

    // Clear cache on logout click
    const logoutBtn = document.querySelector('.nav-link-logout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', () => {
            sessionStorage.removeItem('admin_profile');
        });
    }
});

function setupNavGroups() {
    document.querySelectorAll('.nav-group').forEach(group => {
        const header = group.querySelector('.nav-group-header');
        if (header) {
            header.addEventListener('click', () => {
                group.classList.toggle('collapsed');
            });
        }
    });
}

export async function loadAdminProfileHeader() {
    // 1. Try to load from sessionStorage cache
    const cachedProfile = sessionStorage.getItem('admin_profile');
    if (cachedProfile) {
        try {
            const adm = JSON.parse(cachedProfile);
            applyAdminProfile(adm);
            return adm;
        } catch (e) {
            sessionStorage.removeItem('admin_profile');
        }
    }

    // 2. Fetch from server if not cached
    try {
        const res = await fetch('/api/admin/me.php');
        if (res.status === 401) {
            sessionStorage.removeItem('admin_profile');
            window.location.href = '/admin/login.html';
            return null;
        }

        const data = await res.json();
        if (data.ok && data.admin) {
            const adm = data.admin;
            sessionStorage.setItem('admin_profile', JSON.stringify(adm));
            applyAdminProfile(adm);
            return adm;
        }
    } catch (err) {
        console.error('[common.js] Error loading profile:', err);
    }
    return null;
}

function applyAdminProfile(adm) {
    const nameEl = document.getElementById('admin-name');
    const roleEl = document.getElementById('admin-role');
    const avatarEl = document.getElementById('admin-avatar');

    if (nameEl) nameEl.innerText = adm.nama || 'Admin';
    if (roleEl) roleEl.innerText = adm.role_label || 'Admin BLKA';
    if (avatarEl) {
        const initial = adm.nama ? adm.nama.trim().charAt(0).toUpperCase() : 'A';
        avatarEl.innerText = initial;
    }

    if (!adm.is_super) {
        const settingsLink = Array.from(document.querySelectorAll('.sidebar-nav a.nav-link')).find(a => a.href.includes('pengaturan.html'));
        if (settingsLink) {
            settingsLink.remove();
        }
        const path = window.location.pathname;
        if (path.includes('pengaturan') || path.includes('kelola-admin')) {
            window.location.href = '/admin/index.html';
        }
    }
}

/* ==========================================================================
   CUSTOM ADMIN MODAL & CONFIRM DIALOG SYSTEM
   ========================================================================== */

function getOrCreateOverlay() {
    let overlay = document.getElementById('admin-global-modal-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'admin-global-modal-overlay';
        overlay.className = 'admin-modal-overlay';
        overlay.innerHTML = `
            <div class="admin-modal-card">
                <div class="admin-modal-icon-wrapper" id="admin-modal-icon"></div>
                <h3 class="admin-modal-title" id="admin-modal-title"></h3>
                <p class="admin-modal-message" id="admin-modal-message"></p>
                <div class="admin-modal-actions" id="admin-modal-actions"></div>
            </div>
        `;
        document.body.appendChild(overlay);
    }
    return overlay;
}

function getIconSvg(type) {
    switch (type) {
        case 'success':
            return `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`;
        case 'error':
        case 'danger':
            return `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
        case 'warning':
            return `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;
        case 'info':
        default:
            return `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`;
    }
}

export function showAdminAlert(message, type = 'info', title = null) {
    return new Promise((resolve) => {
        const overlay = getOrCreateOverlay();
        const iconWrapper = document.getElementById('admin-modal-icon');
        const titleEl = document.getElementById('admin-modal-title');
        const msgEl = document.getElementById('admin-modal-message');
        const actionsEl = document.getElementById('admin-modal-actions');

        const defaultTitles = {
            success: 'Berhasil!',
            error: 'Terjadi Kesalahan',
            warning: 'Peringatan',
            info: 'Informasi'
        };

        iconWrapper.className = `admin-modal-icon-wrapper ${type}`;
        iconWrapper.innerHTML = getIconSvg(type);
        titleEl.innerText = title || defaultTitles[type] || 'Informasi';
        msgEl.innerText = message;

        const btnClass = type === 'error' ? 'danger' : 'primary';
        actionsEl.innerHTML = `<button type="button" class="admin-modal-btn ${btnClass}" id="btn-modal-ok">OK</button>`;

        overlay.classList.add('active');

        const okBtn = document.getElementById('btn-modal-ok');
        const closeHandler = () => {
            overlay.classList.remove('active');
            okBtn.removeEventListener('click', closeHandler);
            resolve();
        };
        okBtn.addEventListener('click', closeHandler);
    });
}

export function showAdminConfirm(message, title = 'Konfirmasi Aksi', type = 'warning', confirmText = 'Ya, Lanjutkan', cancelText = 'Batal') {
    return new Promise((resolve) => {
        const overlay = getOrCreateOverlay();
        const iconWrapper = document.getElementById('admin-modal-icon');
        const titleEl = document.getElementById('admin-modal-title');
        const msgEl = document.getElementById('admin-modal-message');
        const actionsEl = document.getElementById('admin-modal-actions');

        iconWrapper.className = `admin-modal-icon-wrapper ${type}`;
        iconWrapper.innerHTML = getIconSvg(type);
        titleEl.innerText = title;
        msgEl.innerText = message;

        const confirmBtnClass = type === 'danger' || type === 'error' ? 'danger' : 'primary';

        actionsEl.innerHTML = `
            <button type="button" class="admin-modal-btn secondary" id="btn-modal-cancel">${cancelText}</button>
            <button type="button" class="admin-modal-btn ${confirmBtnClass}" id="btn-modal-confirm">${confirmText}</button>
        `;

        overlay.classList.add('active');

        const cancelBtn = document.getElementById('btn-modal-cancel');
        const confirmBtn = document.getElementById('btn-modal-confirm');

        const handleCancel = () => {
            overlay.classList.remove('active');
            cleanup();
            resolve(false);
        };

        const handleConfirm = () => {
            overlay.classList.remove('active');
            cleanup();
            resolve(true);
        };

        const cleanup = () => {
            cancelBtn.removeEventListener('click', handleCancel);
            confirmBtn.removeEventListener('click', handleConfirm);
        };

        cancelBtn.addEventListener('click', handleCancel);
        confirmBtn.addEventListener('click', handleConfirm);
    });
}

// Expose globally on window so legacy / existing scripts can use them seamlessly
window.showAdminAlert = showAdminAlert;
window.showAdminConfirm = showAdminConfirm;
window.showAlert = showAdminAlert;
window.showConfirm = showAdminConfirm;

// Override browser default alert & confirm for a 100% unified, premium UI/UX experience
window.alert = function(message) {
    showAdminAlert(message, 'info');
};

window.confirm = function(message) {
    console.warn('[common.js] Legacy confirm() called synchronously. Use await showAdminConfirm() for proper promise resolution.');
    return true; // Fallback for un-awaited legacy sync confirm calls
};

