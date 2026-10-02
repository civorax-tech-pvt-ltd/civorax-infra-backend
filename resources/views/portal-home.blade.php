@php
    use App\Models\MusterRoll;
    use Filament\Facades\Filament;

    $now = now('Asia/Kathmandu');
    $hour = (int) $now->format('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $website = rtrim(config('services.website.url'), '/');

    $portals = [
        [
            'id' => 'client', 'title' => 'Client portal', 'color' => '59, 130, 246',
            'text' => 'Follow your project: progress, site photos, payments, quotations and documents.',
            'icon' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/>',
        ],
        [
            'id' => 'student', 'title' => 'Academy', 'color' => '16, 185, 129',
            'text' => 'Your courses, class schedule, fee payments and completion certificates.',
            'icon' => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>',
        ],
        [
            'id' => 'team', 'title' => 'Team', 'color' => '99, 102, 241',
            'text' => 'Tasks, attendance, site records, BOQ measurements and approvals.',
            'icon' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17.5" cy="9" r="2.4"/><path d="M15.8 14.6c2.9-.4 5.2 1.4 5.2 4.4"/>',
        ],
    ];

    foreach ($portals as &$portal) {
        $panel = Filament::getPanel($portal['id']);
        $portal['login'] = $panel->getLoginUrl();
        $portal['reset'] = $panel->hasPasswordReset() ? $panel->getRequestPasswordResetUrl() : null;
    }
    unset($portal);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0b1220">
    <title>CivoraX Infra · Portal</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b1220;
            --ink: #f8fafc;
            --muted: #94a3b8;
            --line: rgba(148, 163, 184, .14);
            --card: rgba(15, 23, 42, .72);
            --amber: 245, 158, 11;
        }
        * { box-sizing: border-box; margin: 0; }
        html, body { min-height: 100%; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; text-decoration: none; }

        /* Blueprint background: drafting grid, a building drawn line by line, and a slow glow. */
        .bg { position: fixed; inset: 0; z-index: 0; pointer-events: none; }
        .grid {
            position: absolute; inset: -40px;
            background-image:
                linear-gradient(var(--line) 1px, transparent 1px),
                linear-gradient(90deg, var(--line) 1px, transparent 1px),
                linear-gradient(rgba(148,163,184,.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148,163,184,.05) 1px, transparent 1px);
            background-size: 120px 120px, 120px 120px, 24px 24px, 24px 24px;
            mask-image: radial-gradient(ellipse at 50% 40%, #000 30%, transparent 75%);
            animation: drift 40s linear infinite;
        }
        @keyframes drift { to { transform: translate(120px, 120px); } }
        .glow {
            position: absolute; width: 60vmax; height: 60vmax; border-radius: 50%;
            filter: blur(90px); opacity: .32;
            animation: float 18s ease-in-out infinite alternate;
        }
        .glow.a { background: rgb(var(--amber)); top: -25vmax; left: -15vmax; }
        .glow.b { background: #6366f1; bottom: -30vmax; right: -20vmax; animation-delay: -9s; opacity: .22; }
        @keyframes float { to { transform: translate(6vmax, 4vmax) scale(1.08); } }
        .skyline { position: absolute; left: 0; right: 0; bottom: 0; width: 100%; height: 38vh; opacity: .55; }
        .skyline path {
            fill: none; stroke: rgba(var(--amber), .55); stroke-width: 1.4;
            stroke-dasharray: 2600; stroke-dashoffset: 2600;
            animation: draw 6s ease-out forwards;
        }
        .skyline path.thin { stroke: rgba(148,163,184,.35); stroke-width: 1; animation-delay: 1.2s; }
        .skyline .crane { animation-delay: 2.4s; }
        @keyframes draw { to { stroke-dashoffset: 0; } }

        main { position: relative; z-index: 1; max-width: 1120px; margin: 0 auto; padding: 48px 20px 40px; min-height: 100vh; display: flex; flex-direction: column; }

        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand b { font-size: 1.25rem; font-weight: 800; letter-spacing: -.02em; }
        .brand b span { color: rgb(var(--amber)); }
        .brand small { display: block; font-size: .68rem; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); font-weight: 600; }
        .date { font-size: .82rem; color: var(--muted); text-align: right; }
        .date strong { color: var(--ink); font-weight: 600; }
        #clock { font-variant-numeric: tabular-nums; }

        .hero { margin: 9vh 0 6vh; max-width: 720px; animation: rise .8s ease-out both; }
        .eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 12px; border-radius: 999px; font-size: .75rem; font-weight: 600;
            background: rgba(var(--amber), .12); color: rgb(var(--amber)); border: 1px solid rgba(var(--amber), .25);
        }
        .eyebrow i { width: 7px; height: 7px; border-radius: 50%; background: rgb(var(--amber)); box-shadow: 0 0 0 0 rgba(var(--amber), .7); animation: pulse 2s infinite; }
        @keyframes pulse { 70% { box-shadow: 0 0 0 9px rgba(var(--amber), 0); } 100% { box-shadow: 0 0 0 0 rgba(var(--amber), 0); } }
        h1 { font-size: clamp(2.2rem, 5.4vw, 3.9rem); line-height: 1.04; letter-spacing: -.045em; font-weight: 800; margin: 18px 0 14px; }
        h1 em {
            font-style: normal;
            background: linear-gradient(90deg, rgb(var(--amber)), #fb7185 50%, #818cf8);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        .lead { font-size: 1.05rem; line-height: 1.7; color: var(--muted); max-width: 560px; }

        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; }
        .card {
            --c: 245, 158, 11;
            position: relative; padding: 26px; border-radius: 22px;
            background: var(--card); border: 1px solid var(--line);
            backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            display: flex; flex-direction: column; gap: 14px; overflow: hidden;
            transition: transform .35s cubic-bezier(.2,.8,.2,1), border-color .35s, box-shadow .35s;
            animation: rise .8s ease-out both;
        }
        .card:nth-child(2) { animation-delay: .1s; }
        .card:nth-child(3) { animation-delay: .2s; }
        .card::before {
            content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
            background: radial-gradient(400px circle at var(--x, 50%) var(--y, 0%), rgba(var(--c), .18), transparent 45%);
            opacity: 0; transition: opacity .35s;
        }
        .card:hover { transform: translateY(-6px); border-color: rgba(var(--c), .45); box-shadow: 0 24px 60px -24px rgba(var(--c), .55); }
        .card:hover::before { opacity: 1; }
        .icon {
            width: 52px; height: 52px; border-radius: 15px; display: grid; place-items: center;
            background: rgba(var(--c), .14); color: rgb(var(--c)); border: 1px solid rgba(var(--c), .3);
        }
        .icon svg { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .card h2 { font-size: 1.25rem; font-weight: 700; letter-spacing: -.02em; }
        .card p { color: var(--muted); font-size: .92rem; line-height: 1.6; flex: 1; }
        .actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; border-radius: 12px;
            background: rgb(var(--c)); color: #fff; font-weight: 700; font-size: .9rem;
            transition: gap .25s, filter .25s;
        }
        .btn:hover { gap: 12px; filter: brightness(1.1); }
        .btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; }
        .forgot { font-size: .8rem; color: var(--muted); }
        .forgot:hover { color: var(--ink); text-decoration: underline; }

        footer {
            margin-top: auto; padding-top: 48px; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap;
            font-size: .8rem; color: var(--muted);
        }
        footer nav { display: flex; gap: 18px; flex-wrap: wrap; }
        footer a:hover { color: var(--ink); }

        @keyframes rise { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) {
            *, *::before { animation: none !important; transition: none !important; }
            .skyline path { stroke-dashoffset: 0; }
        }
        @media (max-width: 640px) {
            main { padding-top: 28px; }
            .date { text-align: left; }
            .hero { margin: 7vh 0 5vh; }
        }
    </style>
