<?php

namespace App\Models;

use App\Notifications\Alerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['project_id', 'title', 'type', 'file_path', 'version', 'uploaded_by'])]
class ProjectDocument extends Model
{
    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'agreement' => 'Agreement / contract',
        'drawing' => 'Drawing',
        'render' => 'Render',
        'structural' => 'Structural',
        'approval_document' => 'Approval document',
        'other' => 'Other',
    ];

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? str($this->type)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * Files are uploaded to the public disk (Filament's default), so link there explicitly.
     */
    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }

    protected static function booted(): void
    {
        static::created(fn (ProjectDocument $document) => Alerts::documentShared($document));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
