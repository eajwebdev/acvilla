<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CleaningTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Every departure creates a turnover: the unit must be cleaned before the next guest's check-in. */
class CleaningService
{
    public function __construct(private OperationsService $operations) {}

    /** Make sure each departure on the day has a task, then return the day's tasks with timing. */
    public function forDay(int $tenantId, CarbonImmutable $date, array $settings): Collection
    {
        $iso = $date->toDateString();
        $around = $this->operations->bookingsAround($tenantId, $date, $date);
        $departures = $around->filter(fn (Booking $b) => $b->check_out->toDateString() === $iso);
        foreach ($departures as $booking) {
            CleaningTask::firstOrCreate(['booking_id' => $booking->id],
                ['tenant_id' => $tenantId, 'room_id' => $booking->room_id, 'date' => $iso, 'checklist' => []]);
        }
        $arrivals = $around->filter(fn (Booking $b) => $b->check_in->toDateString() === $iso)->groupBy('room_id');
        $tasks = CleaningTask::with('room:id,name', 'cleaner:id,name', 'booking', 'photos.uploader:id,name')->where('tenant_id', $tenantId)
            ->whereDate('date', $iso)->get()
            // A task whose booking was cancelled or moved no longer belongs to this day.
            ->filter(fn (CleaningTask $t) => ! $t->booking || $departures->contains('id', $t->booking_id));
        $usedArrivals = [];

        return $tasks->map(function (CleaningTask $task) use ($settings, $arrivals, &$usedArrivals) {
            $out = $task->booking ? $task->booking->times($settings)[1] : $settings['check_out_time'];
            $next = $arrivals->get($task->room_id, collect())->first(fn (Booking $b) => ! in_array($b->id, $usedArrivals, true));
            if ($next) {
                $usedArrivals[] = $next->id;
            }
            $readyBy = $next ? $next->times($settings)[0] : $settings['check_in_time'];
            $items = $settings['cleaning_checklist'];
            $done = array_values(array_intersect($task->checklist ?? [], $items));

            return ['id' => $task->id, 'room_id' => $task->room_id, 'unit' => $task->room?->name, 'status' => $task->status,
                'risk' => $this->atRisk($task, $readyBy), 'assigned_to' => $task->assigned_to, 'cleaner' => $task->cleaner?->name,
                'check_out_time' => $out, 'ready_by' => $readyBy, 'next_guest' => $next?->guest_name, 'next_booking_id' => $next?->id,
                'departing_guest' => $task->booking?->guest_name, 'booking_id' => $task->booking_id,
                'checklist' => $done, 'checklist_total' => count($items), 'notes' => $task->notes, 'date' => $task->date->toDateString(),
                'photos' => $task->photos->map(fn ($photo) => $photo->toCard())->values(),
                'started_at' => $task->started_at?->toIso8601String(), 'completed_at' => $task->completed_at?->toIso8601String()];
        })->sortBy([['ready_by', 'asc'], ['unit', 'asc']])->values();
    }

    /** Not finished and less than an hour before the next check-in (or already past it). */
    private function atRisk(CleaningTask $task, string $readyBy): bool
    {
        if ($task->status === 'DONE' || ! $task->date->isToday()) {
            return $task->status !== 'DONE' && $task->date->isPast() && ! $task->date->isToday();
        }

        return now()->greaterThanOrEqualTo(CarbonImmutable::parse($task->date->toDateString().' '.$readyBy)->subHour());
    }
}
