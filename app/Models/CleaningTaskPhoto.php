<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CleaningTaskPhoto extends Model
{
    use StampsCreatedAt;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (CleaningTaskPhoto $photo) => Storage::disk('local')->delete($photo->path));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function toCard(): array
    {
        return ['id' => $this->id, 'url' => "/admin/cleaning/photos/{$this->id}", 'by' => $this->uploader?->name,
            'uploaded_by' => $this->uploaded_by, 'at' => $this->created_at?->toIso8601String()];
    }
}
