<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['milestone_template_id', 'title', 'weight', 'sort'])]
class MilestoneTemplateTask extends Model
{
    public function milestoneTemplate(): BelongsTo
    {
        return $this->belongsTo(MilestoneTemplate::class);
    }
}
