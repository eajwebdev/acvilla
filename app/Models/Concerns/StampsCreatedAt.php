<?php

namespace App\Models\Concerns;

/**
 * For log tables that keep only created_at. The column's database default (CURRENT_TIMESTAMP) is UTC on SQLite and
 * the server's zone on MySQL, while the app reads it as Asia/Manila, so the time is set from the app clock instead.
 */
trait StampsCreatedAt
{
    protected static function bootStampsCreatedAt(): void
    {
        static::creating(fn ($model) => $model->created_at ??= now());
    }
}
