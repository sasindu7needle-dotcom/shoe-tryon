<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'product_id', 'draft', 'source_paths', 'image_tokens', 'options', 'status', 'provider_task_id', 'provider_progress', 'model_path', 'error', 'started_at', 'deadline_at', 'completed_at', 'read_failures', 'next_poll_at'])]
class ModelGeneration extends Model
{
    public const ACTIVE = ['queued', 'uploading', 'submitting', 'generating', 'validating'];

    protected $hidden = ['source_paths', 'image_tokens'];

    protected function casts(): array
    {
        return [
            'draft' => 'array', 'source_paths' => 'array', 'image_tokens' => 'array', 'options' => 'array',
            'started_at' => 'datetime', 'deadline_at' => 'datetime',
            'completed_at' => 'datetime', 'next_poll_at' => 'datetime',
            'provider_progress' => 'integer', 'read_failures' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
