<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CleaningTask;
use App\Models\CleaningTaskPhoto;
use App\Models\User;
use App\Services\CleaningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminCleaningController extends Controller
{
    private const MAX_PHOTOS = 12;

    public function index(Request $request, CleaningService $cleaning)
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'view' => ['nullable', 'in:timeline,workload']]);
        $tenant = $request->user()->tenant;
        $settings = $tenant->settings;
        $date = CarbonImmutable::parse($data['date'] ?? today()->toDateString());

        return Inertia::render('Admin/Cleaning', [
            'date' => $date->toDateString(), 'today' => today()->toDateString(), 'view' => $data['view'] ?? 'timeline',
            'tasks' => $cleaning->forDay($tenant->id, $date, $settings),
            'checklist' => $settings['cleaning_checklist'],
            'checkOutTime' => $settings['check_out_time'], 'checkInTime' => $settings['check_in_time'],
            'cleaners' => User::where('tenant_id', $tenant->id)->whereIn('role', ['ADMIN', 'STAFF'])->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The signed-in person's own assignments: anything still open from the last two weeks, today's work, and the week ahead. */
    public function mine(Request $request, CleaningService $cleaning)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $settings = $tenant->settings;
        $today = CarbonImmutable::today();
        $dates = CleaningTask::where('tenant_id', $tenant->id)->where('assigned_to', $user->id)
            ->whereDate('date', '<=', $today->addDays(7)->toDateString())
            ->where(fn ($q) => $q->whereDate('date', $today->toDateString())->orWhereDate('completed_at', $today->toDateString())
                ->orWhere(fn ($open) => $open->where('status', '!=', 'DONE')->whereDate('date', '>=', $today->subDays(14)->toDateString())))
            ->pluck('date')->map(fn ($d) => $d->toDateString())->unique()->sort()->values();
        $tasks = $dates->flatMap(fn (string $d) => $cleaning->forDay($tenant->id, CarbonImmutable::parse($d), $settings)
            ->where('assigned_to', $user->id)->values())->values();

        return Inertia::render('Admin/MyTasks', [
            'today' => $today->toDateString(), 'tasks' => $tasks, 'checklist' => $settings['cleaning_checklist'],
            'photoRequired' => $user->role === 'STAFF' && $settings['cleaning_photo_required'],
            'maxPhotos' => self::MAX_PHOTOS,
        ]);
    }

    public function update(Request $request, CleaningTask $task)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        abort_unless($task->tenant_id === $tenant->id, 404);
        abort_unless($user->role === 'ADMIN' || $task->assigned_to === $user->id, 403);
        $data = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', CleaningTask::STATUSES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'checklist' => ['sometimes', 'array', 'max:40'],
            'checklist.*' => ['string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if ($user->role === 'STAFF') {
            abort_if(array_key_exists('assigned_to', $data), 403);
            abort_if($task->status === 'DONE', 403);
        }
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            abort_unless(User::where('tenant_id', $tenant->id)->whereIn('role', ['ADMIN', 'STAFF'])->whereKey($data['assigned_to'])->exists(), 422);
        }
        if ((isset($data['checklist']) || (isset($data['status']) && $data['status'] !== 'NOT_STARTED')) && $task->date->isFuture()) {
            throw ValidationException::withMessages(['status' => $this->notYet($task)]);
        }
        // Staff prove the room is done with a photo; admins can still close a task from the board.
        $needsPhoto = $request->user()->role === 'STAFF' && $tenant->setting('cleaning_photo_required') && ! $task->photos()->exists();
        if (isset($data['checklist'])) {
            $data['checklist'] = array_values(array_intersect($tenant->setting('cleaning_checklist'), $data['checklist']));
            $all = count($tenant->setting('cleaning_checklist'));
            // Ticking the first item starts the clean; ticking the last finishes it (once there is a photo, when one is required).
            if (! isset($data['status']) && $all > 0 && count($data['checklist']) === $all && ! $needsPhoto) {
                $data['status'] = 'DONE';
            } elseif (! isset($data['status']) && $data['checklist'] && $task->status !== 'IN_PROGRESS') {
                $data['status'] = 'IN_PROGRESS';
            }
        }
        if (($data['status'] ?? null) === 'DONE' && $task->status !== 'DONE' && $needsPhoto) {
            throw ValidationException::withMessages(['photo' => 'Take a photo of the finished room before marking it ready.']);
        }
        if (isset($data['status']) && $data['status'] !== $task->status) {
            $data += match ($data['status']) {
                'IN_PROGRESS' => ['started_at' => $task->started_at ?? now(), 'completed_at' => null],
                'DONE' => ['started_at' => $task->started_at ?? now(), 'completed_at' => now(), 'checklist' => $data['checklist'] ?? $tenant->setting('cleaning_checklist')],
                default => ['started_at' => null, 'completed_at' => null],
            };
            $this->audit($request, $task, 'cleaning.'.strtolower($data['status']));
        }
        $task->update($data);

        return back()->with('success', match ($data['status'] ?? null) {
            'DONE' => "{$task->room?->name} marked ready.", 'IN_PROGRESS' => 'Cleaning started.', 'NOT_STARTED' => 'Task reset.', default => 'Cleaning task updated.',
        });
    }

    public function storePhoto(Request $request, CleaningTask $task)
    {
        $user = $request->user();
        abort_unless($task->tenant_id === $user->tenant_id, 404);
        abort_unless($user->role === 'ADMIN' || ($task->assigned_to === $user->id && $task->status !== 'DONE'), 403);
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], ['photos.*.image' => 'That file is not a photo.', 'photos.*.max' => 'Each photo must be under 10 MB.']);
        if ($task->date->isFuture()) {
            throw ValidationException::withMessages(['photos' => $this->notYet($task)]);
        }
        $files = $request->file('photos');
        if ($task->photos()->count() + count($files) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'A task can keep up to '.self::MAX_PHOTOS.' photos. Remove one first.']);
        }
        foreach ($files as $file) {
            $task->photos()->create(['path' => $file->store("cleaning/{$task->tenant_id}/{$task->id}", 'local'), 'uploaded_by' => $request->user()->id]);
        }
        if ($task->status === 'NOT_STARTED') {
            $task->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);
        }
        $this->audit($request, $task, 'cleaning.photo_added', ['count' => count($files)]);

        return back()->with('success', count($files) === 1 ? 'Photo saved.' : count($files).' photos saved.');
    }

    /** Photos sit on the private disk and are only served to signed-in staff of the same tenant. */
    public function showPhoto(Request $request, CleaningTaskPhoto $photo)
    {
        abort_unless($photo->task?->tenant_id === $request->user()->tenant_id, 404);
        abort_unless($request->user()->role === 'ADMIN' || $photo->task->assigned_to === $request->user()->id, 403);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function destroyPhoto(Request $request, CleaningTaskPhoto $photo)
    {
        $user = $request->user();
        $task = $photo->task;
        abort_unless($task?->tenant_id === $user->tenant_id, 404);
        abort_unless($user->role === 'ADMIN' || ($task->assigned_to === $user->id && $photo->uploaded_by === $user->id && $task->status !== 'DONE'), 403);
        $photo->delete();
        $this->audit($request, $task, 'cleaning.photo_removed');

        return back()->with('success', 'Photo removed.');
    }

    /** A turnover can only be worked on from its own day: the guest has not checked out before then. */
    private function notYet(CleaningTask $task): string
    {
        return 'This turnover is on '.$task->date->format('D, M j').'. You can start it on that day.';
    }

    private function audit(Request $request, CleaningTask $task, string $action, array $meta = []): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $task->tenant_id, 'action' => $action,
            'entity_type' => 'CleaningTask', 'entity_id' => $task->id,
            'metadata' => ['room_id' => $task->room_id, 'date' => $task->date->toDateString(), ...$meta], 'ip_address' => $request->ip()]);
    }
}
