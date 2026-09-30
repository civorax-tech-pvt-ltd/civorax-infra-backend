<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\CertificateSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CertificateController extends Controller
{
    /**
     * The printable certificate: for admins, and for the student it belongs to while it is valid.
     */
    public function show(Request $request, Certificate $certificate): View
    {
        $user = $request->user();
        $ownsIt = $user->student !== null && $certificate->enrollment?->student_id === $user->student->id;

        abort_unless($user->hasRole('super_admin') || ($ownsIt && ! $certificate->isRevoked()), 403);

        return view('certificates.print', ['certificate' => $certificate, 'settings' => CertificateSetting::current()]);
    }

    /**
     * Public page the QR code opens: anyone (e.g. an employer) can check a certificate is genuine.
     */
    public function verify(string $code): View
    {
        return view('certificates.verify', [
            'certificate' => Certificate::query()->where('verification_code', strtoupper($code))->first(),
            'settings' => CertificateSetting::current(),
        ]);
    }
}
