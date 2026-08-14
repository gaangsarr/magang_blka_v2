document.addEventListener('DOMContentLoaded', async () => {
    // Cek status, kalau sudah login lempar ke index
    const statusRes = await fetch('/api/admin/status.php');
    const statusData = await statusRes.json();
    if (statusRes.ok && statusData.authenticated) {
        window.location.href = '/admin/index.html';
        return;
    }

    const form = document.getElementById('form-login');
    const btn = document.getElementById('btn-login');
    const errBox = document.getElementById('error-box');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        
        errBox.classList.add('hidden');
        btn.innerText = 'Memeriksa...';
        btn.disabled = true;

        try {
            const res = await fetch('/api/admin/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password })
            });

            const data = await res.json();
            
            if (!res.ok) {
                errBox.innerText = data.error || 'Terjadi kesalahan.';
                errBox.classList.remove('hidden');
                btn.innerText = 'Login';
                btn.disabled = false;
                return;
            }

            window.location.href = data.redirect;
            
        } catch (err) {
            errBox.innerText = 'Gagal menghubungi server.';
            errBox.classList.remove('hidden');
            btn.innerText = 'Login';
            btn.disabled = false;
        }
    });
});
