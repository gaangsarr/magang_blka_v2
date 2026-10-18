import { showAdminAlert } from './common.js';

let csrfToken = null;
let currentAdmin = null;

document.addEventListener('DOMContentLoaded', async () => {
    // Auth check
    const statusRes = await fetch('/api/admin/status.php');
    const statusData = await statusRes.json();
    if (statusData.csrf_token) csrfToken = statusData.csrf_token;
    if (!statusRes.ok || !statusData.authenticated) {
        window.location.href = '/admin/login.html';
        return;
    }

    currentAdmin = statusData.admin || statusData;
    const adminRole = currentAdmin.role || statusData.role || statusData.admin_role || '';
    const isSuper = Boolean(statusData.is_super || currentAdmin.is_super || adminRole === 'super_admin' || adminRole === 'superadmin');

    // Sembunyikan Kelola Admin, Notifikasi Email & Keamanan jika bukan Super Admin
    if (!isSuper) {
        const cardKelola = document.getElementById('card-kelola-admin');
        if (cardKelola) cardKelola.style.display = 'none';

        const cardEmail = document.getElementById('card-notifikasi-email');
        if (cardEmail) cardEmail.style.display = 'none';

        const cardKeamanan = document.getElementById('card-keamanan-admin');
        if (cardKeamanan) cardKeamanan.style.display = 'none';
    } else {
        setupSuperAdminPasswordListeners();
        loadSuperAdminAccountInfo();
    }

    // Load current settings
    await loadSettings();

    // Setup event listeners for Dokumen & Panduan
    setupDokumenListeners();

    // Form submit: Syarat Magang 5 Bulan
    const formPengaturan = document.getElementById('form-pengaturan');
    if (formPengaturan) {
        formPengaturan.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btn-save');
            const statusEl = document.getElementById('save-status');
            btn.disabled = true;
            btn.innerText = 'Menyimpan...';
            statusEl.style.display = 'none';

            const payload = {
                min_ipk_5bulan: document.getElementById('min_ipk').value,
                min_sks_5bulan: document.getElementById('min_sks').value,
            };

            try {
                const res = await fetch('/api/admin/pengaturan/update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();

                if (res.ok && data.ok) {
                    statusEl.innerText = 'Tersimpan';
                    statusEl.style.color = '#10b981';
                    statusEl.style.display = 'inline';
                    await showAdminAlert('Pengaturan syarat program 5 bulan berhasil diperbarui!', 'success');
                    setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
                } else {
                    await showAdminAlert(data.error || 'Gagal menyimpan pengaturan.', 'error');
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat menyimpan.', 'error');
            }
            btn.disabled = false;
            btn.innerText = 'Simpan Pengaturan';
        });
    }

    // Form submit for email settings
    const formEmail = document.getElementById('form-notifikasi-email');
    if (formEmail) {
        formEmail.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btn-save-email');
            const statusEl = document.getElementById('save-status-email');
            btn.disabled = true;
            btn.innerText = 'Menyimpan...';
            statusEl.style.display = 'none';

            const payload = {
                email_notifikasi_penolakan: document.getElementById('email_notifikasi_penolakan').checked ? '1' : '0',
                email_notifikasi_pengumuman: document.getElementById('email_notifikasi_pengumuman').checked ? '1' : '0',
                email_notifikasi_submit: document.getElementById('email_notifikasi_submit').checked ? '1' : '0',
            };

            try {
                const res = await fetch('/api/admin/pengaturan/update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();

                if (res.ok && data.ok) {
                    statusEl.innerText = 'Tersimpan';
                    statusEl.style.color = '#10b981';
                    statusEl.style.display = 'inline';
                    await showAdminAlert('Pengaturan notifikasi email berhasil diperbarui!', 'success');
                    setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
                } else {
                    await showAdminAlert(data.error || 'Gagal menyimpan pengaturan email.', 'error');
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat menyimpan.', 'error');
            }
            btn.disabled = false;
            btn.innerText = 'Simpan Pengaturan Email';
        });
    }
});

