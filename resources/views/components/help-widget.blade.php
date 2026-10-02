@props(['dark' => false])

@php
    $settings = \App\Models\CompanySetting::current();
    $contacts = $settings->help_enabled ? $settings->helpContacts() : [];
    $icons = [
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'whatsapp' => '<path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9L3 21"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>',
        'email' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'website' => '<circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',
        'facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
    ];
    $colors = ['phone' => '#16a34a', 'whatsapp' => '#22c55e', 'email' => '#2563eb', 'website' => '#0d9488', 'facebook' => '#1877f2'];
@endphp

@if ($contacts !== [])
    <div id="cx-help" class="cx-help {{ $dark ? 'cx-dark' : '' }}" data-open="true" role="complementary" aria-label="Need help?">
        <style>
            .cx-help { position: fixed; right: 18px; bottom: 18px; z-index: 45; font-family: inherit; }
            .cx-help-card {
                width: 240px; padding: 14px 14px 10px; border-radius: 16px;
                background: #fff; color: #0f172a; border: 1px solid rgba(15, 23, 42, .1);
                box-shadow: 0 18px 40px -18px rgba(15, 23, 42, .45);
                transform-origin: bottom right; transition: transform .2s ease, opacity .2s ease;
            }
            .cx-help-head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
            .cx-help-head b { font-size: .9rem; flex: 1; }
            .cx-help-hours { font-size: .7rem; color: #64748b; margin: 0 0 6px 26px; }
            .cx-help-close { border: 0; background: transparent; color: #94a3b8; cursor: pointer; font-size: 1.1rem; line-height: 1; padding: 2px 4px; border-radius: 6px; }
            .cx-help-close:hover { background: rgba(148, 163, 184, .15); color: #0f172a; }
            .cx-help a.cx-help-row {
                display: flex; align-items: center; gap: 10px; padding: 7px 6px; border-radius: 9px;
                font-size: .8rem; color: inherit; text-decoration: none; word-break: break-all;
            }
            .cx-help a.cx-help-row:hover { background: rgba(148, 163, 184, .14); }
            .cx-help svg { width: 16px; height: 16px; flex: none; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
            .cx-help-fab {
                display: none; width: 52px; height: 52px; border-radius: 50%; border: 0; cursor: pointer;
                background: #f59e0b; color: #fff; box-shadow: 0 12px 28px -10px rgba(245, 158, 11, .8);
                align-items: center; justify-content: center;
            }
            .cx-help-fab svg { width: 24px; height: 24px; }
            .cx-help[data-open="false"] .cx-help-card { display: none; }
            .cx-help[data-open="false"] .cx-help-fab { display: flex; }
            .dark .cx-help-card, .cx-help.cx-dark .cx-help-card { background: #111827; color: #f1f5f9; border-color: rgba(255, 255, 255, .1); }
            .dark .cx-help-close:hover, .cx-help.cx-dark .cx-help-close:hover { color: #fff; }
            @media print { .cx-help { display: none; } }
        </style>

        <div class="cx-help-card">
            <div class="cx-help-head">
                <svg viewBox="0 0 24 24" style="color:#f59e0b"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/><path d="M19 20a3 3 0 0 1-3 3h-3"/></svg>
                <b>Need help?</b>
                <button type="button" class="cx-help-close" data-cx-help-toggle aria-label="Minimise">–</button>
            </div>
            @if (filled($settings->help_hours))
                <p class="cx-help-hours">{{ $settings->help_hours }}</p>
            @endif
            @foreach ($contacts as $contact)
                <a class="cx-help-row" href="{{ $contact['href'] }}" @if (! str_starts_with($contact['href'], 'tel:') && ! str_starts_with($contact['href'], 'mailto:')) target="_blank" rel="noopener" @endif>
                    <svg viewBox="0 0 24 24" style="color: {{ $colors[$contact['type']] }}">{!! $icons[$contact['type']] !!}</svg>
                    <span>{{ $contact['label'] }}</span>
                </a>
            @endforeach
        </div>

        <button type="button" class="cx-help-fab" data-cx-help-toggle aria-label="Need help?">
            <svg viewBox="0 0 24 24"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/></svg>
        </button>

        <script>
            (() => {
                const box = document.getElementById('cx-help');
                if (! box || box.dataset.ready) return;
                box.dataset.ready = '1';

                // Remembered per browser; small screens start minimised so the box doesn't cover the page.
                let saved = null;
                try { saved = localStorage.getItem('cx-help-open'); } catch (e) {}
                const open = saved === null ? window.innerWidth >= 768 : saved === '1';
                box.dataset.open = String(open);

                box.querySelectorAll('[data-cx-help-toggle]').forEach((button) => button.addEventListener('click', () => {
                    const next = box.dataset.open !== 'true';
                    box.dataset.open = String(next);
                    try { localStorage.setItem('cx-help-open', next ? '1' : '0'); } catch (e) {}
                }));
            })();
        </script>
    </div>
@endif
