<style>
    .lg { font-size: 13px; }
    .lg-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 14px; }
    .lg-card { border: 1px solid rgb(0 0 0 / .08); border-radius: 12px; padding: 10px 14px; }
    .dark .lg-card { border-color: rgb(255 255 255 / .1); }
    .lg-card span, .lg-card small { display: block; font-size: 12px; color: #6b7280; }
    .lg-card b { display: block; font-size: 20px; margin: 2px 0; }
    .lg-card.is-due b { color: #dc2626; }
    .lg-card.is-adv b { color: #d97706; }
    .lg-scroll { overflow-x: auto; }
    .lg-table { width: 100%; border-collapse: collapse; }
    .lg-table th, .lg-table td { border-bottom: 1px solid rgb(0 0 0 / .08); padding: 7px 8px; text-align: right; vertical-align: top; }
    .dark .lg-table th, .dark .lg-table td { border-color: rgb(255 255 255 / .08); }
    .lg-table th { font-size: 12px; color: #6b7280; font-weight: 600; }
    .lg-table .l { text-align: left; }
    .lg-table .nw { white-space: nowrap; }
    .lg-table small { color: #6b7280; font-size: 11px; }
    .lg-table .due { color: #dc2626; }
    .lg-table .adv { color: #d97706; }
    .lg-table tfoot td { border-top: 2px solid rgb(0 0 0 / .15); }
    .lg-muted { color: #6b7280; text-align: center !important; }
    .lg-note { font-size: 12px; color: #6b7280; margin-top: 8px; }
</style>
