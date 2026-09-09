/**
 * REMATE - In-Page PDF Preview Modal
 * Reusable modal for viewing student transcript PDFs within the page
 * without switching tabs or creating new windows.
 */

let pdfOverlay = null;

function escapeText(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function initPdfModal() {
    if (pdfOverlay) return pdfOverlay;

    pdfOverlay = document.getElementById('global-pdf-preview-overlay');
    if (!pdfOverlay) {
        pdfOverlay = document.createElement('div');
        pdfOverlay.id = 'global-pdf-preview-overlay';
        pdfOverlay.className = 'admin-modal-overlay';
        pdfOverlay.innerHTML = `
            <div class="admin-modal-card modal-card-pdf" role="dialog" aria-modal="true" aria-labelledby="pdf-modal-mhs-nama">
                <div class="pdf-modal-header">
                    <div class="pdf-modal-title-wrap">
                        <div class="pdf-modal-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="12" y1="18" x2="12" y2="12"/>
                                <polyline points="9 15 12 12 15 15"/>
                            </svg>
                        </div>
                        <div style="min-width: 0;">
                            <h3 class="pdf-modal-title" id="pdf-modal-mhs-nama">Pratinjau Transkrip Nilai</h3>
                            <p class="pdf-modal-subtitle" id="pdf-modal-mhs-nim">Memuat...</p>
                        </div>
                    </div>
                    <div class="pdf-modal-actions">
                        <a id="pdf-modal-tab-link" href="#" target="_blank" rel="noopener noreferrer" class="btn-pdf-external" title="Buka berkas ini di tab baru browser">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                            <span>Buka Tab Baru</span>
                        </a>
                        <button type="button" class="btn-pdf-close" id="btn-close-pdf-modal" aria-label="Tutup pratinjau">&times;</button>
                    </div>
                </div>
                <div class="pdf-modal-body">
                    <div class="pdf-loading-overlay" id="pdf-modal-loading">
                        <div class="admin-spinner"></div>
                        <span>Memuat dokumen transkrip nilai...</span>
                    </div>
                    <iframe id="pdf-modal-iframe" src="about:blank" title="Pratinjau Dokumen Transkrip Nilai"></iframe>
                </div>
            </div>
        `;
        document.body.appendChild(pdfOverlay);

        // Event listener penutup
        const btnClose = document.getElementById('btn-close-pdf-modal');
        if (btnClose) {
            btnClose.addEventListener('click', closePdfPreviewModal);
        }

        // Tutup jika klik backdrop di luar kartu
        pdfOverlay.addEventListener('click', (e) => {
            if (e.target === pdfOverlay) {
                closePdfPreviewModal();
            }
        });

        // Tutup jika menekan tombol Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && pdfOverlay.classList.contains('active')) {
                closePdfPreviewModal();
            }
        });
    }

    return pdfOverlay;
}

export function openPdfPreviewModal(url, nama = '', nim = '') {
    const overlay = initPdfModal();
    const titleEl = document.getElementById('pdf-modal-mhs-nama');
    const subtitleEl = document.getElementById('pdf-modal-mhs-nim');
    const tabLink = document.getElementById('pdf-modal-tab-link');
    const iframe = document.getElementById('pdf-modal-iframe');
    const loading = document.getElementById('pdf-modal-loading');

    const cleanNama = nama ? nama.trim() : 'Mahasiswa';
    let cleanNim = nim ? nim.trim() : '';
    if (cleanNim && !cleanNim.toUpperCase().startsWith('NIM')) {
        cleanNim = `NIM: ${cleanNim}`;
    }

    if (titleEl) titleEl.innerText = `Transkrip: ${cleanNama}`;
    if (subtitleEl) subtitleEl.innerText = cleanNim || 'Dokumen PDF Akademik';
    if (tabLink) tabLink.href = url;

    // Reset iframe & tampilkan loader
    if (loading) loading.style.display = 'flex';
    if (iframe) {
        iframe.onload = () => {
            if (loading) loading.style.display = 'none';
        };
        iframe.src = url;
    }

    overlay.classList.add('active');
    document.body.style.overflow = 'hidden'; // Mencegah scrolling latar belakang
}

export function closePdfPreviewModal() {
    if (!pdfOverlay) return;
    pdfOverlay.classList.remove('active');
    document.body.style.overflow = '';

    const iframe = document.getElementById('pdf-modal-iframe');
    if (iframe) {
        // Reset src untuk membebaskan alokasi memori buffer browser
        iframe.src = 'about:blank';
    }
}

// Mengekspos fungsi secara global agar dapat dipanggil langsung via atribut inline HTML
if (typeof window !== 'undefined') {
    window.openPdfPreviewModal = openPdfPreviewModal;
    window.closePdfPreviewModal = closePdfPreviewModal;
}
