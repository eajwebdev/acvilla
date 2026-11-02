<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A picture the site can show: a full http(s) link, or a path on this site such as /uploads/site/pool.jpg. */
class ImageSource implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        $local = preg_match('#^/(?!/)[A-Za-z0-9._/-]+$#', $value) && ! str_contains($value, '..');
        $remote = filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value);
        if (strlen($value) > 2048 || (! $local && ! $remote)) {
            $fail('Use an uploaded photo or a full https:// image link.');
        }
    }
}
