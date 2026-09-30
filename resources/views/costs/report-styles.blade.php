<style>
    .cr { font-size: 14px; }
    .cr h3 { font-weight: 700; margin: 18px 0 8px; font-size: 15px; }
    .cr h3 small, .cr small { font-weight: 400; color: #6b7280; font-size: 12px; }
    .cr-health { border-radius: 12px; padding: 12px 16px; margin-bottom: 14px; }
    .cr-health b { font-size: 16px; margin-right: 8px; }
    .cr-health ul { margin: 6px 0 0 18px; padding: 0; list-style: disc; font-size: 13px; }
    .cr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
    .cr-cards > div { border: 1px solid rgb(0 0 0 / .08); border-radius: 12px; padding: 10px 14px; }
    .dark .cr-cards > div { border-color: rgb(255 255 255 / .1); }
    .cr-cards span, .cr-cards small { display: block; color: #6b7280; font-size: 12px; }
    .cr-cards b { display: block; font-size: 19px; margin: 2px 0; }
    .cr .good b, .cr tr.good td:last-child { color: #15803d; }
    .cr .bad b, .cr tr.bad td:last-child { color: #b91c1c; }
    .cr-table { width: 100%; border-collapse: collapse; }
    .cr-table th, .cr-table td { padding: 7px 8px; border-bottom: 1px solid rgb(0 0 0 / .07); text-align: left; vertical-align: top; }
    .dark .cr-table th, .dark .cr-table td { border-color: rgb(255 255 255 / .08); }
    .cr-table th { font-size: 12px; color: #6b7280; font-weight: 600; }
    .cr-table td:last-child, .cr-table th:last-child { text-align: right; white-space: nowrap; }
    .cr-table tr.cr-total td { font-weight: 700; border-top: 2px solid rgb(0 0 0 / .15); }
    .cr-cats em { font-style: normal; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 999px; margin-left: 6px; }
    .flag-warning em { background: #fef3c7; color: #92400e; }
    .flag-over em, .flag-unbudgeted em { background: #fee2e2; color: #991b1b; }
    .cr-bar-cell { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
    .cr-bar { width: 120px; height: 8px; border-radius: 999px; background: rgb(0 0 0 / .08); overflow: hidden; }
    .cr-bar i { display: block; height: 100%; background: #16a34a; }
    .flag-warning .cr-bar i { background: #d97706; }
    .flag-over .cr-bar i { background: #dc2626; }
    .cr-muted { color: #6b7280; text-align: center !important; }
    .cr .nw { white-space: nowrap; }
</style>
