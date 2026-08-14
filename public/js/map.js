// map.js - Modul peta menggunakan Leaflet.js
// Memungkinkan pin domisili dan geocoding

let map = null;
let marker = null;

/**
 * Inisialisasi peta pada elemen target
 * @param {string} containerId ID elemen div untuk peta
 * @param {function} onLocationChange callback(lat, lng)
 */
export function initMap(containerId, onLocationChange) {
    // Default location: Jakarta (bisa diubah ke kampus ITPLN)
    const defaultLocation = [-6.1751, 106.8272]; 
    
    map = L.map(containerId).setView(defaultLocation, 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // Saat peta diklik
    map.on('click', function(e) {
        setMarker(e.latlng.lat, e.latlng.lng, onLocationChange);
    });
}

/**
 * Set marker di koordinat tertentu
 */
export function setMarker(lat, lng, onLocationChange) {
    if (marker) {
        marker.setLatLng([lat, lng]);
    } else {
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        
        // Update koordinat saat marker didrag
        marker.on('dragend', function(e) {
            const pos = marker.getLatLng();
            if (onLocationChange) onLocationChange(pos.lat, pos.lng);
        });
    }
    
    map.panTo([lat, lng]);
    if (onLocationChange) onLocationChange(lat, lng);
}

/**
 * Cari lokasi menggunakan Nominatim API
 * @param {string} query 
 * @param {function} onLocationChange 
 * @param {function} onError 
 */
export async function searchLocation(query, onLocationChange, onError) {
    if (!query) return;
    
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`);
        const data = await res.json();
        
        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lng = parseFloat(data[0].lon);
            setMarker(lat, lng, onLocationChange);
        } else {
            if (onError) onError('Lokasi tidak ditemukan.');
        }
    } catch (err) {
        console.error('Geocoding error:', err);
        if (onError) onError('Gagal menghubungi layanan pencarian lokasi.');
    }
}

/**
 * Force map untuk resize (sering terjadi bug peta terpotong jika di-init di dalam container yang disembunyikan/display:none)
 */
export function invalidateMapSize() {
    if (map) {
        setTimeout(() => map.invalidateSize(), 100);
    }
}