async function loadSettings() {
    try {
        const res = await fetch('/api/admin/pengaturan/list.php');
        const data = await res.json();

        if (data.ok && data.data) {
            const d = data.data;

            // IPK & SKS
            if (d.min_ipk_5bulan && document.getElementById('min_ipk')) {
                document.getElementById('min_ipk').value = d.min_ipk_5bulan.nilai;
            }
            if (d.min_sks_5bulan && document.getElementById('min_sks')) {
                document.getElementById('min_sks').value = d.min_sks_5bulan.nilai;
            }

            // Email
            if (d.email_notifikasi_penolakan && document.getElementById('email_notifikasi_penolakan')) {
                document.getElementById('email_notifikasi_penolakan').checked = d.email_notifikasi_penolakan.nilai === '1';
            }
            if (d.email_notifikasi_pengumuman && document.getElementById('email_notifikasi_pengumuman')) {
                document.getElementById('email_notifikasi_pengumuman').checked = d.email_notifikasi_pengumuman.nilai === '1';
            }
            if (d.email_notifikasi_submit && document.getElementById('email_notifikasi_submit')) {
                document.getElementById('email_notifikasi_submit').checked = d.email_notifikasi_submit.nilai === '1';
            }

            // Dokumen 1: Pedoman Magang
            const pedomanTipe = (d.doc_pedoman_tipe && d.doc_pedoman_tipe.nilai === 'url') ? 'url' : 'file';
            const radioPedomanFile = document.getElementById('radio-pedoman-file');
            const radioPedomanUrl = document.getElementById('radio-pedoman-url');
            if (pedomanTipe === 'url' && radioPedomanUrl) {
                radioPedomanUrl.checked = true;
            } else if (radioPedomanFile) {
                radioPedomanFile.checked = true;
            }
            togglePedomanSource(pedomanTipe);

            const pedomanFilePath = (d.doc_pedoman_file && d.doc_pedoman_file.nilai) ? d.doc_pedoman_file.nilai : '/assets/docs/pedoman-magang.pdf';
            const pedomanExternalUrl = (d.doc_pedoman_url && d.doc_pedoman_url.nilai) ? d.doc_pedoman_url.nilai : '';
            
            if (document.getElementById('url_pedoman')) document.getElementById('url_pedoman').value = pedomanExternalUrl;
            if (document.getElementById('judul_pedoman')) document.getElementById('judul_pedoman').value = d.doc_pedoman_judul ? d.doc_pedoman_judul.nilai : 'Buku Pedoman Magang';
            if (document.getElementById('meta_pedoman')) document.getElementById('meta_pedoman').value = d.doc_pedoman_meta ? d.doc_pedoman_meta.nilai : 'Panduan Resmi';
            if (document.getElementById('badge_pedoman')) document.getElementById('badge_pedoman').value = d.doc_pedoman_badge ? d.doc_pedoman_badge.nilai : 'PDF';
            if (document.getElementById('btn_text_pedoman')) document.getElementById('btn_text_pedoman').value = d.doc_pedoman_btn_text ? d.doc_pedoman_btn_text.nilai : 'Buka Dokumen PDF';
            if (document.getElementById('deskripsi_pedoman')) document.getElementById('deskripsi_pedoman').value = d.doc_pedoman_deskripsi ? d.doc_pedoman_deskripsi.nilai : '';

            updatePedomanActiveDisplay(pedomanTipe, pedomanFilePath, pedomanExternalUrl);

            // Dokumen 2: Log Book Magang
            const logbookTipe = (d.doc_logbook_tipe && d.doc_logbook_tipe.nilai === 'url') ? 'url' : 'file';
            const radioLogbookFile = document.getElementById('radio-logbook-file');
            const radioLogbookUrl = document.getElementById('radio-logbook-url');
            if (logbookTipe === 'url' && radioLogbookUrl) {
                radioLogbookUrl.checked = true;
            } else if (radioLogbookFile) {
                radioLogbookFile.checked = true;
            }
            toggleLogbookSource(logbookTipe);

            const logbookFilePath = (d.doc_logbook_file && d.doc_logbook_file.nilai) ? d.doc_logbook_file.nilai : '/assets/docs/logbook.docx';
            const logbookExternalUrl = (d.doc_logbook_url && d.doc_logbook_url.nilai) ? d.doc_logbook_url.nilai : '';

            if (document.getElementById('url_logbook')) document.getElementById('url_logbook').value = logbookExternalUrl;
            if (document.getElementById('judul_logbook')) document.getElementById('judul_logbook').value = d.doc_logbook_judul ? d.doc_logbook_judul.nilai : 'Buku Catatan / Log Book';
            if (document.getElementById('meta_logbook')) document.getElementById('meta_logbook').value = d.doc_logbook_meta ? d.doc_logbook_meta.nilai : 'Format Laporan';
            if (document.getElementById('badge_logbook')) document.getElementById('badge_logbook').value = d.doc_logbook_badge ? d.doc_logbook_badge.nilai : 'Word';
            if (document.getElementById('btn_text_logbook')) document.getElementById('btn_text_logbook').value = d.doc_logbook_btn_text ? d.doc_logbook_btn_text.nilai : 'Buka Dokumen';
            if (document.getElementById('deskripsi_logbook')) document.getElementById('deskripsi_logbook').value = d.doc_logbook_deskripsi ? d.doc_logbook_deskripsi.nilai : '';

            updateLogbookActiveDisplay(logbookTipe, logbookFilePath, logbookExternalUrl);
        }
    } catch (err) {
        console.error('Gagal memuat pengaturan:', err);
    }
}

