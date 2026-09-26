/**
 * auth.js — Direct Microsoft Azure Entra ID (SSO ITPLN) Authentication
 */

const btnLogin = document.getElementById('btn-login-microsoft');
const errorBox = document.getElementById('error-box');

// 1. Periksa session aktif saat halaman landing dimuat
async function checkExistingSession() {
  try {
    const res = await fetch('/api/auth/status.php', {
      method: 'GET',
      credentials: 'same-origin',
    });

    if (!res.ok) return;

    const data = await res.json();

    if (data.authenticated) {
      // Jika pengguna adalah Admin (BLKA/Super Admin/Perusahaan), arahkan langsung ke portal admin
      if (data.role === 'admin') {
        const targetUrl = data.redirect_to || (data.admin_role === 'admin_perusahaan' ? '/perusahaan/index.html' : '/admin/index.html');
        window.location.replace(targetUrl);
        return;
      }
      const urlParams = new URLSearchParams(window.location.search);
      const returnTo = urlParams.get('return_to');
      if (returnTo && returnTo.startsWith('/') && !returnTo.startsWith('//')) {
        window.location.replace(returnTo);
        return;
      }
      updateLandingPageForLoggedInUser(data);
    }
  } catch (_) {}
}

function escapeHtml(str) {
  if (!str && str !== 0) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function updateLandingPageForLoggedInUser(data) {
  window.isLoggedInUser = true;

  const user = data.user;
  const shortName = user && user.nama ? user.nama.split(' ')[0] : 'Mahasiswa';
  const initial = user && user.nama ? user.nama.charAt(0).toUpperCase() : 'M';
  const isEligible = data.is_eligible_pendaftaran_aktif !== false && data.is_eligible_angkatan !== false;
  const adaPeriodeDibuka = data.ada_periode_dibuka === true;
  const sudahMendaftar = data.sudah_mendaftar === true;
  const hasRiwayat = data.has_riwayat_pendaftaran === true;

  // Update navbar links
  document.querySelectorAll('a').forEach(link => {
    const text = link.textContent.trim();
    if (text === 'Pendaftaran') {
      if (!adaPeriodeDibuka && !hasRiwayat) {
        link.href = '#';
        link.addEventListener('click', (e) => {
          e.preventDefault();
          showNoPeriodeModal();
        });
      } else if (sudahMendaftar) {
        link.href = '/status.html';
      } else {
        link.href = '/daftar.html';
      }
    } else if (text === 'Pengumuman' || text === 'Riwayat & Pengumuman') {
      link.href = '/status.html';
    }
  });

  // Update Hero Button
  if (btnLogin) {
    if (!adaPeriodeDibuka) {
      if (hasRiwayat) {
        btnLogin.innerHTML = `
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          <span>Lihat Riwayat & Pengumuman</span>
        `;
        btnLogin.style.background = '';
        const newBtn = btnLogin.cloneNode(true);
        btnLogin.parentNode.replaceChild(newBtn, btnLogin);
        newBtn.addEventListener('click', () => { window.location.href = '/status.html'; });
      } else {
        btnLogin.innerHTML = `
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span>Belum Ada Periode Pendaftaran Dibuka</span>
        `;
        btnLogin.style.background = '#64748b';
        const newBtn = btnLogin.cloneNode(true);
        btnLogin.parentNode.replaceChild(newBtn, btnLogin);
        newBtn.addEventListener('click', () => {
          showNoPeriodeModal();
        });
      }
    } else {
      if (sudahMendaftar) {
        btnLogin.innerHTML = `
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          <span>Lihat Status Pendaftaran</span>
        `;
        btnLogin.style.background = '';
        const newBtn = btnLogin.cloneNode(true);
        btnLogin.parentNode.replaceChild(newBtn, btnLogin);
        newBtn.addEventListener('click', () => { window.location.href = '/status.html'; });
      } else if (!isEligible) {
        if (hasRiwayat) {
          btnLogin.innerHTML = `
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            <span>Lihat Riwayat & Pengumuman</span>
          `;
          btnLogin.style.background = '';
          const newBtn = btnLogin.cloneNode(true);
          btnLogin.parentNode.replaceChild(newBtn, btnLogin);
          newBtn.addEventListener('click', () => { window.location.href = '/status.html'; });
        } else {
          btnLogin.innerHTML = `
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>Pendaftaran Khusus Angkatan ${escapeHtml(data.angkatan_eligible || '')}</span>
          `;
          btnLogin.style.background = '#0b3d6b';
          const newBtn = btnLogin.cloneNode(true);
          btnLogin.parentNode.replaceChild(newBtn, btnLogin);
          newBtn.addEventListener('click', () => { window.location.href = '/daftar.html'; });
        }
      } else {
        btnLogin.innerHTML = `
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          <span>Lanjut ke Pendaftaran (${escapeHtml(data.periode_aktif ? data.periode_aktif.nama : 'Periode Baru')})</span>
        `;
        btnLogin.style.background = '';
        const newBtn = btnLogin.cloneNode(true);
        btnLogin.parentNode.replaceChild(newBtn, btnLogin);
        newBtn.addEventListener('click', () => { window.location.href = '/daftar.html'; });
      }
    }
  }

  // Profile Dropdown Navbar Desktop
  const navbarAction = document.querySelector('.navbar-action');
  if (navbarAction) {
    navbarAction.style.display = 'flex';
    navbarAction.innerHTML = `
      <div class="navbar-profile-wrapper">
        <button type="button" class="nav-profile-btn" id="navProfileBtn" aria-label="Profil Saya" aria-expanded="false">
          <div class="profile-avatar-circle" id="profileAvatarCircle">${initial}</div>
          <span class="profile-short-name" id="profileShortName">${shortName}</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="profile-chevron"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div class="profile-dropdown-card" id="profileDropdownCard">
          <div class="profile-card-header">
            <div class="profile-card-avatar">${initial}</div>
            <div class="profile-card-user">
              <h4 class="profile-card-name">${user ? user.nama : 'Mahasiswa'}</h4>
              <span class="profile-card-badge">${isEligible ? 'Mahasiswa ITPLN' : 'Angkatan Tidak Memenuhi Syarat'}</span>
            </div>
          </div>

          <div class="profile-card-divider"></div>

          <div class="profile-info-list">
            <div class="profile-info-item">
              <span class="profile-info-label">NIM</span>
              <span class="profile-info-value">${user ? user.nim : '-'}</span>
            </div>
            <div class="profile-info-item">
              <span class="profile-info-label">Jurusan</span>
              <span class="profile-info-value">${user ? user.jurusan : '-'}</span>
            </div>
            <div class="profile-info-item">
              <span class="profile-info-label">Angkatan</span>
              <span class="profile-info-value">${user ? user.angkatan : '-'}</span>
            </div>
            <div class="profile-info-item">
              <span class="profile-info-label">Email</span>
              <span class="profile-info-value">${user ? user.email : '-'}</span>
            </div>
          </div>

          <div class="profile-card-divider"></div>

          <div class="profile-card-actions">
            <button type="button" class="btn-profile-logout" id="btnLandingProfileLogout">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              <span>Keluar (Logout)</span>
            </button>
          </div>
        </div>
      </div>
    `;

    const navProfileBtn = document.getElementById('navProfileBtn');
    const profileDropdownCard = document.getElementById('profileDropdownCard');
    if (navProfileBtn && profileDropdownCard) {
      navProfileBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const expanded = navProfileBtn.getAttribute('aria-expanded') === 'true';
        navProfileBtn.setAttribute('aria-expanded', !expanded);
        profileDropdownCard.classList.toggle('show');
      });

      document.addEventListener('click', (e) => {
        if (!navProfileBtn.contains(e.target) && !profileDropdownCard.contains(e.target)) {
          profileDropdownCard.classList.remove('show');
          navProfileBtn.setAttribute('aria-expanded', 'false');
        }
      });
    }

    const btnLandingProfileLogout = document.getElementById('btnLandingProfileLogout');
    if (btnLandingProfileLogout) {
      btnLandingProfileLogout.addEventListener('click', async () => {
        try {
          await fetch('/api/auth/logout.php', { method: 'POST', credentials: 'same-origin' });
        } catch (_) {}
        sessionStorage.clear();
        localStorage.clear();
        window.location.reload();
      });
    }
  }

  // Mobile Drawer Profile Card
  const mobileProfile = document.getElementById('mobileDrawerProfile');
  if (mobileProfile) {
    mobileProfile.style.display = 'flex';
    const mobileAvatar = document.getElementById('mobileDrawerAvatar');
    const mobileName = document.getElementById('mobileDrawerUserName');
    const mobileMeta = document.getElementById('mobileDrawerUserMeta');
    if (mobileAvatar) mobileAvatar.textContent = initial;
    if (mobileName) mobileName.textContent = user ? user.nama : 'Mahasiswa';
    if (mobileMeta) mobileMeta.textContent = (user && user.nim ? user.nim : '-') + (user && user.jurusan ? ' • ' + user.jurusan : '');
  }

  // Mobile Action Logout Button
  const mobileMenuAction = document.querySelector('.mobile-menu-action');
  if (mobileMenuAction) {
    mobileMenuAction.style.marginTop = '10px';
    mobileMenuAction.innerHTML = `
      <button type="button" class="btn-mobile-masuk" style="background:#dc2626;" id="btnMobileLogout">
        Keluar (Logout)
      </button>
    `;
    const btnMobileLogout = document.getElementById('btnMobileLogout');
    if (btnMobileLogout) {
      btnMobileLogout.addEventListener('click', async () => {
        try {
          await fetch('/api/auth/logout.php', { method: 'POST', credentials: 'same-origin' });
        } catch (_) {}
        sessionStorage.clear();
        localStorage.clear();
        window.location.reload();
      });
    }
  }
}

