<?php

namespace App\Models;

use App\Notifications\Alerts;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A course completion certificate. Numbered CERT-2026-0001 per year, with a public verification link
 * (QR on the certificate) that shows whether it is genuine and still valid.
 */
#[Fillable([
    'enrollment_id', 'number', 'verification_code', 'student_name', 'photo_path', 'course_title', 'grade',
    'issued_on', 'completed_on', 'instructor_name', 'remarks', 'issued_by', 'revoked_at', 'revoke_reason',
])]
class Certificate extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        static::creating(function (Certificate $certificate): void {
            $certificate->verification_code ??= static::newVerificationCode();
            $certificate->number ??= static::nextNumber($certificate->issued_on ?? now());
            $certificate->photo_path ??= $certificate->enrollment?->student?->photo_path;
        });

        static::created(fn (Certificate $certificate) => Alerts::certificateIssued($certificate));
    }

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'completed_on' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * Next number for the issue year: CERT-2026-0001, CERT-2026-0002, …
     */
    public static function nextNumber(CarbonInterface|string $issuedOn): string
    {
        $prefix = CertificateSetting::current()->numberPrefix();
        $year = Carbon::parse($issuedOn)->year;
        $stem = "{$prefix}-{$year}-";

        return DB::transaction(function () use ($stem): string {
            $last = static::query()->where('number', 'like', $stem.'%')->lockForUpdate()->orderByDesc('number')->value('number');

            return $stem.str_pad((string) ((int) substr((string) $last, strlen($stem)) + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    protected static function newVerificationCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (static::query()->where('verification_code', $code)->exists());

        return $code;
    }

    public function scopeValid(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function verifyUrl(): string
    {
        return route('certificates.verify', $this->verification_code);
    }

    /**
     * "2026-09-28 (2083 Aswin 12)", as printed on the certificate.
     */
    public static function dualDate(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        [$year, $month, $day] = MusterRoll::toBs($date);

        return $date->toDateString()." ({$year} ".MusterRoll::BS_MONTHS[$month]." {$day})";
    }

    /**
     * The verification QR as inline SVG markup.
     */
    public function qrSvg(int $size = 150): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($this->verifyUrl());

        // Drop the XML declaration so the markup can sit inside HTML.
        return preg_replace('/^<\?xml.*?\?>\s*/', '', $svg);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function revoke(string $reason): void
    {
        $this->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
    }

    public function restore(): void
    {
        $this->forceFill(['revoked_at' => null, 'revoke_reason' => null])->save();
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class)->withTrashed();
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
