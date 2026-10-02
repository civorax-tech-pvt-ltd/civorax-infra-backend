@php
    $colors = [
        'present' => 'var(--success-600)',
        'away' => 'var(--gray-500)',
        'error' => 'var(--danger-600)',
        'waiting' => 'var(--gray-400)',
    ];
@endphp

<div
    x-data="{
        open: false,
        timer: null,
        // The panel navigates without full page loads, so this component is rebuilt on every page:
        // stop the old timer, and only check right away if the last check is older than the interval.
        init() {
            const every = {{ \App\Livewire\AttendanceTracker::INTERVAL_SECONDS * 1000 }};
            const last = Number(sessionStorage.getItem('cx-attendance-last') || 0);

            if (Date.now() - last >= every) this.send();
            this.timer = setInterval(() => this.send(), every);
        },
        destroy() {
            clearInterval(this.timer);
        },
        send() {
            sessionStorage.setItem('cx-attendance-last', String(Date.now()));

            if (! window.isSecureContext || ! navigator.geolocation) {
                $wire.locationUnavailable('Location needs a secure (https) connection');
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => $wire.report(position.coords.latitude, position.coords.longitude, position.coords.accuracy),
                (error) => $wire.locationUnavailable(error.code === 1 ? 'Allow location access to record attendance' : 'Could not read your location'),
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 60000 },
            );
        },
    }"
    x-on:click="open = ! open; send()"
    title="{{ $message }} (tap to check again)"
    role="button"
    style="display: flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; max-width: 16rem; margin-inline-end: 0.75rem; cursor: pointer;"
>
    <span style="flex: none; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: rgb({{ $colors[$state] ?? $colors['waiting'] }});"></span>
    {{-- Truncated to one line; tapping shows the whole reason (phones have no hover tooltip). --}}
    <span x-bind:style="open ? 'white-space: normal' : 'overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'">📍 {{ $message }}</span>
</div>