function showNoPeriodeModal() {
  const modal = document.getElementById('modalNoPeriode');
  if (modal) {
    modal.classList.remove('hidden');
  } else {
    alert('Saat ini belum ada periode pendaftaran magang yang dibuka.');
  }
}

function showAuthLoading(title = 'Menghubungkan Akun...', desc = 'Mengalihkan ke halaman autentikasi Microsoft ITPLN...') {
  const overlay = document.getElementById('authLoadingOverlay');
  const titleEl = document.getElementById('authLoadingTitle');
  const descEl = document.getElementById('authLoadingDesc');

  if (titleEl) titleEl.innerText = title;
  if (descEl) descEl.innerText = desc;
  if (overlay) {
    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');
  }
  setLoading(true);
}

function setLoading(isLoading) {
  if (!btnLogin) return;
  if (isLoading) {
    btnLogin.classList.add('loading');
    btnLogin.disabled = true;
    btnLogin.innerHTML = `
      <span class="btn-hero-spinner"></span>
      <span>Mengalihkan...</span>
    `;
  } else {
    btnLogin.classList.remove('loading');
    btnLogin.disabled = false;
    btnLogin.innerHTML = `
      <svg width="20" height="20" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="1" y="1" width="9" height="9" fill="#F25022"/>
        <rect x="11" y="1" width="9" height="9" fill="#7FBA00"/>
        <rect x="1" y="11" width="9" height="9" fill="#00A4EF"/>
        <rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
      </svg>
      <span>Masuk</span>
    `;
  }
}

