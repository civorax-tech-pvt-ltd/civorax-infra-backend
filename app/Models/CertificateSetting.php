<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Letterhead and signatures printed on every certificate (a single row, edited under Academy › Certificate Settings).
 */
#[Fillable([
    'organization_name', 'address', 'phone', 'pan_number', 'logo_path', 'show_name_with_logo', 'number_prefix',
    'default_instructor', 'instructor_signature_path', 'director_name', 'director_title', 'director_signature_path', 'stamp_path',
])]
class CertificateSetting extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return ['show_name_with_logo' => 'boolean'];
    }

    /**
     * Print the name as text on the certificate: always without a logo, optional with one.
     */
    public function printsName(): bool
    {
        return $this->logo_path === null || $this->show_name_with_logo !== false;
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    public function organizationName(): string
    {
        return $this->organization_name ?: config('app.name');
    }

    public function imageUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * The prefix without a trailing year ("CERT-2026" → "CERT"): the year is always added by the numbering.
     */
    public function numberPrefix(): string
    {
        return preg_replace('/[-_]?\d{4}$/', '', (string) $this->number_prefix) ?: 'CERT';
    }

    /**
     * Crop the empty white / transparent border around an uploaded logo or signature, so it prints
     * at a useful size. Returns the path of the trimmed PNG (or the original if nothing to trim).
     */
    public static function trimImage(?string $path): ?string
    {
        $disk = Storage::disk('public');

        if ($path === null || ! $disk->exists($path) || ! function_exists('imagecreatefromstring')) {
            return $path;
        }

        $image = @imagecreatefromstring($disk->get($path));

        if ($image === false) {
            return $path;
        }

        // Palette PNGs (common for logos) return colour indexes, not RGBA, until converted.
        imagepalettetotruecolor($image);
        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, intdiv(max($width, $height), 600)); // sample large images for speed
        [$left, $top, $right, $bottom] = [$width, $height, -1, -1];

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $isBlank = $alpha > 110 || ((($rgba >> 16) & 0xFF) > 235 && (($rgba >> 8) & 0xFF) > 235 && ($rgba & 0xFF) > 235);

                if (! $isBlank) {
                    [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
                }
            }
        }

        if ($right < 0) {
            return $path; // blank image: leave it alone
        }

        $pad = (int) round(max($right - $left, $bottom - $top) * 0.02) + $step;
        $left = max(0, $left - $pad);
        $top = max(0, $top - $pad);
        $cropWidth = min($width, $right + $pad + 1) - $left;
        $cropHeight = min($height, $bottom + $pad + 1) - $top;

        if ($cropWidth >= $width * 0.97 && $cropHeight >= $height * 0.97) {
            return $path; // already tight
        }

        $cropped = imagecreatetruecolor($cropWidth, $cropHeight);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        imagecopy($cropped, $image, 0, 0, $left, $top, $cropWidth, $cropHeight);

        ob_start();
        imagepng($cropped);
        $trimmedPath = preg_replace('/\.\w+$/', '', $path).'-trimmed.png';
        $disk->put($trimmedPath, ob_get_clean());

        return $trimmedPath;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
