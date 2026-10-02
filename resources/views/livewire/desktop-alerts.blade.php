<div
    x-data="{
        storageKey: 'cx-desktop-alerts-{{ auth()->id() }}',
        permission: ('Notification' in window) ? Notification.permission : 'unsupported',
        dismissed: false,
        timer: null,
        // Rebuilt on every page of the single-page panel: one timer at a time, and no extra check
        // when the last one was less than 30 seconds ago.
        init() {
            if (! window.isSecureContext || this.permission === 'unsupported') { this.permission = 'unsupported'; return; }
            this.dismissed = localStorage.getItem(this.storageKey + '-dismissed') === '1';
            if (Date.now() - Number(sessionStorage.getItem(this.storageKey + '-polled') || 0) >= 30000) this.poll();
            this.timer = setInterval(() => this.poll(), 30000);
        },
        destroy() {
            clearInterval(this.timer);
        },
        async poll() {
            sessionStorage.setItem(this.storageKey + '-polled', String(Date.now()));
            const since = localStorage.getItem(this.storageKey);
            const result = await $wire.check(since);
            localStorage.setItem(this.storageKey, result.now);

            if (Notification.permission !== 'granted') return;

            result.alerts.forEach((alert) => {
                const popup = new Notification(alert.title, { body: alert.body, icon: '{{ asset('favicon.ico') }}', tag: alert.id });
                popup.onclick = () => { window.focus(); if (alert.url) window.location.href = alert.url; popup.close(); };
            });
        },
        async enable() {
            this.permission = await Notification.requestPermission();
            if (this.permission === 'granted') {
                new Notification('Desktop alerts are on', { body: 'You will be notified about important updates while CivoraX is open.', icon: '{{ asset('favicon.ico') }}' });
            }
        },
        dismiss() {
            this.dismissed = true;
            localStorage.setItem(this.storageKey + '-dismissed', '1');
        },
    }"
>
    <div
        x-cloak
        x-show="permission === 'default' && ! dismissed"
        style="position: fixed; left: 1rem; bottom: 1rem; z-index: 40; display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 0.75rem 0.6rem 1rem; border-radius: 0.9rem; background: rgb(var(--gray-900)); color: #fff; font-size: 0.85rem; box-shadow: 0 12px 30px -10px rgba(0,0,0,0.45);"
    >
        <span>🔔 Get desktop alerts for important updates?</span>
        <button type="button" x-on:click="enable()" style="padding: 0.35rem 0.75rem; border-radius: 0.6rem; background: rgb(var(--primary-500)); color: #fff; font-weight: 600;">Enable</button>
        <button type="button" x-on:click="dismiss()" aria-label="Not now" style="padding: 0.35rem 0.5rem; opacity: 0.7;">✕</button>
    </div>
</div>
