/**
 * auth.js — Firebase Auth: Microsoft provider login
 */

import { initializeApp }         from 'https://www.gstatic.com/firebasejs/11.2.0/firebase-app.js';
import { getAuth, signInWithPopup, OAuthProvider, signOut }
  from 'https://www.gstatic.com/firebasejs/11.2.0/firebase-auth.js';

const DEFAULT_CONFIG = {
  apiKey:            '',
  authDomain:        '',
  projectId:         '',
  storageBucket:     '',
  messagingSenderId: '',
  appId:             '',
};

const firebaseConfig = (typeof window !== 'undefined' && window.FIREBASE_CONFIG)
  ? window.FIREBASE_CONFIG
  : DEFAULT_CONFIG;

let app, auth;

try {
  app  = initializeApp(firebaseConfig);
  auth = getAuth(app);
  auth.languageCode = 'id';
} catch (err) {
  console.error('[auth.js] Firebase init gagal:', err);
  showError('Konfigurasi Firebase belum lengkap. Hubungi administrator.');
}

const msProvider = new OAuthProvider('microsoft.com');
msProvider.addScope('openid');
msProvider.addScope('profile');
msProvider.addScope('email');

const tenantId = firebaseConfig.azureTenantId;
if (tenantId) {
  msProvider.setCustomParameters({ tenant: tenantId });
}

const btnLogin   = document.getElementById('btn-login-microsoft');
const errorBox   = document.getElementById('error-box');

async function checkExistingSession() {
  try {
    const res = await fetch('/api/auth/status.php', {
      method: 'GET',
      credentials: 'same-origin',
    });

    if (!res.ok) return;

    const data = await res.json();

    if (data.authenticated) {
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

  let targetUrl = '/daftar.html';
  let labelText = 'Lanjut ke Pendaftaran';


  if (!adaPeriodeDibuka) {
    if (hasRiwayat) {
      targetUrl = '/status.html';
      labelText = 'Lihat Riwayat & Pengumuman';
    } else {
      targetUrl = '#';
      labelText = 'Pendaftaran Belum Dibuka';
    }
  } else {
    if (sudahMendaftar) {
      targetUrl = '/status.html';
      labelText = 'Lihat Status Pendaftaran';
    } else {
      targetUrl = '/daftar.html';
      labelText = 'Lanjut ke Pendaftaran (' + (data.periode_aktif ? data.periode_aktif.nama : 'Periode Baru') + ')';
    }
  }

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
    }
 else {
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



  // Profile Dropdown
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

  // Mobile Drawer Profile Card (matches status.html & daftar.html)
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

  // Mobile Action (Identical to status.html & daftar.html)
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

function alertIneligibleAngkatan(data) {

  const modal = document.getElementById('modalIneligibleAngkatan');
  const textEligible = document.getElementById('textModalEligibleAngkatan');
  const textMhs = document.getElementById('textModalMhsAngkatan');
  const btnLogout = document.getElementById('btnModalLogoutIneligible');

  if (textEligible) textEligible.innerText = data.angkatan_eligible || '-';
  if (textMhs) textMhs.innerText = data.mhs_angkatan || '-';

  if (btnLogout) {
    btnLogout.onclick = async () => {
      try {
        await fetch('/api/auth/logout.php', { method: 'POST', credentials: 'same-origin' });
      } catch (_) {}
      sessionStorage.clear();
      localStorage.clear();
      window.location.reload();
    };
  }

  if (modal) {
    modal.classList.remove('hidden');
  } else {
    alert(`Mohon maaf, pendaftaran periode magang saat ini hanya dibuka untuk Mahasiswa Angkatan (${data.angkatan_eligible}).\n\nAngkatan Anda (${data.mhs_angkatan}) belum / tidak diizinkan untuk mendaftar.`);
  }
}

function showAuthLoading(title = 'Menghubungkan Akun...', desc = 'Membuka jendela autentikasi Microsoft SSO Kampus...') {
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

function updateAuthLoading(title, desc) {
  const titleEl = document.getElementById('authLoadingTitle');
  const descEl = document.getElementById('authLoadingDesc');
  if (titleEl && title) titleEl.innerText = title;
  if (descEl && desc) descEl.innerText = desc;
}

function hideAuthLoading() {
  const overlay = document.getElementById('authLoadingOverlay');
  if (overlay) {
    overlay.classList.remove('active');
    overlay.setAttribute('aria-hidden', 'true');
  }
  setLoading(false);
}

async function handleMicrosoftLogin() {
  if (!auth) {
    showError('Firebase belum siap. Coba refresh halaman.');
    return;
  }

  clearError();
  showAuthLoading('Menghubungkan Akun...', 'Membuka jendela autentikasi Microsoft SSO Kampus...');

  try {
    const result = await signInWithPopup(auth, msProvider);

    // Popup selesai diotorisasi, sekarang verifikasi token & database session
    updateAuthLoading('Memverifikasi Identitas...', 'Mencocokkan data akun kampus (@itpln.ac.id) dengan sistem...');

    const user   = result.user;
    const idToken = await user.getIdToken(false);

    const verifyRes = await fetch('/api/auth/verify.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify({ id_token: idToken }),
    });

    const verifyData = await verifyRes.json();

    if (!verifyRes.ok || verifyData.error) {
      await signOut(auth);
      hideAuthLoading();
      showError(verifyData.error ?? 'Login gagal. Coba lagi.');
      return;
    }

    updateAuthLoading('Autentikasi Berhasil!', 'Menyiapkan sesi & mengalihkan halaman...');

    // Transisi halus sebelum redirect
    setTimeout(() => {
      redirect(verifyData);
    }, 400);

  } catch (err) {
    hideAuthLoading();
    if (err.code === 'auth/popup-closed-by-user' || err.code === 'auth/cancelled-popup-request') {
      // User menutup popup secara sengaja, batalkan tanpa error
      return;
    }
    if (err.code === 'auth/popup-blocked') {
      showError('Pop-up diblokir browser. Izinkan pop-up untuk situs ini dan coba lagi.');
      return;
    }
    console.error('[auth.js] Login error:', err);
    showError('Terjadi kesalahan saat login. Coba lagi atau hubungi Tim REMATE ITPLN.');
  }
}

function redirect(serverResponse) {
  if (serverResponse.is_admin && serverResponse.redirect_url) {
    window.location.href = serverResponse.redirect_url;
    return;
  }
  if (serverResponse.sudah_mendaftar) {
    window.location.href = '/status.html';
    return;
  }
  if (serverResponse.needs_nama) {
    window.location.href = '/daftar.html?needs_nama=1';
    return;
  }
  window.location.href = '/daftar.html';
}

function setLoading(isLoading) {
  if (!btnLogin) return;
  if (isLoading) {
    btnLogin.classList.add('loading');
    btnLogin.disabled = true;
    btnLogin.innerHTML = `
      <span class="btn-hero-spinner"></span>
      <span>Menghubungkan...</span>
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
}

function clearError() {
  if (!errorBox) return;
  errorBox.textContent = '';
  errorBox.classList.remove('visible');
}

if (btnLogin) {
  btnLogin.addEventListener('click', handleMicrosoftLogin);
}

checkExistingSession();