function showError(message) {
  if (!errorBox) return;
  errorBox.textContent = message;
  errorBox.classList.add('visible');
  errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearError() {
  if (!errorBox) return;
  errorBox.textContent = '';
  errorBox.classList.remove('visible');
}

// 2. Alur Login Microsoft Azure Entra ID (Direct Server-Side OAuth Redirect)
function handleMicrosoftLogin() {
  clearError();
  showAuthLoading('Menghubungkan Akun ITPLN...', 'Mengalihkan ke server autentikasi Microsoft SSO...');
  const urlParams = new URLSearchParams(window.location.search);
  const returnTo = urlParams.get('return_to');
  const targetUrl = (returnTo && returnTo.startsWith('/') && !returnTo.startsWith('//'))
    ? '/api/auth/azure/login.php?return_to=' + encodeURIComponent(returnTo)
    : '/api/auth/azure/login.php';
  setTimeout(() => {
    window.location.href = targetUrl;
  }, 200);
}

if (btnLogin) {
  btnLogin.addEventListener('click', handleMicrosoftLogin);
}

// 3. Tangkap pesan error dari redirect callback Microsoft jika ada (?error=...)
const urlParams = new URLSearchParams(window.location.search);
const errorParam = urlParams.get('error');
if (errorParam) {
  showError(decodeURIComponent(errorParam));
  // Bersihkan parameter query dari URL address bar tanpa reload
  if (window.history.replaceState) {
    window.history.replaceState({}, document.title, window.location.pathname);
  }
}

// 4. Cek session yang sudah ada
checkExistingSession();
