<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DashboardPeriod
{
    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $to;

    public function __construct(Request $request)
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (isset($input['from']) xor isset($input['to'])) {
            throw ValidationException::withMessages(['from' => 'Choose both ends of the date range.']);
        }

        $this->from = isset($input['from']) ? CarbonImmutable::parse($input['from']) : CarbonImmutable::now()->startOfMonth();
        $this->to = isset($input['to']) ? CarbonImmutable::parse($input['to']) : CarbonImmutable::now()->endOfMonth()->startOfDay();

        if ($this->from->gt($this->to) || $this->from->diffInDays($this->to) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose a range of up to one year.']);
        }
    }

    public function previous(): array
    {
        $days = (int) $this->from->diffInDays($this->to) + 1;

        return [$this->from->subDays($days), $this->from->subDay()];
    }

    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
