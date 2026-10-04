import { initSidebar, updateSidebarProfile } from '/js/admin/sidebar.js';

let profileInFlightPromise = null;

// Auto render sidebar immediately or on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
        loadAdminProfileHeader();
    });
} else {
    initSidebar();
    loadAdminProfileHeader();
}

export async function loadAdminProfileHeader() {
    if (profileInFlightPromise) {
        return profileInFlightPromise;
    }

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

    // 2. Fetch from server if not cached (singleton promise)
    profileInFlightPromise = (async () => {
        try {
            const res = await fetch('/api/admin/me.php');
            if (res.status === 401) {
                sessionStorage.removeItem('admin_profile');
                if (!window.location.pathname.includes('login.html')) {
                    window.location.href = '/admin/login.html';
                }
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
        } finally {
            profileInFlightPromise = null;
        }
        return null;
    })();

    return profileInFlightPromise;
}

function applyAdminProfile(adm) {
    if (!adm) return;

    const nameEl = document.getElementById('admin-name');
    const roleEl = document.getElementById('admin-role');
    const avatarEl = document.getElementById('admin-avatar');

    if (nameEl) nameEl.innerText = adm.nama || 'Admin';
    if (roleEl) roleEl.innerText = adm.role_label || 'Admin BLKA';
    if (avatarEl) {
        const initial = adm.nama ? adm.nama.trim().charAt(0).toUpperCase() : 'A';
        avatarEl.innerText = initial;
    }

    // Update sidebar footer profile and super admin filter
    updateSidebarProfile(adm);

    const currentPath = window.location.pathname.toLowerCase();

    // Khusus Admin Perusahaan: jika berada di panel admin kampus, arahkan ke portal perusahaan
    if (adm.is_perusahaan) {
        if (!currentPath.startsWith('/perusahaan/') && !currentPath.includes('login.html')) {
            window.location.href = '/perusahaan/index.html';
            return;
        }
    }

    // Khusus Admin Non-Super: jangan izinkan akses ke menu pengaturan & kelola-admin
    if (!adm.is_super && !adm.is_perusahaan) {
        if (currentPath.includes('pengaturan') || currentPath.includes('kelola-admin')) {
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
        msgEl.innerHTML = message;

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
        msgEl.innerHTML = message;

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

export function showAdminToast(message, type = 'info', duration = 3500) {
    let container = document.getElementById('admin-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'admin-toast-container';
        document.body.appendChild(container);
    }
    container.className = 'admin-toast-container';
    container.style.cssText = 'position: fixed !important; top: 24px !important; right: 24px !important; z-index: 999999 !important; display: flex !important; flex-direction: column !important; gap: 10px !important; pointer-events: none !important; max-width: calc(100vw - 48px) !important;';

    const icons = {
        success: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`,
        error: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`,
        warning: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
        info: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`
    };

    const bgColors = {
        success: '#047857',
        error: '#b91c1c',
        warning: '#d97706',
        info: '#0b3d6b'
    };
    const bg = bgColors[type] || '#0b3d6b';
    const iconSvg = icons[type] || icons.info;

    const toast = document.createElement('div');
    toast.className = `admin-toast ${type}`;
    toast.style.cssText = `background: ${bg}; color: #ffffff; padding: 12px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,0.18); pointer-events: auto; animation: adminToastSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); display: flex; align-items: center; gap: 10px; border: 1px solid rgba(255,255,255,0.2);`;
    toast.innerHTML = `<span style="display: inline-flex; align-items: center; flex-shrink: 0;">${iconSvg}</span><span style="flex: 1; line-height: 1.4;">${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-12px) scale(0.95)';
        toast.style.transition = 'all 0.25s ease';
        setTimeout(() => toast.remove(), 260);
    }, duration);
}

// Expose globally on window so legacy / existing scripts can use them seamlessly
window.showAdminAlert = showAdminAlert;
window.showAdminConfirm = showAdminConfirm;
window.showAdminToast = showAdminToast;
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


