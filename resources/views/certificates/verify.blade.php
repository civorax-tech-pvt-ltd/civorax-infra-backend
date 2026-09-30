<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Certificate verification – {{ $settings->organizationName() }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f5fb; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111827; padding: 16px; box-sizing: border-box; }
        .card { width: 100%; max-width: 460px; background: #fff; border-radius: 18px; box-shadow: 0 10px 30px rgba(0,0,0,.08); overflow: hidden; }
        .top { padding: 22px 24px; color: #fff; display: flex; gap: 14px; align-items: center; }
        .top.ok { background: #15803d; }
        .top.bad { background: #b91c1c; }
        .top svg { width: 40px; height: 40px; flex: none; }
        .top b { display: block; font-size: 19px; }
        .top span { font-size: 13px; opacity: .9; }
        .photo { display: flex; justify-content: center; padding: 22px 24px 0; }
        .photo img { width: 132px; height: 132px; border-radius: 16px; object-fit: cover; border: 3px solid #fff; box-shadow: 0 4px 16px rgba(0,0,0,.15); }
        dl { margin: 0; padding: 18px 24px 8px; }
        dt { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .06em; }
        dd { margin: 2px 0 14px; font-size: 16px; font-weight: 600; }
        .foot { padding: 12px 24px 20px; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="card">
        @if ($certificate === null)
            <div class="top bad">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                <div><b>Certificate not found</b><span>This code does not match any certificate we issued.</span></div>
            </div>
        @else
            <div class="top {{ $certificate->isRevoked() ? 'bad' : 'ok' }}">
                @if ($certificate->isRevoked())
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                    <div><b>Certificate revoked</b><span>This certificate is no longer valid.</span></div>
                @else
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <div><b>Genuine certificate</b><span>Issued by {{ $settings->organizationName() }}</span></div>
                @endif
            </div>
            @if ($certificate->photoUrl())
                {{-- Lets the checker match the person holding the certificate; the photo is not printed on it. --}}
                <div class="photo"><img src="{{ $certificate->photoUrl() }}" alt="Photo of {{ $certificate->student_name }}"></div>
            @endif
            <dl>
                <dt>Awarded to</dt><dd>{{ $certificate->student_name }}</dd>
                <dt>Course</dt><dd>{{ $certificate->course_title }}</dd>
                @if ($certificate->grade)<dt>Result / Grade</dt><dd>{{ $certificate->grade }}</dd>@endif
                <dt>Certificate no.</dt><dd>{{ $certificate->number }}</dd>
                <dt>Completed</dt><dd>{{ \App\Models\Certificate::dualDate($certificate->completed_on) }}</dd>
                <dt>Issued</dt><dd>{{ \App\Models\Certificate::dualDate($certificate->issued_on) }}</dd>
            </dl>
        @endif
        <div class="foot">{{ $settings->organizationName() }}@if ($settings->phone) · {{ $settings->phone }}@endif</div>
    </div>
</body>
</html>
