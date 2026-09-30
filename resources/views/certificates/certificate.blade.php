@php
    /** @var \App\Models\Certificate $certificate */
    /** @var \App\Models\CertificateSetting $settings */
    $org = $settings->organizationName();
    $contact = collect([$settings->address, $settings->phone, $settings->pan_number ? 'PAN Number: '.$settings->pan_number : null])->filter()->implode('  |  ');
    $logo = $settings->imageUrl($settings->logo_path);
    $instructorSignature = $settings->imageUrl($settings->instructor_signature_path);
    $directorSignature = $settings->imageUrl($settings->director_signature_path);
    $stamp = $settings->imageUrl($settings->stamp_path);
@endphp

<div class="cert">
    <div class="cert-frame"></div>
    <span class="corner tl"></span><span class="corner tr"></span><span class="corner bl"></span><span class="corner br"></span>
    <div class="cert-watermark" aria-hidden="true">{{ mb_strtoupper(mb_substr($org, 0, 1)) }}</div>

    @if ($certificate->isRevoked())
        <div class="cert-revoked">REVOKED</div>
    @endif

    <div class="cert-body">
        <header>
            @if ($logo)<img class="cert-logo {{ $settings->printsName() ? '' : 'cert-logo--alone' }}" src="{{ $logo }}" alt="{{ $org }}">@endif
            @if ($settings->printsName())<div class="cert-org">{{ $org }}</div>@endif
            @if ($contact)<div class="cert-contact">{{ $contact }}</div>@endif
        </header>

        <h1>Certificate of Completion</h1>
        <div class="cert-rule"><span></span><i></i><span></span></div>

        <p class="cert-small">This is to certify that</p>
        <div class="cert-name">{{ $certificate->student_name }}</div>
        <p class="cert-small">has successfully completed the course</p>
        <div class="cert-course">{{ $certificate->course_title }}</div>
        @if ($certificate->grade)
            <p class="cert-grade">Result / Grade: {{ $certificate->grade }}</p>
        @endif

        <div class="cert-facts">
            <div>
                <div class="cert-label">Certificate No.</div>
                <div class="cert-number">{{ $certificate->number }}</div>
            </div>
            <div>
                <div class="cert-label">Date</div>
                <div class="cert-value">{{ \App\Models\Certificate::dualDate($certificate->issued_on) }}</div>
            </div>
            <div>
                <div class="cert-label">Completion Date</div>
                <div class="cert-value">{{ \App\Models\Certificate::dualDate($certificate->completed_on) }}</div>
            </div>
        </div>

        <footer class="cert-signs">
            <div class="cert-sign">
                @if ($instructorSignature)<img src="{{ $instructorSignature }}" alt="">@endif
                <div class="cert-signer">{{ $certificate->instructor_name }}</div>
                <div class="cert-line"></div>
                <div class="cert-role">Instructor</div>
            </div>
            <div class="cert-qr">
                {!! $certificate->qrSvg(110) !!}
                <div>Scan to verify · {{ $certificate->verification_code }}</div>
            </div>
            <div class="cert-sign cert-sign--director">
                @if ($stamp)<img class="cert-stamp" src="{{ $stamp }}" alt="">@endif
                @if ($directorSignature)<img src="{{ $directorSignature }}" alt="">@endif
                <div class="cert-signer">{{ $settings->director_name }}</div>
                <div class="cert-line"></div>
                <div class="cert-role">{{ $settings->director_title ?: 'Director' }}</div>
            </div>
        </footer>
    </div>
</div>

