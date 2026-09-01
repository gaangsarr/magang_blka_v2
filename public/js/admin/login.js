document.addEventListener('DOMContentLoaded', async () => {
    // Cek status, kalau sudah login lempar ke index masing-masing
    try {
        const statusRes = await fetch('/api/admin/status.php');
        const statusData = await statusRes.json();
        if (statusRes.ok && statusData.authenticated) {
            const role = statusData.admin?.role;
            if (role === 'admin_perusahaan') {
                window.location.href = '/perusahaan/index.html';
            } else {
                window.location.href = '/admin/index.html';
            }
            return;
        }
    } catch (e) {
        // Silently continue to login
    }

    const form = document.getElementById('form-login');
    const btn = document.getElementById('btn-login');
    const errBox = document.getElementById('error-box');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const identifierInput = document.getElementById('identifier') || document.getElementById('email');
        const identifier = identifierInput ? identifierInput.value.trim() : '';
        const password = document.getElementById('password').value;
        
        if (!identifier || !password) {
            errBox.innerText = 'Username/Email dan kata sandi wajib diisi.';
            errBox.classList.remove('hidden');
            return;
        }

        errBox.classList.add('hidden');
        btn.innerText = 'Memeriksa...';
        btn.disabled = true;

        try {
            const res = await fetch('/api/admin/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ identifier, password })
            });

            const data = await res.json();
            
            if (!res.ok) {
                errBox.innerText = data.error || 'Terjadi kesalahan.';
                errBox.classList.remove('hidden');
                btn.innerText = 'Masuk ke Portal';
                btn.disabled = false;
                return;
            }

            sessionStorage.removeItem('admin_profile');
            sessionStorage.removeItem('remate_admin_sidebar_scroll');
            window.location.href = data.redirect || '/admin/index.html';
            
        } catch (err) {
            errBox.innerText = 'Gagal menghubungi server.';
            errBox.classList.remove('hidden');
            btn.innerText = 'Masuk ke Portal';
            btn.disabled = false;
        }
    });
});