function updatePedomanActiveDisplay(tipe, filePath, externalUrl) {
    const badgeEl = document.getElementById('badge-status-pedoman');
    const infoEl = document.getElementById('info-file-pedoman');
    const btnPreview = document.getElementById('btn-preview-pedoman-admin');

    if (tipe === 'url' && externalUrl) {
        if (badgeEl) {
            badgeEl.innerText = 'Tautan Cloud';
            badgeEl.style.background = '#fef3c7';
            badgeEl.style.color = '#b45309';
            badgeEl.style.borderColor = '#fde68a';
        }
        if (infoEl) {
            infoEl.innerText = externalUrl;
            infoEl.title = externalUrl;
        }
        if (btnPreview) {
            btnPreview.href = externalUrl;
        }
    } else {
        if (badgeEl) {
            badgeEl.innerText = 'PDF Server';
            badgeEl.style.background = '#e0f2fe';
            badgeEl.style.color = '#0369a1';
            badgeEl.style.borderColor = '#bae6fd';
        }
        if (infoEl) {
            infoEl.innerText = filePath;
            infoEl.title = filePath;
        }
        if (btnPreview) {
            btnPreview.href = filePath;
        }
    }
}

function updateLogbookActiveDisplay(tipe, filePath, externalUrl) {
    const badgeEl = document.getElementById('badge-status-logbook');
    const infoEl = document.getElementById('info-file-logbook');
    const btnPreview = document.getElementById('btn-preview-logbook-admin');

    if (tipe === 'url' && externalUrl) {
        if (badgeEl) {
            badgeEl.innerText = 'Tautan Cloud';
            badgeEl.style.background = '#fef3c7';
            badgeEl.style.color = '#b45309';
            badgeEl.style.borderColor = '#fde68a';
        }
        if (infoEl) {
            infoEl.innerText = externalUrl;
            infoEl.title = externalUrl;
        }
        if (btnPreview) {
            btnPreview.href = externalUrl;
        }
    } else {
        if (badgeEl) {
            badgeEl.innerText = 'DOCX Server';
            badgeEl.style.background = '#e0e7ff';
            badgeEl.style.color = '#3730a3';
            badgeEl.style.borderColor = '#c7d2fe';
        }
        if (infoEl) {
            infoEl.innerText = filePath;
            infoEl.title = filePath;
        }
        if (btnPreview) {
            btnPreview.href = filePath;
        }
    }
}

function togglePedomanSource(tipe) {
    const boxUpload = document.getElementById('box-upload-pedoman');
    const boxUrl = document.getElementById('box-url-pedoman');
    if (tipe === 'url') {
        if (boxUpload) boxUpload.style.display = 'none';
        if (boxUrl) boxUrl.style.display = 'block';
    } else {
        if (boxUpload) boxUpload.style.display = 'block';
        if (boxUrl) boxUrl.style.display = 'none';
    }
}

function toggleLogbookSource(tipe) {
    const boxUpload = document.getElementById('box-upload-logbook');
    const boxUrl = document.getElementById('box-url-logbook');
    if (tipe === 'url') {
        if (boxUpload) boxUpload.style.display = 'none';
        if (boxUrl) boxUrl.style.display = 'block';
    } else {
        if (boxUpload) boxUpload.style.display = 'block';
        if (boxUrl) boxUrl.style.display = 'none';
    }
}

