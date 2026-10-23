<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{{ $pageTitle ?? 'NetPulse' }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <script>
        // NP-6: suntik X-CSRF-TOKEN ke setiap request same-origin non-GET
        // (fetch & XMLHttpRequest) karena CSRF kini aktif untuk rute /api/* ber-sesi.
        (function () {
            var meta = document.querySelector('meta[name="csrf-token"]');
            var token = meta ? meta.getAttribute('content') : '';
            if (!token) return;
            var SAFE = /^(GET|HEAD|OPTIONS|TRACE)$/i;
            function sameOrigin(url) {
                try {
                    return new URL(url, window.location.href).origin === window.location.origin;
                } catch (e) { return false; }
            }
            if (window.fetch) {
                var nativeFetch = window.fetch;
                window.fetch = function (input, init) {
                    init = init || {};
                    var method = init.method || (input instanceof Request ? input.method : 'GET');
                    var url = input instanceof Request ? input.url : String(input);
                    if (!SAFE.test(method) && sameOrigin(url)) {
                        var headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
                        if (!headers.has('X-CSRF-TOKEN')) headers.set('X-CSRF-TOKEN', token);
                        if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'XMLHttpRequest');
                        init.headers = headers;
                        if (!init.credentials) init.credentials = 'same-origin';
                    }
                    return nativeFetch.call(this, input, init);
                };
            }
            var open = XMLHttpRequest.prototype.open;
            var send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url) {
                this.__npCsrf = !SAFE.test(String(method)) && sameOrigin(String(url));
                return open.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function () {
                if (this.__npCsrf) {
                    try { this.setRequestHeader('X-CSRF-TOKEN', token); } catch (e) {}
                }
                return send.apply(this, arguments);
            };
        })();
    </script>

    <script>
        (function () {
            try {
                var theme = localStorage.getItem('theme');
                if (theme) {
                    document.documentElement.setAttribute('data-theme', theme);
                    document.body && document.body.setAttribute('data-theme', theme);
                }
                var primary = localStorage.getItem('primary_color') || '#ffe14a';
                var soft = localStorage.getItem('primary_soft') || '#ff5c8a';
                document.documentElement.style.setProperty('--primary', primary);
                document.documentElement.style.setProperty('--primary-soft', soft);
                document.documentElement.style.setProperty('--primary-gradient',
                    'linear-gradient(135deg, ' + primary + ' 0%, ' + soft + ' 100%)');
            } catch (e) {}
        })();
    </script>

    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}?v={{ filemtime(public_path('assets/css/style.css')) }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">


    <script src="{{ asset('assets/js/theme.js') }}?v={{ filemtime(public_path('assets/js/theme.js')) }}"></script>
    @stack('styles')
</head>
<body data-role="{{ $currentUser['role'] ?? 'viewer' }}">
    <script>
        (function () {
            try {
                var theme = document.documentElement.getAttribute('data-theme');
                if (theme) document.body.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>

    <div class="layout">
        {{-- Mobile top header --}}
        <header class="mobile-header">
            <div class="mobile-brand">
                <div class="mobile-logo">
                    <img src="/images/kusumanet-one.webp" alt="" width="256" height="256">
                </div>
                <div class="mobile-titles">
                    <div class="mobile-title">NetPulse MultiOptical</div>
                    <div class="mobile-subtitle">Network Optical Monitoring</div>
                </div>
            </div>
            <form method="POST" action="/logout" class="logout-form">
                @csrf
                <button type="submit" class="mobile-logout" title="Logout" aria-label="Logout">
                    <i class="fas fa-arrow-right-from-bracket"></i>
                </button>
            </form>
        </header>

        {{-- Desktop sidebar --}}
        <aside class="sidebar">
            <div class="sidebar-nav">
                <h2>
                    <span class="sidebar-logo"><img src="/images/kusumanet-one.webp" alt="" width="256" height="256"></span>
                    NetPulse MultiOptical
                </h2>
                <ul>
                    <li class="{{ request()->is('dashboard*') ? 'active' : '' }}">
                        <a href="/dashboard">
                            <i class="fas fa-gauge-high"></i><span>Dashboard</span>
                        </a>
                    </li>
                    <li class="{{ request()->is('monitoring*') ? 'active' : '' }}">
                        <a href="/monitoring">
                            <i class="fas fa-signal"></i><span>Monitoring</span>
                        </a>
                    </li>
                    <li class="{{ request()->is('devices*') ? 'active' : '' }}">
                        <a href="/devices">
                            <i class="fas fa-server"></i><span>Devices</span>
                        </a>
                    </li>
                    <li class="{{ request()->is('interfaces*') ? 'active' : '' }}">
                        <a href="/interfaces">
                            <i class="fas fa-ethernet"></i><span>Interfaces</span>
                        </a>
                    </li>
                    <li class="{{ request()->is('sla*') ? 'active' : '' }}">
                        <a href="/sla">
                            <i class="fas fa-chart-column"></i><span>SLA Report</span>
                        </a>
                    </li>
                    <li class="{{ request()->is('map*') ? 'active' : '' }}">
                        <a href="/map">
                            <i class="fas fa-map-location-dot"></i><span>Map</span>
                        </a>
                    </li>
                    <li class="{{ request()->is('users*') ? 'active' : '' }}">
                        <a href="/users">
                            <i class="fas fa-users-gear"></i><span>Users</span>
                        </a>
                    </li>
                    <li class="{{ request()->is('settings*') ? 'active' : '' }}">
                        <a href="/settings">
                            <i class="fas fa-sliders"></i><span>Settings</span>
                        </a>
                    </li>
                    <li class="sidebar-download-item">
                        <a href="/download/app" target="_blank" download="netpulse.apk" class="sidebar-apk-link">
                            <i class="fab fa-android text-success"></i><span>Download APK</span>
                            <span class="badge-apk-tag">APK</span>
                        </a>
                    </li>
                </ul>
            </div>

            {{-- Sidebar user info + logout --}}
            <div class="sidebar-user">
                <div class="sidebar-user__card">
                    <div class="sidebar-user__avatar">
                        {{ strtoupper(substr($currentUser['username'] ?? 'U', 0, 2)) }}
                    </div>
                    <div class="sidebar-user__meta">
                        <div class="sidebar-user__name">{{ $currentUser['username'] ?? '-' }}</div>
                        <div class="sidebar-user__role">{{ $currentUser['role'] ?? '' }}</div>
                    </div>
                    <form method="POST" action="/logout" class="logout-form">
                        @csrf
                        <button type="submit" class="sidebar-user__logout" title="Logout">
                            <i class="fas fa-arrow-right-from-bracket"></i>
                        </button>
                    </form>
                </div>
                <div class="sidebar-version">v2.0 · NetPulse MultiOptical</div>
            </div>
        </aside>

        <main class="content">
            <div class="topbar">
                <h1>{{ $pageTitle ?? 'Dashboard' }}</h1>
                <div class="topbar-right-group">
                    <div class="topbar-actions-slot">
                        @stack('topbar-actions')
                    </div>
                    
                    {{-- App Switcher Ekosistem KusumaVision --}}
                    <div class="ecosystem-dropdown" id="ecosystemDropdown">
                        <button type="button" class="btn-ecosystem" onclick="toggleEcosystemMenu()" title="Ekosistem KusumaVision">
                            <i class="fas fa-cubes"></i> <span>Ekosistem</span> <i class="fas fa-chevron-down ml-1"></i>
                        </button>
                        <div class="ecosystem-menu" id="ecosystemMenu">
                            <div class="ecosystem-menu-header">KusumaVision Ecosystem</div>
                            <a href="https://nms.kusumavision.net" target="_blank" rel="noopener" class="ecosystem-item">
                                <i class="fas fa-network-wired text-cyan"></i>
                                <div>
                                    <div class="ecosystem-item-title">KusumaVision NMS</div>
                                    <div class="ecosystem-item-sub">GPON OLT / ONU Management</div>
                                </div>
                            </a>
                            <a href="https://mikrotik.kusumavision.net" target="_blank" rel="noopener" class="ecosystem-item">
                                <i class="fas fa-server text-purple"></i>
                                <div>
                                    <div class="ecosystem-item-title">KusumaVision MikroTik</div>
                                    <div class="ecosystem-item-sub">RouterOS, PPPoE & Firewall</div>
                                </div>
                            </a>
                            <a href="https://billing.kusumavision.net" target="_blank" rel="noopener" class="ecosystem-item">
                                <i class="fas fa-file-invoice-dollar text-emerald"></i>
                                <div>
                                    <div class="ecosystem-item-title">KusumaVision Billing</div>
                                    <div class="ecosystem-item-sub">CRM, Kasir & Otomasi Isolir</div>
                                </div>
                            </a>
                            <a href="https://sso.kusumavision.net" target="_blank" rel="noopener" class="ecosystem-item">
                                <i class="fas fa-shield-halved text-amber"></i>
                                <div>
                                    <div class="ecosystem-item-title">KusumaVision SSO</div>
                                    <div class="ecosystem-item-sub">Identity Provider & Akun Terpusat</div>
                                </div>
                            </a>
                            <a href="https://kusumavision.net" target="_blank" rel="noopener" class="ecosystem-item">
                                <i class="fas fa-globe text-primary"></i>
                                <div>
                                    <div class="ecosystem-item-title">Portal Perusahaan</div>
                                    <div class="ecosystem-item-sub">kusumavision.net</div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <a href="/download/app" class="topbar-apk-btn" title="Unduh Netpulse Mobile APK" download="netpulse.apk" target="_blank">
                        <i class="fab fa-android"></i> <span>Download APK</span>
                    </a>

                    <div class="header-clock" data-live-clock></div>
                    <div class="user-info">
                        <i class="fas fa-user"></i>
                        {{ $currentUser['username'] ?? '-' }}
                    </div>
                </div>
            </div>

            <div class="content-body">
                @yield('content')
            </div>
        </main>
    </div>

    {{-- Mobile bottom navigation --}}
    <nav class="mobile-bottom-nav">
        <div class="nav-items">
            <a href="/dashboard" class="{{ request()->is('dashboard*') ? 'active' : '' }}">
                <i class="fas fa-gauge-high"></i>
                <span>Dashboard</span>
            </a>
            <a href="/monitoring" class="{{ request()->is('monitoring*') ? 'active' : '' }}">
                <i class="fas fa-signal"></i>
                <span>Monitor</span>
            </a>
            <a href="/devices" class="{{ request()->is('devices*') ? 'active' : '' }}">
                <i class="fas fa-server"></i>
                <span>Devices</span>
            </a>
            <a href="/map" class="{{ request()->is('map*') ? 'active' : '' }}">
                <i class="fas fa-map-location-dot"></i>
                <span>Map</span>
            </a>
            <a href="/settings" class="{{ request()->is('settings*') ? 'active' : '' }}">
                <i class="fas fa-sliders"></i>
                <span>Settings</span>
            </a>
        </div>
    </nav>

    {{-- Confirm delete modal --}}
    <div class="modal" id="confirmDeleteModal">
        <div class="modal-box" style="max-width:420px;">
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            <h3><i class="fas fa-triangle-exclamation" style="color:#ef4444"></i> Konfirmasi Hapus</h3>
            <p id="confirmDeleteMessage" style="margin:1rem 0; color:var(--text-soft);">
                Yakin ingin menghapus data ini?
            </p>
            <div class="modal-actions">
                <button class="btn btn-danger" id="confirmDeleteYes">
                    <i class="fas fa-trash"></i> Hapus
                </button>
                <button class="btn btn-outline" onclick="closeDeleteModal()">
                    Batal
                </button>
            </div>
        </div>
    </div>

    <footer class="app-footer">
        <span>© {{ date('Y') }} NetpulseMultiOptical &nbsp;·&nbsp; Dibuat oleh <strong>Masamune</strong></span>
    </footer>

    <script src="{{ asset('assets/js/script.js') }}?v={{ filemtime(public_path('assets/js/script.js')) }}"></script>
    <script src="{{ asset('assets/js/port-monitoring.js') }}?v={{ filemtime(public_path('assets/js/port-monitoring.js')) }}"></script>
    <script>
        function toggleEcosystemMenu() {
            var m = document.getElementById('ecosystemMenu');
            if (m) {
                m.classList.toggle('active');
            }
        }
        document.addEventListener('click', function (e) {
            var d = document.getElementById('ecosystemDropdown');
            var m = document.getElementById('ecosystemMenu');
            if (d && m && !d.contains(e.target)) {
                m.classList.remove('active');
            }
        });
    </script>
    @stack('scripts')
</body>
</html>
