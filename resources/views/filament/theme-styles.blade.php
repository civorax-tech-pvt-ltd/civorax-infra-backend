<style>
    /* Soft backdrop behind content */
    .fi-body { background:
        radial-gradient(1200px 500px at 100% -10%, rgba(var(--primary-500), 0.07), transparent 60%),
        rgb(var(--gray-50)); }
    .dark .fi-body { background:
        radial-gradient(1200px 500px at 100% -10%, rgba(var(--primary-500), 0.10), transparent 60%),
        rgb(var(--gray-950)); }

    /* Top bar and sidebar */
    .fi-topbar > nav { backdrop-filter: saturate(160%) blur(10px); background: rgba(255, 255, 255, 0.85); }
    .dark .fi-topbar > nav { background: rgba(var(--gray-900), 0.85); }
    .fi-sidebar-item-active > a { box-shadow: inset 3px 0 0 rgb(var(--primary-500)); }
    .fi-sidebar-group-label { letter-spacing: 0.06em; text-transform: uppercase; font-size: 0.7rem; }

    /* Cards */
    .fi-section, .fi-wi-stats-overview-stat, .fi-ta-ctn { border-radius: 1rem !important; }
    .fi-section, .fi-ta-ctn { box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 8px 24px -12px rgba(15, 23, 42, 0.12); }
    .fi-wi-stats-overview-stat { transition: transform 0.15s ease, box-shadow 0.15s ease;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 8px 24px -14px rgba(15, 23, 42, 0.14); }
    .fi-wi-stats-overview-stat:hover { transform: translateY(-2px);
        box-shadow: 0 2px 4px rgba(15, 23, 42, 0.05), 0 14px 30px -14px rgba(15, 23, 42, 0.22); }
    .fi-wi-stats-overview-stat-value { letter-spacing: -0.02em; }

    /* Page headings */
    .fi-header-heading { letter-spacing: -0.025em; }

    /* Dashboard welcome banner */
    .cx-banner { position: relative; overflow: hidden; border-radius: 1.25rem; padding: 1.75rem 1.75rem 1.5rem;
        color: #fff; background: linear-gradient(135deg, rgb(var(--primary-600)), rgb(var(--primary-800)));
        box-shadow: 0 18px 40px -20px rgba(var(--primary-700), 0.7); }
    .cx-banner::after { content: ""; position: absolute; inset: -40% -10% auto auto; width: 22rem; height: 22rem;
        border-radius: 9999px; background: rgba(255, 255, 255, 0.08); }
    .cx-banner h2 { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; }
    .cx-banner p { opacity: 0.85; }
    .cx-banner-actions { position: relative; z-index: 1; display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1.25rem; }
    .cx-banner-actions a { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 0.9rem; border-radius: 0.65rem;
        font-size: 0.85rem; font-weight: 600; background: rgba(255, 255, 255, 0.16); color: #fff; transition: background 0.15s ease; }
    .cx-banner-actions a:hover { background: rgba(255, 255, 255, 0.28); }
    .cx-banner-actions a .cx-badge { background: #fff; color: rgb(var(--primary-700)); border-radius: 9999px; padding: 0 0.45rem; font-size: 0.72rem; }

    /* Project cards (team and client dashboards) */
    .cx-cards { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
    .cx-card { display: block; border-radius: 1rem; padding: 1.1rem 1.2rem; background: #fff;
        border: 1px solid rgba(var(--gray-200), 1); transition: border-color 0.15s ease, transform 0.15s ease; }
    .dark .cx-card { background: rgb(var(--gray-900)); border-color: rgba(255, 255, 255, 0.08); }
    .cx-card:hover { border-color: rgb(var(--primary-400)); transform: translateY(-2px); }
    .cx-card-title { font-weight: 700; letter-spacing: -0.01em; }
    .cx-card-meta { font-size: 0.78rem; opacity: 0.65; }
    .cx-pill { display: inline-block; padding: 0.1rem 0.55rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 600;
        background: rgba(var(--primary-500), 0.12); color: rgb(var(--primary-700)); }
    .dark .cx-pill { color: rgb(var(--primary-300)); }
</style>