function setupDokumenListeners() {
    // 1. Radio changes
    const radioPedomanFile = document.getElementById('radio-pedoman-file');
    const radioPedomanUrl = document.getElementById('radio-pedoman-url');
    if (radioPedomanFile) radioPedomanFile.addEventListener('change', () => togglePedomanSource('file'));
    if (radioPedomanUrl) radioPedomanUrl.addEventListener('change', () => togglePedomanSource('url'));

    const radioLogbookFile = document.getElementById('radio-logbook-file');
    const radioLogbookUrl = document.getElementById('radio-logbook-url');
    if (radioLogbookFile) radioLogbookFile.addEventListener('change', () => toggleLogbookSource('file'));
    if (radioLogbookUrl) radioLogbookUrl.addEventListener('change', () => toggleLogbookSource('url'));

    // 2. Upload Pedoman (.pdf)
    const btnBrowsePedoman = document.getElementById('btn-browse-pedoman');
    const inputFilePedoman = document.getElementById('input-file-pedoman');
    const selectedPedoman = document.getElementById('selected-file-pedoman');
    const btnUploadPedoman = document.getElementById('btn-upload-pedoman');
    const statusUploadPedoman = document.getElementById('upload-status-pedoman');

    if (btnBrowsePedoman && inputFilePedoman) {
        btnBrowsePedoman.addEventListener('click', () => inputFilePedoman.click());
        inputFilePedoman.addEventListener('change', () => {
            if (inputFilePedoman.files && inputFilePedoman.files[0]) {
                const f = inputFilePedoman.files[0];
                if (!f.name.toLowerCase().endsWith('.pdf')) {
                    showAdminAlert('Format file harus PDF (.pdf)', 'warning');
                    inputFilePedoman.value = '';
                    selectedPedoman.innerText = 'Format harus .pdf';
                    btnUploadPedoman.style.display = 'none';
                    return;
                }
                if (f.size > 10 * 1024 * 1024) {
                    showAdminAlert('Ukuran file melebihi 10 MB', 'warning');
                    inputFilePedoman.value = '';
                    selectedPedoman.innerText = 'Ukuran melebihi 10 MB';
                    btnUploadPedoman.style.display = 'none';
                    return;
                }
                const kb = Math.round(f.size / 1024);
                selectedPedoman.innerText = `${f.name} (${kb >= 1024 ? (kb/1024).toFixed(1) + ' MB' : kb + ' KB'})`;
                btnUploadPedoman.style.display = 'inline-block';
                btnUploadPedoman.disabled = false;
            } else {
                selectedPedoman.innerText = 'Belum ada file dipilih';
                btnUploadPedoman.style.display = 'none';
            }
        });
    }

    if (btnUploadPedoman) {
        btnUploadPedoman.addEventListener('click', async () => {
            if (!inputFilePedoman.files || !inputFilePedoman.files[0]) return;
            const file = inputFilePedoman.files[0];
            const formData = new FormData();
            formData.append('doc_type', 'pedoman');
            formData.append('file', file);

            btnUploadPedoman.disabled = true;
            btnUploadPedoman.innerText = 'Mengunggah...';
            statusUploadPedoman.style.display = 'none';

            try {
                const res = await fetch('/api/admin/pengaturan/upload_doc.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    await showAdminAlert('Berkas Buku Pedoman Magang berhasil diperbarui!', 'success');
                    inputFilePedoman.value = '';
                    selectedPedoman.innerText = 'Berkas aktif berhasil diganti';
                    btnUploadPedoman.style.display = 'none';
                    updatePedomanActiveDisplay('file', data.file_path, '');
                } else {
                    await showAdminAlert(data.error || 'Gagal mengunggah berkas pedoman.', 'error');
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat mengunggah berkas.', 'error');
            }
            btnUploadPedoman.disabled = false;
            btnUploadPedoman.innerText = 'Unggah Sekarang';
        });
    }

    // 3. Upload Log Book (.docx)
    const btnBrowseLogbook = document.getElementById('btn-browse-logbook');
    const inputFileLogbook = document.getElementById('input-file-logbook');
    const selectedLogbook = document.getElementById('selected-file-logbook');
    const btnUploadLogbook = document.getElementById('btn-upload-logbook');
    const statusUploadLogbook = document.getElementById('upload-status-logbook');

    if (btnBrowseLogbook && inputFileLogbook) {
        btnBrowseLogbook.addEventListener('click', () => inputFileLogbook.click());
        inputFileLogbook.addEventListener('change', () => {
            if (inputFileLogbook.files && inputFileLogbook.files[0]) {
                const f = inputFileLogbook.files[0];
                if (!f.name.toLowerCase().endsWith('.docx')) {
                    showAdminAlert('Format file harus DOCX (.docx)', 'warning');
                    inputFileLogbook.value = '';
                    selectedLogbook.innerText = 'Format harus .docx';
                    btnUploadLogbook.style.display = 'none';
                    return;
                }
                if (f.size > 10 * 1024 * 1024) {
                    showAdminAlert('Ukuran file melebihi 10 MB', 'warning');
                    inputFileLogbook.value = '';
                    selectedLogbook.innerText = 'Ukuran melebihi 10 MB';
                    btnUploadLogbook.style.display = 'none';
                    return;
                }
                const kb = Math.round(f.size / 1024);
                selectedLogbook.innerText = `${f.name} (${kb >= 1024 ? (kb/1024).toFixed(1) + ' MB' : kb + ' KB'})`;
                btnUploadLogbook.style.display = 'inline-block';
                btnUploadLogbook.disabled = false;
            } else {
                selectedLogbook.innerText = 'Belum ada file dipilih';
                btnUploadLogbook.style.display = 'none';
            }
        });
    }

    if (btnUploadLogbook) {
        btnUploadLogbook.addEventListener('click', async () => {
            if (!inputFileLogbook.files || !inputFileLogbook.files[0]) return;
            const file = inputFileLogbook.files[0];
            const formData = new FormData();
            formData.append('doc_type', 'logbook');
            formData.append('file', file);

            btnUploadLogbook.disabled = true;
            btnUploadLogbook.innerText = 'Mengunggah...';
            statusUploadLogbook.style.display = 'none';

            try {
                const res = await fetch('/api/admin/pengaturan/upload_doc.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    await showAdminAlert('Berkas Log Book Magang berhasil diperbarui!', 'success');
                    inputFileLogbook.value = '';
                    selectedLogbook.innerText = 'Berkas aktif berhasil diganti';
                    btnUploadLogbook.style.display = 'none';
                    updateLogbookActiveDisplay('file', data.file_path, '');
                } else {
                    await showAdminAlert(data.error || 'Gagal mengunggah berkas logbook.', 'error');
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat mengunggah berkas.', 'error');
            }
            btnUploadLogbook.disabled = false;
            btnUploadLogbook.innerText = 'Unggah Sekarang';
        });
    }

    // 4. Save metadata Pedoman
    const btnSaveDocPedoman = document.getElementById('btn-save-doc-pedoman');
    if (btnSaveDocPedoman) {
        btnSaveDocPedoman.addEventListener('click', async () => {
            const statusEl = document.getElementById('save-status-doc-pedoman');
            btnSaveDocPedoman.disabled = true;
            btnSaveDocPedoman.innerText = 'Menyimpan...';
            if (statusEl) statusEl.style.display = 'none';

            const tipe = document.getElementById('radio-pedoman-url').checked ? 'url' : 'file';
            const payload = {
                doc_pedoman_tipe: tipe,
                doc_pedoman_url: document.getElementById('url_pedoman').value.trim(),
                doc_pedoman_judul: document.getElementById('judul_pedoman').value.trim(),
                doc_pedoman_meta: document.getElementById('meta_pedoman').value.trim(),
                doc_pedoman_badge: document.getElementById('badge_pedoman').value.trim(),
                doc_pedoman_btn_text: document.getElementById('btn_text_pedoman').value.trim(),
                doc_pedoman_deskripsi: document.getElementById('deskripsi_pedoman').value.trim(),
            };

            try {
                const res = await fetch('/api/admin/pengaturan/update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    if (statusEl) {
                        statusEl.innerText = 'Tersimpan';
                        statusEl.style.display = 'inline';
                        setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
                    }
                    await showAdminAlert('Pengaturan Buku Pedoman Magang berhasil disimpan!', 'success');
                    await loadSettings();
                } else {
                    await showAdminAlert(data.error || 'Gagal menyimpan pengaturan pedoman.', 'error');
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat menyimpan.', 'error');
            }
            btnSaveDocPedoman.disabled = false;
            btnSaveDocPedoman.innerText = 'Simpan Pengaturan Pedoman';
        });
    }

    // 5. Save metadata Log Book
    const btnSaveDocLogbook = document.getElementById('btn-save-doc-logbook');
    if (btnSaveDocLogbook) {
        btnSaveDocLogbook.addEventListener('click', async () => {
            const statusEl = document.getElementById('save-status-doc-logbook');
            btnSaveDocLogbook.disabled = true;
            btnSaveDocLogbook.innerText = 'Menyimpan...';
            if (statusEl) statusEl.style.display = 'none';

            const tipe = document.getElementById('radio-logbook-url').checked ? 'url' : 'file';
            const payload = {
                doc_logbook_tipe: tipe,
                doc_logbook_url: document.getElementById('url_logbook').value.trim(),
                doc_logbook_judul: document.getElementById('judul_logbook').value.trim(),
                doc_logbook_meta: document.getElementById('meta_logbook').value.trim(),
                doc_logbook_badge: document.getElementById('badge_logbook').value.trim(),
                doc_logbook_btn_text: document.getElementById('btn_text_logbook').value.trim(),
                doc_logbook_deskripsi: document.getElementById('deskripsi_logbook').value.trim(),
            };

            try {
                const res = await fetch('/api/admin/pengaturan/update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    if (statusEl) {
                        statusEl.innerText = 'Tersimpan';
                        statusEl.style.display = 'inline';
                        setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
                    }
                    await showAdminAlert('Pengaturan Log Book Magang berhasil disimpan!', 'success');
                    await loadSettings();
                } else {
                    await showAdminAlert(data.error || 'Gagal menyimpan pengaturan logbook.', 'error');
                }
            } catch (err) {
                await showAdminAlert('Kesalahan jaringan saat menyimpan.', 'error');
            }
            btnSaveDocLogbook.disabled = false;
            btnSaveDocLogbook.innerText = 'Simpan Pengaturan Log Book';
        });
    }
}

let superAdminAccountData = null;

async function loadSuperAdminAccountInfo() {
    try {
        const res = await fetch('/api/admin/pengaturan/change_password.php');
        const data = await res.json();
        if (data.ok) {
            superAdminAccountData = data;
            const nameEl = document.getElementById('superadmin-display-name');
            const metaEl = document.getElementById('superadmin-display-meta');
            const grpCurrent = document.getElementById('group-current-password');
            const inputCurrent = document.getElementById('current_password');

            if (nameEl) {
                nameEl.innerText = data.nama || 'Super Admin';
            }
            if (metaEl) {
                const infoParts = [];
                if (data.email) infoParts.push(`Email: ${data.email}`);
                if (data.username) infoParts.push(`Username: ${data.username}`);
                infoParts.push(`Password Login: ${data.has_password ? 'Sudah Diatur' : 'Belum Diatur (Login SSO)'}`);
                metaEl.innerText = infoParts.join(' • ');
            }

            if (grpCurrent && inputCurrent) {
                if (data.has_password) {
                    grpCurrent.style.display = 'block';
                    inputCurrent.required = true;
                } else {
                    grpCurrent.style.display = 'none';
                    inputCurrent.required = false;
                    inputCurrent.value = '';
                }
            }
        }
    } catch (err) {
        console.error('[pengaturan.js] Error loading super admin info:', err);
    }
}

function setupSuperAdminPasswordListeners() {
    const modal = document.getElementById('modal-ganti-password');
    const btnOpen = document.getElementById('btn-open-modal-password');
    const btnClose = document.getElementById('btn-close-modal-password');
    const btnCancel = document.getElementById('btn-cancel-modal-password');
    const form = document.getElementById('form-ganti-password');
    const alertBox = document.getElementById('pw-form-alert');

    function openModal() {
        if (!modal) return;
        if (form) form.reset();
        hideAlert();

        // Reset toggle password icons and types
        document.querySelectorAll('.btn-toggle-pw').forEach(btn => {
            const targetId = btn.getAttribute('data-target');
            const inp = document.getElementById(targetId);
            if (inp) inp.type = 'password';
            const eyeOpen = btn.querySelector('.eye-open');
            const eyeClosed = btn.querySelector('.eye-closed');
            if (eyeOpen) eyeOpen.style.display = 'block';
            if (eyeClosed) eyeClosed.style.display = 'none';
        });

        if (superAdminAccountData) {
            const grpCurrent = document.getElementById('group-current-password');
            const inputCurrent = document.getElementById('current_password');
            if (grpCurrent && inputCurrent) {
                if (superAdminAccountData.has_password) {
                    grpCurrent.style.display = 'block';
                    inputCurrent.required = true;
                } else {
                    grpCurrent.style.display = 'none';
                    inputCurrent.required = false;
                    showAlert('Akun Anda saat ini belum memiliki kata sandi lokal (masuk via SSO). Silakan buat kata sandi baru.', 'info');
                }
            }
        }

        modal.classList.add('active');
        const firstInput = (superAdminAccountData && superAdminAccountData.has_password) 
            ? document.getElementById('current_password') 
            : document.getElementById('new_password');
        if (firstInput) setTimeout(() => firstInput.focus(), 150);
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('active');
        if (form) form.reset();
        hideAlert();
    }

    function showAlert(msg, type = 'error') {
        if (!alertBox) return;
        alertBox.style.display = 'block';
        if (type === 'error') {
            alertBox.style.background = '#fef2f2';
            alertBox.style.color = '#b91c1c';
            alertBox.style.border = '1px solid #fecaca';
        } else if (type === 'info') {
            alertBox.style.background = '#f0f9ff';
            alertBox.style.color = '#0369a1';
            alertBox.style.border = '1px solid #bae6fd';
        } else {
            alertBox.style.background = '#f0fdf4';
            alertBox.style.color = '#15803d';
            alertBox.style.border = '1px solid #bbf7d0';
        }
        alertBox.innerText = msg;
    }

    function hideAlert() {
        if (alertBox) alertBox.style.display = 'none';
    }

    if (btnOpen) btnOpen.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);

    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
            closeModal();
        }
    });

    document.querySelectorAll('.btn-toggle-pw').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            const inp = document.getElementById(targetId);
            if (!inp) return;
            const eyeOpen = btn.querySelector('.eye-open');
            const eyeClosed = btn.querySelector('.eye-closed');

            if (inp.type === 'password') {
                inp.type = 'text';
                if (eyeOpen) eyeOpen.style.display = 'none';
                if (eyeClosed) eyeClosed.style.display = 'block';
            } else {
                inp.type = 'password';
                if (eyeOpen) eyeOpen.style.display = 'block';
                if (eyeClosed) eyeClosed.style.display = 'none';
            }
        });
    });

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert();

            const curPass = document.getElementById('current_password') ? document.getElementById('current_password').value : '';
            const newPass = document.getElementById('new_password').value;
            const confPass = document.getElementById('confirm_password').value;

            if (superAdminAccountData && superAdminAccountData.has_password && !curPass) {
                showAlert('Password saat ini wajib diisi.');
                return;
            }

            if (!newPass) {
                showAlert('Password baru wajib diisi.');
                return;
            }

            if (newPass.length < 6) {
                showAlert('Password baru minimal 6 karakter.');
                return;
            }

            if (newPass !== confPass) {
                showAlert('Konfirmasi password baru tidak cocok.');
                return;
            }

            const btnSubmit = document.getElementById('btn-submit-password');
            const origText = btnSubmit.innerHTML;
            btnSubmit.disabled = true;
            btnSubmit.innerText = 'Menyimpan...';

            try {
                const res = await fetch('/api/admin/pengaturan/change_password.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        current_password: curPass,
                        new_password: newPass,
                        confirm_password: confPass
                    })
                });

                const data = await res.json();
                if (res.ok && data.ok) {
                    closeModal();
                    await showAdminAlert('Kata sandi Super Admin berhasil diperbarui!', 'success', 'Password Diperbarui');
                    await loadSuperAdminAccountInfo();
                } else {
                    showAlert(data.error || 'Gagal mengubah kata sandi.');
                }
            } catch (err) {
                console.error('[pengaturan.js] Error updating password:', err);
                showAlert('Kesalahan koneksi ke server saat memperbarui password.');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = origText;
            }
        });
    }
}

