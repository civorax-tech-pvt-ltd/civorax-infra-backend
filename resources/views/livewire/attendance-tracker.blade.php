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
        send() {
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
    x-init="send(); setInterval(() => send(), {{ \App\Livewire\AttendanceTracker::INTERVAL_SECONDS * 1000 }})"
    title="{{ $message }}"
    style="display: flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; max-width: 16rem; margin-inline-end: 0.75rem;"
>
    <span style="flex: none; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: rgb({{ $colors[$state] ?? $colors['waiting'] }});"></span>
    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">📍 {{ $message }}</span>
</div>
