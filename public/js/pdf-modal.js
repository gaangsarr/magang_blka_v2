/**
 * INTERN ITPLN - PDF Viewer Helper
 * Membuka dokumen PDF langsung di tab baru browser (native browser PDF viewer).
 * Berfungsi untuk mahasiswa, admin, dan mitra perusahaan di semua perangkat.
 */

export function openPdfPreviewModal(url) {
    if (!url) return;
    window.open(url, '_blank', 'noopener,noreferrer');
}

export function closePdfPreviewModal() {
    // Tidak lagi diperlukan karena modal ditiadakan
}

// Mengekspos fungsi secara global untuk kompatibilitas penuh
if (typeof window !== 'undefined') {
    window.openPdfPreviewModal = openPdfPreviewModal;
    window.closePdfPreviewModal = closePdfPreviewModal;
}