<style>
    .cert { --navy: #1b2466; --gold: #b8860b; position: relative; width: 297mm; height: 210mm; margin: 0 auto; background: #fff; color: var(--navy); font-family: "Times New Roman", Times, Georgia, serif; overflow: hidden; box-sizing: border-box; }
    .cert *, .cert *::before, .cert *::after { box-sizing: border-box; }
    .cert-frame { position: absolute; inset: 9mm; border: 1.2mm solid var(--navy); }
    .cert-frame::after { content: ""; position: absolute; inset: 1.8mm; border: .35mm solid var(--gold); }
    .corner { position: absolute; width: 16mm; height: 16mm; border-color: var(--navy); border-style: solid; border-width: 0; }
    .corner.tl { top: 5mm; left: 5mm; border-top-width: .8mm; border-left-width: .8mm; }
    .corner.tr { top: 5mm; right: 5mm; border-top-width: .8mm; border-right-width: .8mm; }
    .corner.bl { bottom: 5mm; left: 5mm; border-bottom-width: .8mm; border-left-width: .8mm; }
    .corner.br { bottom: 5mm; right: 5mm; border-bottom-width: .8mm; border-right-width: .8mm; }
    .cert-watermark { position: absolute; left: 50%; top: 52%; transform: translate(-50%, -50%); font-size: 120mm; font-weight: 700; color: #eef1f7; line-height: 1; z-index: 0; user-select: none; }
    .cert-revoked { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%) rotate(-18deg); font: 700 34mm/1 Arial, sans-serif; color: rgba(220, 38, 38, .22); border: 3mm solid rgba(220, 38, 38, .22); padding: 4mm 12mm; z-index: 3; }
    .cert-body { position: relative; z-index: 1; height: 100%; padding: 16mm 26mm 17mm; display: flex; flex-direction: column; align-items: center; text-align: center; }
    .cert-logo { height: 22mm; max-width: 80mm; margin-bottom: 1.5mm; object-fit: contain; }
    .cert-logo--alone { height: 28mm; max-width: 95mm; margin-bottom: 2.5mm; }
    .cert-org { font-size: 7.4mm; font-weight: 700; letter-spacing: .02em; }
    .cert-contact { font-size: 3.3mm; color: #555; margin-top: 1.5mm; }
    .cert h1 { font-size: 16mm; font-weight: 700; margin: 6mm 0 0; line-height: 1.05; }
    .cert-rule { display: flex; align-items: center; gap: 3mm; margin: 3mm 0 6mm; }
    .cert-rule span { width: 20mm; height: .35mm; background: var(--gold); }
    .cert-rule i { width: 3.2mm; height: 3.2mm; background: var(--gold); transform: rotate(45deg); }
    .cert-small { font-style: italic; color: #666; font-size: 4.6mm; margin: 0; }
    .cert-name { font-size: 12mm; font-weight: 700; font-style: italic; margin: 3mm 0 3mm; }
    .cert-course { font-size: 6.4mm; font-weight: 700; color: var(--gold); margin-top: 2mm; }
    .cert-grade { font-style: italic; color: #4b5585; font-size: 4.4mm; margin: 2mm 0 0; }
    /* margin-top: auto pins the row low; the padding keeps a gap so the grade can never touch it. */
    .cert-facts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6mm; width: 100%; margin-top: auto; padding-top: 6mm; margin-bottom: 5mm; }
    .cert-label { font-size: 3mm; letter-spacing: .18em; text-transform: uppercase; color: #666; margin-bottom: 1.8mm; }
    .cert-value { font-size: 4.2mm; }
    .cert-number { display: inline-block; border: .4mm solid var(--gold); border-radius: 1.6mm; padding: 1.4mm 4mm; font-weight: 700; font-size: 4.2mm; }
    .cert-signs { display: grid; grid-template-columns: 1fr auto 1fr; align-items: end; gap: 12mm; width: 100%; }
    .cert-sign { display: flex; flex-direction: column; align-items: center; }
    .cert-sign img { height: 14mm; max-width: 55mm; object-fit: contain; margin-bottom: -1mm; }
    .cert-sign--director { position: relative; }
    /* Pressed partly over the signature, slightly turned and see-through, like a real rubber stamp. */
    .cert-sign .cert-stamp { position: absolute; left: 52%; bottom: 5mm; width: 27mm; height: 27mm; max-width: none; margin: 0 0 0 4mm; transform: rotate(-12deg); opacity: .9; mix-blend-mode: multiply; z-index: 2; pointer-events: none; }
    .cert-signer { font-weight: 700; font-size: 4.4mm; min-height: 5mm; }
    .cert-line { width: 55mm; height: .3mm; background: #555; margin: 1.5mm 0; }
    .cert-role { font-size: 3.8mm; color: #555; }
    .cert-qr { display: flex; flex-direction: column; align-items: center; font-size: 2.8mm; color: #555; }
    .cert-qr svg { width: 22mm; height: 22mm; }
</style>
