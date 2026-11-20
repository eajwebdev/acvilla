<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CleaningTask extends Model
{
    public const STATUSES = ['NOT_STARTED', 'IN_PROGRESS', 'DONE'];

    protected $guarded = ['id'];

    /** Added by hand (a title, maybe no room), as opposed to the turnover made for a check-out. */
    public function isManual(): bool
    {
        return $this->booking_id === null && filled($this->title);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'checklist' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(CleaningTaskPhoto::class)->orderBy('id');
    }

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
