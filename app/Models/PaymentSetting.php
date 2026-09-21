<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['qr_image_path', 'instructions'])]
class PaymentSetting extends Model
{
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
