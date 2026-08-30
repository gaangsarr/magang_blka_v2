/**
 * public/js/admin/sidebar.js
 * Modular Dynamic Sidebar Component for REMATE Admin Portal
 */

export const SIDEBAR_MENU_SECTIONS = [
    {
        section: 'OVERVIEW',
        items: [
            {
                id: 'menu-dashboard',
                label: 'Dashboard',
                path: '/admin/index.html',
                aliases: ['/admin/', '/admin/index.html'],
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>`
            }
        ]
    },
    {
        section: 'PROGRAM & SELEKSI MAGANG',
        items: [
            {
                id: 'menu-periode',
                label: 'Periode Magang',
                path: '/admin/periode.html',
                aliases: ['/admin/periode.html'],
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>`
            },
            {
                id: 'menu-unit',
                label: 'Kuota Unit Magang',
                path: '/admin/unit.html',
                aliases: ['/admin/unit.html'],
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>`
            },
            {
                id: 'menu-pendaftar',
                label: 'Data Pendaftar',
                path: '/admin/pendaftar.html',
                aliases: ['/admin/pendaftar.html'],
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`
            },
            {
                id: 'menu-penetapan',
                label: 'Penetapan Unit',
                path: '/admin/penetapan.html',
                aliases: ['/admin/penetapan.html'],
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`
            },
            {
                id: 'menu-hasilkan-surat',
                label: 'Hasilkan Surat',
                path: '/admin/hasilkan-surat.html',
                aliases: ['/admin/hasilkan-surat.html'],
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`
            }
        ]
    },
    {
        section: 'MASTER DATA & HIERARKI',
        items: [
            {
                id: 'menu-peminatan',
                label: 'Master Peminatan',
                path: '/admin/peminatan.html',
                aliases: ['/admin/peminatan.html'],
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg>`
            },
            {
                id: 'group-hierarki',
                label: 'Hierarki Entitas PLN',
                isGroup: true,
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>`,
                subitems: [
                    {
                        label: 'Holding (Pusat)',
                        path: '/admin/holding.html',
                        aliases: ['/admin/holding.html']
                    },
                    {
                        label: 'Subholding',
                        path: '/admin/subholding.html',
                        aliases: ['/admin/subholding.html']
                    },
                    {
                        label: 'Anak Perusahaan',
                        path: '/admin/anak-perusahaan.html',
                        aliases: ['/admin/anak-perusahaan.html']
                    },
                    {
                        label: 'Unit Induk (UID/UIW)',
                        path: '/admin/unit-induk.html',
                        aliases: ['/admin/unit-induk.html']
                    },
                    {
                        label: 'Unit Pelaksana (UP3/UPDL)',
                        path: '/admin/unit-pelaksana.html',
                        aliases: ['/admin/unit-pelaksana.html']
                    },
                    {
                        label: 'Unit Layanan (ULP)',
                        path: '/admin/unit-layanan.html',
                        aliases: ['/admin/unit-layanan.html']
                    }
                ]
            }
        ]
    },
    {
        section: 'SISTEM & AUDIT',
        items: [
            {
                id: 'menu-histori',
                label: 'Log Aktivitas',
                path: '/admin/histori.html',
                aliases: ['/admin/histori.html', '/admin/histori-detail.html'],
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`
            },
            {
                id: 'menu-kelola-admin',
                label: 'Kelola Admin',
                path: '/admin/kelola-admin.html',
                aliases: ['/admin/kelola-admin.html'],
                superAdminOnly: true,
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`
            },
            {
                id: 'menu-pengaturan',
                label: 'Pengaturan Sistem',
                path: '/admin/pengaturan.html',
                aliases: ['/admin/pengaturan.html'],
                superAdminOnly: true,
                icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>`
            }
        ]
    }
];

/**
 * Initializes and renders the dynamic sidebar
 */
export function initSidebar() {
    const aside = document.getElementById('admin-sidebar') || document.querySelector('.admin-sidebar');
    if (!aside) return;

    const currentPath = window.location.pathname.toLowerCase();

    let navHtml = '';

    SIDEBAR_MENU_SECTIONS.forEach(section => {
        navHtml += `
            <div class="sidebar-nav-section">
                <div class="sidebar-section-title">${section.section}</div>
                <div class="sidebar-section-items">
        `;

        section.items.forEach(item => {
            if (item.isGroup) {
                // Check if any sub-item matches current path
                const hasActiveChild = item.subitems.some(sub => 
                    sub.aliases.some(alias => currentPath === alias.toLowerCase() || (alias !== '/admin/' && currentPath.endsWith(alias.toLowerCase())))
                );

                const groupCollapsedClass = hasActiveChild ? '' : 'collapsed';

                let subitemsHtml = '';
                item.subitems.forEach(sub => {
                    const isSubActive = sub.aliases.some(alias => 
                        currentPath === alias.toLowerCase() || (alias !== '/admin/' && currentPath.endsWith(alias.toLowerCase()))
                    );
                    const subActiveClass = isSubActive ? 'active' : '';

                    subitemsHtml += `
                        <a href="${sub.path}" class="nav-sublink ${subActiveClass}" data-path="${sub.path}">
                            <span class="subnav-dot"></span>
                            <span>${sub.label}</span>
                        </a>
                    `;
                });

                navHtml += `
                    <div class="nav-group ${groupCollapsedClass}" id="${item.id}">
                        <div class="nav-group-header">
                            <div class="nav-group-header-left">
                                ${item.icon}
                                <span>${item.label}</span>
                            </div>
                            <svg class="nav-group-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                        <div class="nav-subitems">
                            ${subitemsHtml}
                        </div>
                    </div>
                `;
            } else {
                const isActive = item.aliases.some(alias => 
                    currentPath === alias.toLowerCase() || (alias !== '/admin/' && currentPath.endsWith(alias.toLowerCase()))
                );
                const activeClass = isActive ? 'active' : '';
                const superAdminAttr = item.superAdminOnly ? 'data-super-admin-only="true"' : '';

                navHtml += `
                    <a href="${item.path}" class="nav-link ${activeClass}" id="${item.id}" ${superAdminAttr}>
                        ${item.icon}
                        <span>${item.label}</span>
                    </a>
                `;
            }
        });

        navHtml += `
                </div>
            </div>
        `;
    });

    aside.innerHTML = `
        <!-- Sidebar Brand Header -->
        <div class="sidebar-header">
            <a href="/admin/index.html" class="sidebar-brand-link">
                <img src="/assets/img/logo_itpln.png" alt="Logo ITPLN" class="sidebar-logo" onerror="this.style.display='none'" />
                <div class="sidebar-brand-info">
                    <div class="sidebar-brand-title">
                        <span>REMATE</span>
                    </div>
                    <span class="sidebar-subtitle">Rekrutmen Magang Talenta Energi</span>
                </div>
            </a>
        </div>

        <!-- Sidebar Scrollable Navigation -->
        <nav class="sidebar-nav custom-scrollbar">
            ${navHtml}
        </nav>

        <!-- Sidebar User Profile & Quick Actions -->
        <div class="sidebar-footer">
            <div class="sidebar-user-card">
                <div class="sidebar-avatar" id="sidebar-admin-avatar">A</div>
                <div class="sidebar-user-meta">
                    <div class="sidebar-user-name" id="sidebar-admin-name">Admin REMATE</div>
                    <div class="sidebar-user-role" id="sidebar-admin-role">Administrator</div>
                </div>
                <a href="/api/admin/logout.php" class="btn-sidebar-logout" title="Keluar dari Sistem">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </a>
            </div>
        </div>
    `;

    // Bind Accordion Click Handlers
    aside.querySelectorAll('.nav-group').forEach(group => {
        const header = group.querySelector('.nav-group-header');
        if (header) {
            header.addEventListener('click', () => {
                group.classList.toggle('collapsed');
            });
        }
    });

    // Handle Sidebar Scroll Position Retention & Active Item In-View
    const navEl = aside.querySelector('.sidebar-nav');
    const SCROLL_KEY = 'remate_admin_sidebar_scroll';

    if (navEl) {
        // Restore previous scroll position if available
        const savedScroll = sessionStorage.getItem(SCROLL_KEY);
        if (savedScroll !== null) {
            navEl.scrollTop = parseInt(savedScroll, 10);
        }

        // Ensure active item is always visible within the sidebar viewport
        const activeEl = aside.querySelector('.nav-link.active, .nav-sublink.active');
        if (activeEl) {
            const navRect = navEl.getBoundingClientRect();
            const activeRect = activeEl.getBoundingClientRect();
            if (activeRect.top < navRect.top || activeRect.bottom > navRect.bottom) {
                activeEl.scrollIntoView({ block: 'nearest' });
            }
        }

        // Save scroll position on scroll
        navEl.addEventListener('scroll', () => {
            sessionStorage.setItem(SCROLL_KEY, navEl.scrollTop);
        }, { passive: true });

        // Save scroll position when any link is clicked
        aside.querySelectorAll('.nav-link, .nav-sublink, .sidebar-brand-link').forEach(link => {
            link.addEventListener('click', () => {
                sessionStorage.setItem(SCROLL_KEY, navEl.scrollTop);
            });
        });
    }

    // Handle logout click cache clear
    const logoutBtn = aside.querySelector('.btn-sidebar-logout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', () => {
            sessionStorage.removeItem('admin_profile');
            sessionStorage.removeItem('remate_admin_sidebar_scroll');
        });
    }
}

/**
 * Updates profile metadata in sidebar and filters super admin links
 */
export function updateSidebarProfile(adminData) {
    if (!adminData) return;

    const nameEl = document.getElementById('sidebar-admin-name') || document.getElementById('admin-name');
    const roleEl = document.getElementById('sidebar-admin-role') || document.getElementById('admin-role');
    const avatarEl = document.getElementById('sidebar-admin-avatar') || document.getElementById('admin-avatar');

    if (nameEl) nameEl.innerText = adminData.nama || 'Admin';
    if (roleEl) roleEl.innerText = adminData.role_label || (adminData.role === 'super_admin' ? 'Super Admin REMATE' : 'Admin REMATE');
    if (avatarEl) {
        const initial = adminData.nama ? adminData.nama.trim().charAt(0).toUpperCase() : 'A';
        avatarEl.innerText = initial;
    }

    // Filter Super Admin links if not super admin
    if (!adminData.is_super && adminData.role !== 'super_admin') {
        document.querySelectorAll('[data-super-admin-only="true"]').forEach(el => {
            el.remove();
        });
    }
}
