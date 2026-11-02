<?php

namespace Tests\Feature;

use App\Models\AttendanceEntry;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_staff_can_clock_in_and_out_once_per_day_and_manager_can_review(): void
    {
        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($staff)->get('/admin/attendance')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Attendance')->where('entry', null)->where('staff', null));
        $this->post('/admin/attendance/clock-out')->assertSessionHasErrors('attendance');
        $this->post('/admin/attendance/clock-in')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/admin/attendance/clock-in')->assertSessionHasErrors('attendance');
        $this->assertSame(1, AttendanceEntry::where('user_id', $staff->id)->count());
        $this->get('/admin/attendance')->assertInertia(fn (Assert $page) => $page->where('entry.method', 'manual')
            ->where('entry.clocked_out_at', null));

        $this->actingAs($admin)->get('/admin/attendance')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Attendance')->where('staff.0.name', $staff->name)->where('staff.0.entry.method', 'manual'));
        $this->post('/admin/attendance/clock-in')->assertForbidden();

        $this->actingAs($staff)->post('/admin/attendance/clock-out')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/admin/attendance/clock-out')->assertSessionHasErrors('attendance');
        $this->assertNotNull(AttendanceEntry::where('user_id', $staff->id)->firstOrFail()->clocked_out_at);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $staff->id, 'action' => 'attendance.clock_in']);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $staff->id, 'action' => 'attendance.clock_out']);
    }

    public function test_attendance_is_tenant_scoped(): void
    {
        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $otherAdmin = User::where('email', 'admin2@example.com')->firstOrFail();
        $this->actingAs($staff)->post('/admin/attendance/clock-in')->assertRedirect();

        $this->actingAs($otherAdmin)->get('/admin/attendance')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Attendance')->has('staff', 0));
    }

    public function test_overnight_shift_can_be_closed_and_manager_can_correct_a_missed_time(): void
    {
        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $start = today()->setTime(23, 50);
        $this->travelTo($start);
        $this->actingAs($staff)->post('/admin/attendance/clock-in')->assertRedirect();
        $entry = AttendanceEntry::where('user_id', $staff->id)->firstOrFail();

        $this->travelTo($start->copy()->addMinutes(20));
        $this->get('/admin/attendance')->assertInertia(fn (Assert $page) => $page->where('entry.id', $entry->id));
        $this->post('/admin/attendance/clock-in')->assertSessionHasErrors('attendance');
        $this->post('/admin/attendance/clock-out')->assertRedirect();
        $this->assertNotNull($entry->fresh()->clocked_out_at);

        $this->actingAs($admin)->put('/admin/attendance/'.$entry->id, [
            'clocked_in_at' => $start->format('Y-m-d\TH:i'),
            'clocked_out_at' => $start->copy()->addMinutes(15)->format('Y-m-d\TH:i'),
            'reason' => 'Corrected staff clock-out time',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.corrected', 'entity_id' => $entry->id]);
        $this->actingAs($staff)->put('/admin/attendance/'.$entry->id, [
            'clocked_in_at' => $start->format('Y-m-d\TH:i'), 'reason' => 'Wrong time',
        ])->assertForbidden();
        $this->travelBack();
    }

    public function test_missed_clock_out_requires_manager_correction_before_another_shift(): void
    {
        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $start = today()->subDays(2)->setTime(9, 0);
        $staff->tenant->update(['subscription_started_at' => today()->subDays(5)]);
        $this->travelTo($start);
        $this->actingAs($staff)->post('/admin/attendance/clock-in')->assertRedirect();
        $entry = AttendanceEntry::where('user_id', $staff->id)->firstOrFail();

        $this->travelTo($start->copy()->addHours(49));
        $this->post('/admin/attendance/clock-out')->assertSessionHasErrors('attendance');
        $this->post('/admin/attendance/clock-in')->assertSessionHasErrors('attendance');
        $this->actingAs($admin)->put('/admin/attendance/'.$entry->id, [
            'clocked_in_at' => $start->format('Y-m-d\TH:i'),
            'clocked_out_at' => $start->copy()->addHours(8)->format('Y-m-d\TH:i'),
            'reason' => 'Staff forgot to clock out',
        ])->assertSessionHasNoErrors();
        $this->actingAs($staff)->post('/admin/attendance/clock-in')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, AttendanceEntry::where('user_id', $staff->id)->count());
        $this->travelBack();
    }
}