</head>
<body>
    <div class="bg" aria-hidden="true">
        <div class="glow a"></div>
        <div class="glow b"></div>
        <div class="grid"></div>
        {{-- A skyline drawn like a blueprint: towers, a house, and a tower crane. --}}
        <svg class="skyline" viewBox="0 0 1440 360" preserveAspectRatio="xMidYMax slice">
            <path d="M0 359H1440"/>
            <path d="M70 359V180h120v179M90 200h20M130 200h20M90 230h20M130 230h20M90 260h20M130 260h20M90 290h20M130 290h20"/>
            <path d="M230 359V240l70-50 70 50v119M275 359v-55h50v55M250 255h30M320 255h30"/>
            <path class="thin" d="M410 359V120h90v239M500 359V160h70v199M425 140h60M425 170h60M425 200h60M425 230h60M425 260h60M425 290h60M515 180h40M515 210h40M515 240h40M515 270h40"/>
            <path d="M640 359V60h130v299M660 80h90M660 110h90M660 140h90M660 170h90M660 200h90M660 230h90M660 260h90M660 290h90M705 60V30"/>
            <path class="crane" d="M860 359V70M845 359h30M860 70l-180 0M860 70h90M860 70l-30-30h60l-30 30M700 70v60M690 130h20v18h-20zM945 70v40h-25v-40"/>
            <path class="thin" d="M980 359V200h160v159M1000 220h120M1000 250h120M1000 280h120M1000 310h120"/>
            <path d="M1170 359V150l60-40 60 40v209M1195 170h70M1195 200h70M1195 230h70M1195 260h70M1195 290h70M1210 359v-40h40v40"/>
            <path class="thin" d="M1320 359V230h100v129M1335 250h25M1380 250h25M1335 280h25M1380 280h25"/>
        </svg>
    </div>

    <main>
        <header>
            <a class="brand" href="{{ $website }}">
                <svg width="42" height="42" viewBox="0 0 34 34" fill="none" aria-hidden="true">
                    <rect width="34" height="34" rx="9" fill="rgb(245,158,11)"/>
                    <path d="M9 25V13.5L17 8l8 5.5V25" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/>
                    <path d="M13.5 25v-6.5h7V25" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/>
                    <path d="M11 11.5l12 9M23 11.5l-12 9" stroke="rgba(255,255,255,.45)" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
                <span><b>CivoraX <span>Infra</span></b><small>Portal</small></span>
            </a>
            <div class="date">
                <strong>{{ $now->format('l, M j, Y') }}</strong> · {{ MusterRoll::bsDate($now->toDateString()) }}<br>
                <span id="clock">{{ $now->format('g:i A') }}</span> · Nepal
            </div>
        </header>

        <section class="hero">
            <span class="eyebrow"><i></i> {{ $greeting }}, welcome</span>
            <h1>Every project, site and class, <em>in one place.</em></h1>
            <p class="lead">Sign in to your CivoraX portal. Choose where you belong: clients follow their building, students their courses, and our team runs the work.</p>
        </section>

        <section class="cards">
            @foreach ($portals as $portal)
                <article class="card" style="--c: {{ $portal['color'] }}">
                    <div class="icon"><svg viewBox="0 0 24 24">{!! $portal['icon'] !!}</svg></div>
                    <h2>{{ $portal['title'] }}</h2>
                    <p>{{ $portal['text'] }}</p>
                    <div class="actions">
                        <a class="btn" href="{{ $portal['login'] }}">
                            Sign in
                            <svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                        @if ($portal['reset'])
                            <a class="forgot" href="{{ $portal['reset'] }}">Forgot password?</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>

        <footer>
            <span>© {{ $now->year }} CivoraX Infra Pvt. Ltd. · Itahari, Nepal</span>
            <nav>
                <a href="{{ $website }}">Website</a>
                <a href="{{ $website }}/en/our-work">Our work</a>
                <a href="{{ $website }}/en/contact">Contact</a>            </nav>
        </footer>
    </main>

    <x-help-widget dark />

    <script>
        // Card glow follows the pointer.
        document.querySelectorAll('.card').forEach((card) => {
            card.addEventListener('pointermove', (event) => {
                const box = card.getBoundingClientRect();
                card.style.setProperty('--x', `${event.clientX - box.left}px`);
                card.style.setProperty('--y', `${event.clientY - box.top}px`);
            });
        });

        // Live Nepal time.
        const clock = document.getElementById('clock');
        const tick = () => {
            clock.textContent = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Kathmandu' }).format(new Date());
        };
        tick();
        setInterval(tick, 15000);
    </script>
</body>
</html>
