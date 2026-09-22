// map.js - Modul peta menggunakan Leaflet.js
// Memungkinkan pin domisili dan geocoding

let map = null;
let marker = null;
let activeLocationCallback = null;

/**
 * Inisialisasi peta pada elemen target
 * @param {string} containerId ID elemen div untuk peta
 * @param {function} onLocationChange callback(lat, lng)
 */
export function initMap(containerId, onLocationChange) {
    if (onLocationChange) {
        activeLocationCallback = onLocationChange;
    }
    
    // Default location: Jakarta (Kampus ITPLN area)
    const defaultLocation = [-6.1751, 106.8272]; 
    
    map = L.map(containerId).setView(defaultLocation, 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // Saat peta diklik manual
    map.on('click', function(e) {
        setMarker(e.latlng.lat, e.latlng.lng, activeLocationCallback);
    });
}

/**
 * Set marker di koordinat tertentu dan picu callback koordinat
 */
export function setMarker(lat, lng, onLocationChange) {
    const cb = onLocationChange || activeLocationCallback;

    if (marker) {
        marker.setLatLng([lat, lng]);
    } else {
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        
        // Update koordinat saat marker didrag manual
        marker.on('dragend', function() {
            const pos = marker.getLatLng();
            const currentCb = activeLocationCallback;
            if (currentCb) currentCb(pos.lat, pos.lng);
        });
    }
    
    map.panTo([lat, lng]);
    if (cb) cb(lat, lng);
}

/**
 * Cari lokasi menggunakan Nominatim API
 * @param {string} query 
 * @param {function} onLocationChange 
 * @param {function} onError 
 */
export async function searchLocation(query, onLocationChange, onError) {
    if (!query) return;
    const cb = onLocationChange || activeLocationCallback;
    
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`);
        const data = await res.json();
        
        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lng = parseFloat(data[0].lon);
            setMarker(lat, lng, cb);
            if (map) {
                map.setView([lat, lng], 16); // Zoom in ke jalan yang dicari
            }
            if (cb) cb(lat, lng);
            return true;
        } else {
            if (onError) onError('Lokasi tidak ditemukan. Coba masukkan nama jalan dan daerah yang lebih spesifik.');
            return false;
        }
    } catch (err) {
        console.error('Geocoding error:', err);
        if (onError) onError('Gagal menghubungi layanan pencarian lokasi.');
        return false;
    }
}

/**
 * Force map untuk resize (mencegah bug peta terpotong jika di-init di dalam container display:none)
 */
export function invalidateMapSize() {
    if (map) {
        setTimeout(() => map.invalidateSize(), 100);
    }
}
