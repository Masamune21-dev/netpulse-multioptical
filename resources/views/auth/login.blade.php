<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — NetPulse MultiOptical</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('theme');
                if (theme) document.documentElement.setAttribute('data-theme', theme);
                var primary = localStorage.getItem('primary_color') || '#ffe14a';
                var soft = localStorage.getItem('primary_soft') || '#ff5c8a';
                document.documentElement.style.setProperty('--primary', primary);
                document.documentElement.style.setProperty('--primary-soft', soft);
                document.documentElement.style.setProperty('--primary-gradient',
                    'linear-gradient(135deg, ' + primary + ' 0%, ' + soft + ' 100%)');
            } catch (e) { }
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}?v={{ filemtime(public_path('assets/css/style.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/css/pages/login.css') }}?v={{ filemtime(public_path('assets/css/pages/login.css')) }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
</head>
<body class="login-page">

    {{-- Left Panel --}}
    <div class="login-left">
        <div class="brand-container">
            <div class="logo">
                <div class="logo-icon"><img src="/images/kusumanet-one.webp" alt="" width="256" height="256"></div>
                <div>
                    <div class="logo-text">NetPulse</div>
                    <div class="logo-sub">MultiOptical</div>
                </div>
            </div>
        </div>

        <h1>Network Optical<br>Monitoring System</h1>
        <p>Monitoring jaringan optik dengan status real-time, alert otomatis, dan informasi lengkap perangkat Anda.</p>

        <div class="features">
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-circle-nodes"></i></div>
                <span>SFP monitoring dengan pembacaan TX/RX power optik</span>
            </div>
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-triangle-exclamation"></i></div>
                <span>Alert otomatis untuk deteksi redaman & port down</span>
            </div>
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-map-marked-alt"></i></div>
                <span>Network topology map interaktif real-time</span>
            </div>
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-user-shield"></i></div>
                <span>Manajemen user & akses berbasis role (admin / tech / viewer)</span>
            </div>
        </div>

        <div class="login-status-bar">
            <div class="login-stat">
                <div class="login-stat__val">24/7</div>
                <div class="login-stat__label">Monitoring</div>
            </div>
            <div class="login-stat">
                <div class="login-stat__val">SNMP</div>
                <div class="login-stat__label">v2c / v3</div>
            </div>
            <div class="login-stat">
                <div class="login-stat__val">dBm</div>
                <div class="login-stat__label">Optical Power</div>
            </div>
        </div>
    </div>

    {{-- Right Panel --}}
    <div class="login-right">
        <div class="login-container">
            <div class="login-header">
                <h2>Selamat Datang</h2>
                <p>Masuk untuk membuka dashboard monitoring</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" id="loginForm" action="/login">
                @csrf

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-user"></i></span>
                        <input type="text" id="username" name="username" class="form-control"
                            placeholder="Masukkan username" value="{{ old('username') }}" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" id="password" name="password" class="form-control password-field"
                            placeholder="Masukkan password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Tampilkan password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="loginButton">
                    <span id="buttonText">Masuk</span>
                    <span class="btn-icon" id="buttonIcon"><i class="fas fa-arrow-right"></i></span>
                </button>
            </form>

            <div class="login-divider">atau</div>

            <div class="login-footer">
                <p>Butuh bantuan? <a href="mailto:masamunekazuto21@gmail.com">Hubungi support</a></p>
                <p>© {{ date('Y') }} NetPulse MultiOptical &nbsp;·&nbsp; by Masamune</p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const inp = document.getElementById('password');
            const ico = document.querySelector('.password-toggle i');
            if (inp.type === 'password') {
                inp.type = 'text';
                ico.className = 'fas fa-eye-slash';
            } else {
                inp.type = 'password';
                ico.className = 'fas fa-eye';
            }
            inp.focus();
        }

        document.getElementById('loginForm').addEventListener('submit', function () {
            const btn = document.getElementById('loginButton');
            const txt = document.getElementById('buttonText');
            const ico = document.getElementById('buttonIcon').querySelector('i');
            btn.disabled = true;
            txt.textContent = 'Signing in...';
            ico.className = 'fas fa-spinner fa-spin';
        });

        document.addEventListener('DOMContentLoaded', function () {
            const u = document.getElementById('username');
            if (u) setTimeout(() => u.focus(), 200);

            document.addEventListener('keydown', function (e) {
                if (e.ctrlKey && e.key === '/') { e.preventDefault(); document.getElementById('username').focus(); }
                if (e.ctrlKey && e.key === '.') { e.preventDefault(); document.getElementById('password').focus(); }
                if (e.ctrlKey && e.shiftKey && e.key === 'P') { e.preventDefault(); togglePassword(); }
            });
        });

        document.addEventListener('keypress', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                const f = document.activeElement;
                if (f.tagName !== 'TEXTAREA' && f.type !== 'button') {
                    e.preventDefault();
                    if (!document.getElementById('loginButton').disabled) {
                        document.getElementById('loginForm').submit();
                    }
                }
            }
        });

        // Auto-dismiss error alert on typing
        ['username','password'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', function () {
                const err = document.querySelector('.alert-error');
                if (err) { err.style.opacity = '0'; err.style.transition = 'opacity .25s'; setTimeout(() => err.remove(), 280); }
            });
        });
    </script>
</body>
</html>
