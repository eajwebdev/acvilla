<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_admin_dashboard_follows_selected_day_and_tenant(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $day = today()->addDays(8)->toDateString();
        $this->actingAs($admin)->get('/admin?date='.$day)
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')->where('day.date', $day)->has('day.arrivals', 1)
                ->where('day.arrivals.0.reference', 'EAJ-DEMO-CONFIRMED'));
        $this->get('/admin?date=2020-01-01')
            ->assertInertia(fn (Assert $page) => $page->has('day.arrivals', 0)->where('day.revenueToday', 0));
        $this->actingAs(User::where('email', 'admin2@example.com')->firstOrFail())->get('/admin?date='.$day)
            ->assertInertia(fn (Assert $page) => $page->has('day.arrivals', 0));
    }

    public function test_admin_dashboard_filters_by_date_range(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $from = today()->addDays(8)->toDateString();
        $to = today()->addDays(13)->toDateString();
        // The confirmed stay (nights 8 and 9, out on 10) is inside the range; the other tenant's stay on 11 to 13 is not shown.
        $this->actingAs($admin)->get("/admin?from={$from}&to={$to}")
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')
                ->where('day.date', $from)->where('day.to', $to)->where('day.days', 6)
                ->has('day.arrivals', 1)->where('day.arrivals.0.reference', 'EAJ-DEMO-CONFIRMED')
                ->has('day.departures', 1)->where('day.occupiedNights', 2)->where('day.occupancy', 2)->has('day.revenueWeek', 6)
                ->has('cleaning', 1)->where('cleaningLimited', false));

        // One day inside the range only sees what happens that day.
        $this->get('/admin?from='.today()->addDays(9)->toDateString().'&to='.today()->addDays(9)->toDateString())
            ->assertInertia(fn (Assert $page) => $page->where('day.days', 1)->has('day.arrivals', 0)->where('day.occupied', 1));

        // Long periods keep the totals but skip the day-by-day cleaning list; a year is the most it covers.
        $this->get('/admin?from='.today()->toDateString().'&to='.today()->addYears(3)->toDateString())
            ->assertInertia(fn (Assert $page) => $page->where('day.days', 366)->has('cleaning', 0)->where('cleaningLimited', true)->has('day.arrivals', 2));
        $this->get("/admin?from={$to}&to={$from}")->assertSessionHasErrors('to');

        $csv = $this->get("/admin/today/export?from={$from}&to={$to}&format=csv")->assertDownload("front-desk_{$from}_to_{$to}.csv")->streamedContent();
        $this->assertStringContainsString('EAJ-DEMO-CONFIRMED', $csv);
        $this->assertStringNotContainsString('EAJ-DEMO-MANUAL', $csv);
    }

    public function test_superadmin_and_booking_filters_use_actual_records(): void
    {
        $today = today()->toDateString();
        $owner = User::where('email', 'superadmin@example.com')->firstOrFail();
        $this->actingAs($owner)->get('/superadmin?from='.$today.'&to='.$today)
            ->assertInertia(fn (Assert $page) => $page->component('Superadmin/Dashboard')->where('metrics.bookings', 5));
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin)->get('/admin/bookings?status=CANCELLED')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Bookings')->has('bookings.data', 1)->where('bookings.data.0.status', 'CANCELLED'));
        $this->get('/admin/bookings?from=2020-01-01&to=2020-01-31')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Bookings')->has('bookings.data', 0));
    }

    public function test_bookings_list_filters_by_stay_timing_and_channel_with_tab_counts(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $today = today()->toDateString();
        $mine = Booking::where('tenant_id', $admin->tenant_id);
        $stay = (clone $mine)->whereNotIn('status', ['CANCELLED', 'EXPIRED', 'NO_SHOW'])->firstOrFail();
        $stay->update(['check_in' => today()->subDay(), 'check_out' => today()->addDay(), 'status' => 'CHECKED_IN', 'source' => 'airbnb']);

        $this->actingAs($admin)->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page
            ->where('counts.all', (clone $mine)->count())
            ->where('counts.cancelled', (clone $mine)->whereIn('status', ['CANCELLED', 'EXPIRED', 'NO_SHOW'])->count())
            ->where('summary.in_house', 1)->has('summary.to_collect')->where('today', $today));

        $this->get('/admin/bookings?when=inhouse')->assertInertia(fn (Assert $page) => $page
            ->has('bookings.data', 1)->where('bookings.data.0.id', $stay->id));
        $this->get('/admin/bookings?when=inhouse&channel=direct')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 0));
        $this->get('/admin/bookings?when=inhouse&channel=ota')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1));

        $this->get('/admin/bookings?when=upcoming')->assertInertia(function (Assert $page) use ($today) {
            foreach ($page->toArray()['props']['bookings']['data'] as $row) {
                $this->assertGreaterThanOrEqual($today, substr($row['check_in'], 0, 10));
                $this->assertNotContains($row['status'], ['CANCELLED', 'EXPIRED', 'NO_SHOW']);
            }
        });
        $this->get('/admin/bookings?when=past')->assertInertia(function (Assert $page) use ($today) {
            foreach ($page->toArray()['props']['bookings']['data'] as $row) {
                $this->assertLessThanOrEqual($today, substr($row['check_out'], 0, 10));
            }
        });
        $this->get('/admin/bookings?when=someday')->assertSessionHasErrors('when');
    }

    public function test_occupancy_uses_stay_nights_and_real_inventory(): void
    {
        Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->update([
            'check_in' => today(), 'check_out' => today()->addDay(),
        ]);
        $today = today()->toDateString();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin)->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')->where('day.occupied', 1)->where('day.inventory', 14)->where('day.occupancy', 7));
        $this->get('/admin/insights?range=custom&from='.$today.'&to='.$today)
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Insights')->where('analytics.metrics.occupancy', 7.1)->where('analytics.series.0.occupancy', 7.1));
    }

    public function test_reports_and_csv_export_follow_period_and_tenant(): void
    {
        $today = today()->toDateString();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin)->get('/admin/reports?from='.$today.'&to='.$today)->assertRedirect('/admin/insights?from='.$today.'&to='.$today);
        $this->get('/admin/insights?from='.$today.'&to='.$today)
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Insights')->where('range', 'custom')->has('analytics.units', 6));
        $export = $this->get('/admin/reports/export?from='.$today.'&to='.$today)
            ->assertDownload('bookings_'.$today.'_to_'.$today.'.csv');
        $this->assertStringContainsString('EAJ-DEMO-CONFIRMED', $export->streamedContent());
        $this->assertStringNotContainsString('EAJ-DEMO-MANUAL', $export->streamedContent());
    }
}
