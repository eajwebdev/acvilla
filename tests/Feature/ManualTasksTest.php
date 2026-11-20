<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Tasks added by hand on the Cleaning board, the Manager role, and the platform owner working in the resort's admin. */
class ManualTasksTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $this->tenant = Tenant::where('slug', 'island-collection')->sole();
        $this->tenant->update(['settings' => [...$this->tenant->settings, 'cleaning_photo_required' => true]]);
        config(['platform.site_tenant' => 'island-collection']);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    private function manager(): User
    {
        return User::create(['name' => 'Mila Manager', 'email' => 'manager@example.com', 'password' => 'Password123!', 'role' => 'MANAGER', 'tenant_id' => $this->tenant->id]);
    }

    public function test_admin_adds_a_task_for_staff_who_finishes_it_without_a_photo(): void
    {
        $staff = $this->user('staff@example.com');
        $room = Room::where('tenant_id', $this->tenant->id)->first();
        $this->actingAs($this->user('admin@example.com'))->post('/admin/cleaning', [
            'title' => '  Clean the pool  ', 'date' => today()->toDateString(), 'due_time' => '15:00',
            'room_id' => $room->id, 'assigned_to' => $staff->id, 'notes' => 'Skimmer is in the shed',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $task = CleaningTask::sole();
        $this->assertSame(['Clean the pool', null, '15:00', $staff->id], [$task->title, $task->booking_id, $task->due_time, $task->assigned_to]);
        $this->assertTrue($task->isManual());
        $this->get('/admin/cleaning')->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.kind', 'manual')->where('tasks.0.unit', 'Clean the pool')->where('tasks.0.room', $room->name)
            ->where('tasks.0.ready_by', '15:00')->where('tasks.0.check_out_time', null)->where('tasks.0.checklist_total', 0)
            ->where('tasks.0.created_by', 'Demo Administrator'));

        $this->actingAs($staff)->get('/admin/my-tasks')->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)->where('tasks.0.unit', 'Clean the pool'));
        // A task added by hand never needs a photo, even when room cleanings do.
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'IN_PROGRESS'])->assertSessionHasNoErrors();
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'DONE'])->assertSessionHasNoErrors();
        $this->assertSame(['DONE', []], [$task->fresh()->status, $task->fresh()->checklist]);
        // Staff can't change what the task is, or add tasks.
        $this->put('/admin/cleaning/'.$task->id, ['title' => 'Something else'])->assertForbidden();
        $this->post('/admin/cleaning', ['title' => 'Mine', 'date' => today()->toDateString()])->assertForbidden();
        $this->get('/admin/cleaning')->assertForbidden();
    }

    public function test_a_task_needs_a_title_a_day_from_today_and_someone_from_the_same_team(): void
    {
        $other = $this->user('admin2@example.com');
        $this->actingAs($this->user('admin@example.com'))->post('/admin/cleaning', [
            'title' => '', 'date' => today()->subDay()->toDateString(), 'due_time' => '25:00',
            'assigned_to' => $other->id, 'room_id' => Room::where('tenant_id', '!=', $this->tenant->id)->value('id'),
        ])->assertSessionHasErrors(['title', 'date', 'due_time', 'assigned_to', 'room_id']);
        $this->assertDatabaseCount('cleaning_tasks', 0);
    }

    public function test_manager_adds_assigns_edits_and_removes_tasks_but_not_turnovers(): void
    {
        $manager = $this->manager();
        $staff = $this->user('staff@example.com');
        $this->actingAs($manager)->get('/admin')->assertRedirect('/admin/my-tasks');
        $this->get('/admin/cleaning')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cleaners', fn ($people) => collect($people)->pluck('name')->contains('Mila Manager')));

        $this->post('/admin/cleaning', ['title' => 'Restock the bar', 'date' => today()->addDay()->toDateString()])->assertSessionHasNoErrors();
        $task = CleaningTask::sole();
        $this->put('/admin/cleaning/'.$task->id, ['assigned_to' => $staff->id, 'title' => 'Restock the minibar', 'due_time' => '10:30'])->assertSessionHasNoErrors();
        $this->assertSame(['Restock the minibar', '10:30', $staff->id], [$task->fresh()->title, $task->fresh()->due_time, $task->fresh()->assigned_to]);
        // Tomorrow's task can't be started today.
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'IN_PROGRESS'])->assertSessionHasErrors('status');
        $this->delete('/admin/cleaning/'.$task->id)->assertRedirect();
        $this->assertDatabaseCount('cleaning_tasks', 0);

        // A check-out cleaning belongs to its booking: it can't be removed or renamed.
        $booking = Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->sole();
        $booking->update(['check_in' => today()->subDay(), 'check_out' => today(), 'status' => 'CHECKED_IN']);
        $this->get('/admin/cleaning')->assertOk();
        $turnover = CleaningTask::sole();
        $this->delete('/admin/cleaning/'.$turnover->id)->assertSessionHasErrors('task');
        $this->put('/admin/cleaning/'.$turnover->id, ['title' => 'Renamed'])->assertForbidden();
        $this->assertDatabaseCount('cleaning_tasks', 1);

        // Managers keep the staff side too: their own shift.
        $this->post('/admin/attendance/clock-in')->assertSessionHasNoErrors();
        // But not the owner's pages.
        $this->get('/admin/settings/team')->assertForbidden();
        $this->get('/admin/customers')->assertForbidden();
    }

    public function test_a_manual_task_does_not_take_the_next_arrival_from_the_rooms_turnover(): void
    {
        $booking = Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->sole();
        $booking->update(['check_in' => today()->subDay(), 'check_out' => today(), 'status' => 'CHECKED_IN']);
        $arrival = Booking::where('reference', 'EAJ-DEMO-PENDING')->sole();
        $arrival->update(['room_id' => $booking->room_id, 'check_in' => today(), 'check_out' => today()->addDay(), 'status' => 'CONFIRMED', 'guest_name' => 'Next Guest']);
        $admin = $this->user('admin@example.com');
        $this->actingAs($admin)->post('/admin/cleaning', ['title' => 'Fix the aircon', 'date' => today()->toDateString(), 'room_id' => $booking->room_id]);

        $this->get('/admin/cleaning')->assertInertia(fn (Assert $page) => $page->has('tasks', 2)
            ->where('tasks', fn ($tasks) => collect($tasks)->firstWhere('kind', 'turnover')['next_guest'] === 'Next Guest'
                && collect($tasks)->firstWhere('kind', 'manual')['next_guest'] === null));
    }

    public function test_the_team_page_can_add_and_promote_a_manager(): void
    {
        $this->actingAs($this->user('admin@example.com'))->post('/admin/settings/team', [
            'name' => 'New Manager', 'email' => 'newmanager@example.com', 'role' => 'MANAGER', 'password' => 'a-long-password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('MANAGER', User::where('email', 'newmanager@example.com')->value('role'));
        $staff = $this->user('staff@example.com');
        $this->put('/admin/settings/team/'.$staff->id, ['role' => 'MANAGER'])->assertSessionHasNoErrors();
        $this->assertSame('MANAGER', $staff->fresh()->role);
    }

    public function test_superadmin_works_in_the_resort_admin_as_its_admin_without_changing_their_account(): void
    {
        $owner = $this->user('superadmin@example.com');
        $staff = $this->user('staff@example.com');
        $this->actingAs($owner)->get('/admin/cleaning')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.role', 'ADMIN')->where('auth.user.superadmin', true)->where('auth.user.tenant_id', $this->tenant->id));
        $this->post('/admin/cleaning', ['title' => 'Check the generator', 'date' => today()->toDateString(), 'assigned_to' => $staff->id])->assertSessionHasNoErrors();
        $task = CleaningTask::sole();
        $this->assertSame([$this->tenant->id, $owner->id], [$task->tenant_id, $task->created_by]);
        $this->get('/admin/settings/team')->assertOk();
        $this->get('/admin')->assertOk();

        // A profile save in the resort's admin never attaches the platform account to the resort.
        $this->put('/admin/settings/profile', ['name' => 'Platform Owner', 'email' => $owner->email])->assertSessionHasNoErrors();
        $this->assertSame(['SUPERADMIN', null, 'Platform Owner'], [$owner->fresh()->role, $owner->fresh()->tenant_id, $owner->fresh()->name]);
        // Each real request loads the account afresh (the test reuses one object, which the visit changed in memory).
        $this->actingAs($owner->fresh())->delete('/admin/settings/profile', ['password' => 'Password123!'])->assertForbidden();
        $this->assertNotNull($owner->fresh());
        // And the platform pages still work.
        $this->actingAs($owner->fresh())->get('/superadmin')->assertOk();
    }
}
