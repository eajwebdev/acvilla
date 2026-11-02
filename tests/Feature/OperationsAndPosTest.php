<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CleaningTask;
use App\Models\Expense;
use App\Models\PosItem;
use App\Models\PosSale;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsAndPosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** Move the demo confirmed stay so it checks in today and leaves tomorrow. */
    private function inHouse(string $status = 'CHECKED_IN'): Booking
    {
        $booking = Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->firstOrFail();
        $booking->update(['check_in' => today(), 'check_out' => today()->addDay(), 'status' => $status]);

        return $booking->fresh();
    }

    /** The demo confirmed stay, in house since yesterday and checking out today: today's turnover. */
    private function departingToday(): Booking
    {
        $booking = Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->firstOrFail();
        $booking->update(['check_in' => today()->subDay(), 'check_out' => today(), 'status' => 'CHECKED_IN']);

        return $booking->fresh();
    }

    public function test_today_dashboard_lists_arrivals_departures_and_attention(): void
    {
        $booking = $this->inHouse('CONFIRMED');
        $this->actingAs($this->user('admin@example.com'))->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')
                ->where('day.date', today()->toDateString())
                ->has('day.arrivals', 1)->where('day.arrivals.0.id', $booking->id)
                ->where('day.inventory', 14)->where('day.occupied', 1)
                ->has('trend', 12)->where('target', 0)->has('attention')->has('cleaning', 0));

        $this->get('/admin?date='.today()->addDay()->toDateString())
            ->assertInertia(fn (Assert $page) => $page->has('day.departures', 1)->has('cleaning', 1));
        $this->assertDatabaseHas('cleaning_tasks', ['booking_id' => $booking->id, 'status' => 'NOT_STARTED']);

        $csv = $this->get('/admin/today/export?date='.today()->toDateString())->assertDownload('front-desk_'.today()->toDateString().'.csv');
        $this->assertStringContainsString('EAJ-DEMO-CONFIRMED', $csv->streamedContent());
        $this->get('/admin?date=not-a-date')->assertSessionHasErrors('date');
    }

    public function test_calendar_shows_bookings_blocks_and_respects_tenant(): void
    {
        $admin = $this->user('admin@example.com');
        $this->actingAs($admin)->get('/admin/calendar?month='.today()->format('Y-m'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Calendar')->where('inventory', 14)->has('rooms', 6)->has('bookings'));
        $room = Room::where('tenant_id', $admin->tenant_id)->where('slug', 'garden-room')->firstOrFail();
        $this->post('/admin/calendar/blocks', ['room_id' => $room->id, 'starts_on' => today()->addDays(2)->toDateString(), 'ends_on' => today()->addDays(4)->toDateString(), 'reason' => 'Repaint'])->assertRedirect();
        $confirmed = Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->firstOrFail();
        $this->get('/admin/calendar?month='.today()->format('Y-m'))
            ->assertInertia(fn (Assert $page) => $page->has('blocked', 1)->where('blocked.0.starts_on', today()->addDays(2)->toDateString())->where('blocked.0.unit', 'Garden Room'));
        // Date-only columns serialize as plain Y-m-d so the browser never shifts them across time zones.
        $this->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page->where('bookings.data.0.check_in', fn ($value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1));
        $this->assertSame($confirmed->check_in->toDateString(), $confirmed->toArray()['check_in']);
        $this->get('/admin/insights?range=7d')->assertOk();
        $foreignRoom = Room::where('slug', 'heritage-suite')->firstOrFail();
        $this->get('/admin/calendar?room_id='.$foreignRoom->id)->assertNotFound();
    }

    public function test_bookings_tabs_detail_and_availability(): void
    {
        $admin = $this->user('admin@example.com');
        $booking = Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->firstOrFail();
        $this->actingAs($admin)->get('/admin/bookings?tab=cancelled')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Bookings')->has('bookings.data', 1)->where('bookings.data.0.status', 'CANCELLED'));
        $this->get('/admin/bookings?tab=paid&sort=balance&dir=asc')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 2));
        $this->get('/admin/bookings?search=Signature&sort=unit')->assertOk();

        $this->getJson('/admin/bookings/'.$booking->id)->assertOk()
            ->assertJsonPath('booking.reference', 'EAJ-DEMO-CONFIRMED')->assertJsonPath('transitions', ['CHECKED_IN', 'CANCELLED', 'NO_SHOW']);
        $this->actingAs($this->user('admin2@example.com'))->getJson('/admin/bookings/'.$booking->id)->assertNotFound();

        $this->actingAs($admin)->getJson('/admin/bookings/availability?check_in='.today()->addDays(8)->toDateString().'&check_out='.today()->addDays(9)->toDateString().'&guests=2')
            ->assertOk()->assertJsonCount(6, 'units')->assertJsonPath('check_in_time', '14:00')
            ->assertJsonFragment(['id' => $booking->room_id, 'available' => true]);
    }

    public function test_walk_in_group_booking_is_confirmed_without_email(): void
    {
        $admin = $this->user('admin@example.com');
        $rooms = Room::where('tenant_id', $admin->tenant_id)->where('slug', 'garden-room')->orWhere(fn ($q) => $q->where('tenant_id', $admin->tenant_id)->where('slug', 'mountain-studio'))->pluck('id')->all();
        $this->actingAs($admin)->post('/admin/bookings', [
            'room_ids' => $rooms, 'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(), 'guests' => 2,
            'guest_name' => 'Barkada Group', 'guest_phone' => 'FB: Juan Dela Cruz', 'source' => 'walk_in',
            'check_in_time' => '13:00', 'check_out_time' => '11:00', 'confirm' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $group = Booking::where('guest_name', 'Barkada Group')->get();
        $this->assertCount(2, $group);
        $this->assertEquals(1, $group->pluck('group_code')->unique()->count());
        $this->assertTrue($group->every(fn (Booking $b) => $b->status === 'CONFIRMED' && $b->guest_email === null && $b->expires_at === null && $b->source === 'walk_in' && $b->check_in_time === '13:00'));
        $this->assertDatabaseMissing('booking_messages', ['booking_id' => $group->first()->id]);

        $this->put('/admin/bookings/'.$group->first()->id, ['guest_name' => 'Barkada Group', 'source' => 'direct', 'guest_email' => 'barkada@example.com', 'notes' => 'Late arrival'])->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $group->first()->id, 'source' => 'direct', 'notes' => 'Late arrival']);
    }

    public function test_booking_sources_are_recorded_filtered_and_reported(): void
    {
        $admin = $this->user('admin@example.com');
        $room = Room::where('tenant_id', $admin->tenant_id)->where('slug', 'garden-room')->firstOrFail();
        $base = ['room_id' => $room->id, 'check_in' => today()->addDays(30)->toDateString(), 'check_out' => today()->addDays(31)->toDateString(),
            'guests' => 2, 'confirm' => true];
        $this->actingAs($admin)->post('/admin/bookings', [...$base, 'guest_name' => 'Messenger Guest', 'guest_phone' => 'FB: Ana Reyes', 'source' => 'messenger'])->assertSessionHasNoErrors();
        $this->post('/admin/bookings', [...$base, 'guest_name' => 'Insta Guest', 'source' => 'instagram'])->assertSessionHasNoErrors();
        $this->post('/admin/bookings', [...$base, 'guest_name' => 'WA Guest', 'source' => 'whatsapp'])->assertSessionHasNoErrors();
        $this->post('/admin/bookings', [...$base, 'guest_name' => 'Bad Source', 'source' => 'carrier_pigeon'])->assertSessionHasErrors('source');
        $this->assertDatabaseHas('bookings', ['guest_name' => 'Messenger Guest', 'source' => 'messenger']);

        $this->get('/admin/bookings?source=instagram')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1)->where('bookings.data.0.guest_name', 'Insta Guest'));
        // Social and messaging channels count as direct (no OTA commission).
        $this->get('/admin/bookings?tab=direct')->assertInertia(fn (Assert $page) => $page->where('bookings.data', fn ($rows) => collect($rows)->pluck('guest_name')->intersect(['Messenger Guest', 'Insta Guest', 'WA Guest'])->count() === 3));
        $this->get('/admin/bookings?source=tiktok_live')->assertSessionHasErrors('source');

        $today = today()->toDateString();
        $this->get("/admin/insights?range=custom&from={$today}&to={$today}")->assertInertia(fn (Assert $page) => $page
            ->where('analytics.sources', fn ($sources) => collect($sources)->firstWhere('source', 'messenger')['bookings'] === 1
                && collect($sources)->firstWhere('source', 'messenger')['label'] === 'Messenger'
                && collect($sources)->firstWhere('source', 'instagram')['direct'] === true));
        $csv = $this->get("/admin/reports/export?from={$today}&to={$today}")->streamedContent();
        $this->assertStringContainsString('Source', $csv);
        $this->assertStringContainsString('WhatsApp', $csv);
    }

    /** The browser keeps its own copy of the source list; it must match the server's. */
    public function test_frontend_source_list_matches_backend(): void
    {
        $ops = file_get_contents(resource_path('js/Components/ops.tsx'));
        preg_match('/export const SOURCES: Record<string, string> = \{(.*?)\};/s', $ops, $m);
        preg_match_all("/(\w+): '([^']+)'/", $m[1], $pairs, PREG_SET_ORDER);
        $this->assertSame(Booking::SOURCES, collect($pairs)->mapWithKeys(fn ($p) => [$p[1] => $p[2]])->all());
        preg_match('/export const DIRECT_SOURCES = \[(.*?)\];/s', $ops, $d);
        preg_match_all("/'(\w+)'/", $d[1], $direct);
        $this->assertSame(Booking::DIRECT_SOURCES, $direct[1]);
    }

    public function test_cleaning_board_assigns_ticks_checklist_and_finishes(): void
    {
        $this->departingToday();
        $staff = $this->user('staff@example.com');
        $this->actingAs($this->user('admin@example.com'))->get('/admin/cleaning')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Cleaning')->has('tasks', 1)->where('tasks.0.checklist_total', 9)->has('cleaners', 2));
        $task = CleaningTask::firstOrFail();
        $checklist = config('platform.tenant_defaults.cleaning_checklist');

        $this->put('/admin/cleaning/'.$task->id, ['assigned_to' => $staff->id, 'checklist' => [$checklist[0], 'Not a real step']])->assertRedirect();
        $task->refresh();
        $this->assertSame('IN_PROGRESS', $task->status);
        $this->assertSame([$checklist[0]], $task->checklist);
        $this->assertNotNull($task->started_at);

        // Without a photo, ticking every step keeps the room in progress and an explicit "ready" is refused.
        $this->actingAs($staff)->put('/admin/cleaning/'.$task->id, ['checklist' => $checklist])->assertRedirect();
        $this->assertSame('IN_PROGRESS', $task->fresh()->status);
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'DONE'])->assertSessionHasErrors('photo');
        $this->post('/admin/cleaning/'.$task->id.'/photos', ['photos' => [UploadedFile::fake()->image('room.jpg', 800, 600)]])->assertRedirect()->assertSessionHasNoErrors();
        $this->put('/admin/cleaning/'.$task->id, ['checklist' => $checklist])->assertRedirect();
        $this->assertSame('DONE', $task->fresh()->status);
        $this->actingAs($this->user('admin@example.com'))->put('/admin/cleaning/'.$task->id, ['assigned_to' => $this->user('admin2@example.com')->id])->assertStatus(422);
        $this->actingAs($this->user('admin2@example.com'))->put('/admin/cleaning/'.$task->id, ['status' => 'NOT_STARTED'])->assertNotFound();
    }

    public function test_staff_see_their_tasks_and_prove_them_with_photos(): void
    {
        $booking = $this->departingToday();
        $staff = $this->user('staff@example.com');
        $admin = $this->user('admin@example.com');
        $today = today()->toDateString();
        $this->actingAs($admin)->get('/admin/cleaning')->assertOk();
        $task = CleaningTask::where('booking_id', $booking->id)->firstOrFail();

        $this->actingAs($staff)->get('/admin/my-tasks')->assertInertia(fn (Assert $page) => $page->component('Admin/MyTasks')->has('tasks', 0)->where('myTaskCount', 0));
        $this->actingAs($admin)->put('/admin/cleaning/'.$task->id, ['assigned_to' => $staff->id])->assertRedirect();
        $this->actingAs($staff)->get('/admin/my-tasks')->assertInertia(fn (Assert $page) => $page->has('tasks', 1)
            ->where('tasks.0.id', $task->id)->where('tasks.0.date', $today)->where('photoRequired', true)->where('maxPhotos', 12));
        $this->actingAs($admin)->get('/admin/my-tasks')->assertInertia(fn (Assert $page) => $page->has('tasks', 0)->where('photoRequired', false));

        $this->actingAs($staff)->post('/admin/cleaning/'.$task->id.'/photos', ['photos' => [UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')]])->assertSessionHasErrors('photos.0');
        $this->post('/admin/cleaning/'.$task->id.'/photos', ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')]])->assertSessionHasNoErrors();
        $task->refresh();
        $this->assertCount(2, $task->photos);
        $this->assertSame('IN_PROGRESS', $task->status);
        $photo = $task->photos->first();
        Storage::disk('local')->assertExists($photo->path);
        $this->assertStringStartsWith("cleaning/{$task->tenant_id}/{$task->id}/", $photo->path);
        $this->get('/admin/cleaning/photos/'.$photo->id)->assertOk();
        $this->get('/admin/my-tasks')->assertInertia(fn (Assert $page) => $page->has('tasks.0.photos', 2)->where('tasks.0.photos.0.url', '/admin/cleaning/photos/'.$photo->id));
        $this->actingAs($this->user('admin2@example.com'))->get('/admin/cleaning/photos/'.$photo->id)->assertNotFound();
        $this->post('/admin/cleaning/'.$task->id.'/photos', ['photos' => [UploadedFile::fake()->image('x.jpg')]])->assertNotFound();

        $this->actingAs($staff)->put('/admin/cleaning/'.$task->id, ['status' => 'DONE'])->assertSessionHasNoErrors();
        $this->assertSame('DONE', $task->fresh()->status);
        // Once the room is ready, staff can no longer delete its proof; an admin can.
        $this->delete('/admin/cleaning/photos/'.$photo->id)->assertForbidden();
        $this->actingAs($admin)->delete('/admin/cleaning/photos/'.$photo->id)->assertRedirect();
        Storage::disk('local')->assertMissing($photo->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cleaning.photo_added', 'entity_id' => $task->id]);
        // A room finished today stays on the list under "Finished".
        $this->actingAs($staff)->get('/admin/my-tasks')->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.status', 'DONE'));
    }

    public function test_staff_workspace_keeps_management_data_and_other_assignments_private(): void
    {
        $booking = $this->departingToday();
        $admin = $this->user('admin@example.com');
        $staff = $this->user('staff@example.com');
        $this->actingAs($admin)->get('/admin/cleaning')->assertOk();
        $task = CleaningTask::where('booking_id', $booking->id)->firstOrFail();

        $this->actingAs($staff)->get('/admin')->assertRedirect('/admin/my-tasks');
        $this->get('/admin/cleaning')->assertForbidden();
        $this->get('/admin/insights')->assertForbidden();
        $this->get('/admin/reports/export')->assertForbidden();
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'IN_PROGRESS'])->assertForbidden();
        $this->post('/admin/cleaning/'.$task->id.'/photos', ['photos' => [UploadedFile::fake()->image('room.jpg')]])->assertForbidden();

        $this->actingAs($admin)->put('/admin/cleaning/'.$task->id, ['assigned_to' => $staff->id])->assertRedirect();
        $this->actingAs($staff)->put('/admin/cleaning/'.$task->id, ['assigned_to' => $admin->id])->assertForbidden();
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'IN_PROGRESS'])->assertRedirect();
    }

    public function test_future_turnovers_cannot_be_worked_on_early(): void
    {
        $booking = $this->inHouse();
        $staff = $this->user('staff@example.com');
        $this->actingAs($this->user('admin@example.com'))->get('/admin/cleaning?date='.today()->addDay()->toDateString())->assertOk();
        $task = CleaningTask::where('booking_id', $booking->id)->firstOrFail();
        $this->put('/admin/cleaning/'.$task->id, ['assigned_to' => $staff->id, 'notes' => 'Deep clean'])->assertSessionHasNoErrors();
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'DONE'])->assertSessionHasErrors('status');

        $this->actingAs($staff)->get('/admin/my-tasks')->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.notes', 'Deep clean'));
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'IN_PROGRESS'])->assertSessionHasErrors('status');
        $this->put('/admin/cleaning/'.$task->id, ['checklist' => ['Replace towels']])->assertSessionHasErrors('status');
        $this->post('/admin/cleaning/'.$task->id.'/photos', ['photos' => [UploadedFile::fake()->image('early.jpg')]])->assertSessionHasErrors('photos');
        $task->refresh();
        $this->assertSame('NOT_STARTED', $task->status);
        $this->assertCount(0, $task->photos);
    }

    public function test_photo_rule_can_be_turned_off_and_admins_can_close_without_photo(): void
    {
        $booking = $this->departingToday();
        $admin = $this->user('admin@example.com');
        $this->actingAs($admin)->get('/admin/cleaning')->assertOk();
        $task = CleaningTask::where('booking_id', $booking->id)->firstOrFail();
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'DONE'])->assertSessionHasNoErrors();
        $this->assertSame('DONE', $task->fresh()->status);
        $this->put('/admin/cleaning/'.$task->id, ['status' => 'NOT_STARTED'])->assertRedirect();

        $this->put('/admin/settings/cleaning', ['cleaning_checklist' => ['Make beds'], 'cleaning_photo_required' => false])->assertRedirect();
        $this->put('/admin/cleaning/'.$task->id, ['assigned_to' => $this->user('staff@example.com')->id])->assertRedirect();
        $this->actingAs($this->user('staff@example.com'))->put('/admin/cleaning/'.$task->id, ['status' => 'DONE'])->assertSessionHasNoErrors();
        $this->assertSame('DONE', $task->fresh()->status);
    }

    public function test_pos_cash_sale_gives_change_and_tracks_stock(): void
    {
        $staff = $this->user('staff@example.com');
        $water = PosItem::where('name', 'Bottled water')->firstOrFail();
        $chips = PosItem::where('name', 'Chips')->firstOrFail();
        $this->actingAs($staff)->get('/admin/pos')->assertInertia(fn (Assert $page) => $page->component('Admin/Pos')->has('items', 11)->where('receipt', null));

        $this->post('/admin/pos/sales', ['lines' => [['item_id' => $water->id, 'quantity' => 3], ['name' => 'Broken glass', 'price' => 150, 'quantity' => 1]],
            'discount' => 20, 'payment_method' => 'cash', 'amount_tendered' => 500, 'customer_name' => 'Walk-in Ana'])->assertRedirect()->assertSessionHasNoErrors();
        $sale = PosSale::with('items')->firstOrFail();
        $this->assertSame('R-000001', $sale->number);
        $this->assertEquals(250, (float) $sale->total);
        $this->assertSame('PAID', $sale->status);
        $this->assertCount(2, $sale->items);
        $this->assertSame(45, $water->fresh()->stock);
        $this->get('/admin/pos')->assertInertia(fn (Assert $page) => $page->where('receipt.number', 'R-000001')->where('summary.collected', 250)->where('summary.count', 1));

        $this->post('/admin/pos/sales', ['lines' => [['item_id' => $chips->id, 'quantity' => 3]], 'payment_method' => 'cash'])->assertSessionHasErrors('lines.0.quantity');
        $this->post('/admin/pos/sales', ['lines' => [['item_id' => $water->id, 'quantity' => 1]], 'payment_method' => 'cash', 'amount_tendered' => 10])->assertSessionHasErrors('amount_tendered');
        $this->post('/admin/pos/sales', ['lines' => [['item_id' => $water->id, 'quantity' => 1]], 'payment_method' => 'room_charge'])->assertSessionHasErrors('payment_method');
        $this->assertEquals(1, PosSale::count());

        $this->post('/admin/pos/sales/'.$sale->id.'/void', ['reason' => 'Wrong item'])->assertForbidden();
        $this->actingAs($this->user('admin@example.com'))->post('/admin/pos/sales/'.$sale->id.'/void', ['reason' => 'Wrong item'])->assertRedirect();
        $this->assertSame('VOID', $sale->fresh()->status);
        $this->assertSame(48, $water->fresh()->stock);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pos.void', 'entity_id' => $sale->id]);
        $this->actingAs($staff)->post('/admin/pos/sales', ['lines' => [['item_id' => $water->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertRedirect();
        $this->assertSame('R-000002', PosSale::latest('id')->firstOrFail()->number);
    }

    public function test_room_charges_block_checkout_until_settled(): void
    {
        $booking = $this->inHouse();
        $staff = $this->user('staff@example.com');
        $beer = PosItem::where('name', 'San Miguel beer')->firstOrFail();
        $this->actingAs($staff)->post('/admin/pos/sales', ['lines' => [['item_id' => $beer->id, 'quantity' => 2]], 'payment_method' => 'room_charge', 'booking_id' => $booking->id])
            ->assertSessionHasNoErrors();
        $sale = PosSale::firstOrFail();
        $this->assertSame('UNPAID', $sale->status);
        $this->assertSame($booking->guest_name, $sale->customer_name);
        $this->getJson('/admin/bookings/'.$booking->id)->assertJsonPath('open_charges', 180);

        $this->put('/admin/bookings/'.$booking->id.'/status', ['status' => 'CHECKED_OUT'])->assertSessionHasErrors('status');
        $this->assertSame('CHECKED_IN', $booking->fresh()->status);
        $this->post('/admin/bookings/'.$booking->id.'/charges/settle', ['method' => 'gcash', 'reference' => 'GC123'])->assertRedirect();
        $this->assertDatabaseHas('pos_sales', ['id' => $sale->id, 'status' => 'PAID', 'payment_method' => 'gcash']);
        $this->put('/admin/bookings/'.$booking->id.'/status', ['status' => 'CHECKED_OUT'])->assertRedirect();
        $this->assertSame('CHECKED_OUT', $booking->fresh()->status);

        $other = Booking::where('reference', 'EAJ-DEMO-MANUAL')->firstOrFail();
        $this->post('/admin/pos/sales', ['lines' => [['item_id' => $beer->id, 'quantity' => 1]], 'payment_method' => 'room_charge', 'booking_id' => $other->id])->assertSessionHasErrors('booking_id');
    }

    public function test_arriving_guest_cannot_be_charged_to_a_room_before_check_in(): void
    {
        $booking = $this->inHouse('CONFIRMED');
        $beer = PosItem::where('name', 'San Miguel beer')->firstOrFail();
        $this->actingAs($this->user('staff@example.com'))->post('/admin/pos/sales', [
            'lines' => [['item_id' => $beer->id, 'quantity' => 1]],
            'payment_method' => 'room_charge',
            'booking_id' => $booking->id,
        ])->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('pos_sales', 0);
    }

    public function test_pos_items_are_admin_managed_and_tenant_scoped(): void
    {
        $admin = $this->user('admin@example.com');
        $this->actingAs($admin)->post('/admin/settings/pos-items', ['name' => 'Sunscreen', 'category' => 'Shop', 'price' => 250, 'track_stock' => true, 'stock' => 10, 'active' => true])->assertRedirect();
        $item = PosItem::where('name', 'Sunscreen')->firstOrFail();
        $this->put('/admin/settings/pos-items/'.$item->id.'/stock', ['change' => -3])->assertRedirect();
        $this->assertSame(7, $item->fresh()->stock);
        $this->put('/admin/settings/pos-items/'.$item->id.'/stock', ['change' => -8])->assertSessionHasErrors('change');
        $this->assertSame(7, $item->fresh()->stock);
        $this->put('/admin/settings/pos-items/'.$item->id, ['name' => 'Sunscreen SPF50', 'category' => 'Shop', 'price' => 280, 'track_stock' => false, 'stock' => null, 'active' => true])->assertRedirect();
        $this->assertDatabaseHas('pos_items', ['id' => $item->id, 'name' => 'Sunscreen SPF50', 'track_stock' => false, 'stock' => 0]);
        $this->get('/admin/settings/pos-items')->assertInertia(fn (Assert $page) => $page->component('Admin/PosItems')->has('items', 12));

        $this->actingAs($this->user('staff@example.com'))->get('/admin/settings/pos-items')->assertForbidden();
        $this->actingAs($this->user('admin2@example.com'))->delete('/admin/settings/pos-items/'.$item->id)->assertNotFound();
        $this->actingAs($this->user('admin2@example.com'))->post('/admin/pos/sales', ['lines' => [['item_id' => $item->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertSessionHasErrors('lines.0.item_id');
    }

    public function test_pos_items_tab_gives_admins_the_full_catalog_only(): void
    {
        $hidden = PosItem::where('name', 'Bottled water')->firstOrFail();
        $hidden->update(['active' => false]);
        $this->actingAs($this->user('admin@example.com'))->get('/admin/pos?tab=items')->assertInertia(fn (Assert $page) => $page->component('Admin/Pos')
            ->where('tab', 'items')->has('items', 10)->has('catalog', 11)->where('catalog', fn ($catalog) => collect($catalog)->contains('active', false)));
        $this->actingAs($this->user('staff@example.com'))->get('/admin/pos')->assertInertia(fn (Assert $page) => $page->where('catalog', null));
    }

    public function test_editing_a_stocked_item_without_a_count_keeps_sales_made_meanwhile(): void
    {
        $water = PosItem::where('name', 'Bottled water')->firstOrFail();
        $this->actingAs($this->user('staff@example.com'))->post('/admin/pos/sales', ['lines' => [['item_id' => $water->id, 'quantity' => 2]], 'payment_method' => 'cash'])->assertRedirect();
        $this->actingAs($this->user('admin@example.com'))->put('/admin/settings/pos-items/'.$water->id, ['name' => 'Bottled water', 'category' => $water->category, 'price' => 45, 'track_stock' => true, 'active' => false])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pos_items', ['id' => $water->id, 'price' => 45, 'active' => false, 'stock' => $water->stock - 2]);

        $chips = PosItem::where('name', 'Chips')->firstOrFail();
        $chips->update(['track_stock' => false]);
        $this->put('/admin/settings/pos-items/'.$chips->id, ['name' => 'Chips', 'category' => $chips->category, 'price' => 60, 'track_stock' => true, 'active' => true])->assertSessionHasErrors('stock');
    }

    public function test_insights_analytics_finance_and_expenses(): void
    {
        $admin = $this->user('admin@example.com');
        $this->inHouse();
        $today = today()->toDateString();
        $this->actingAs($admin)->get('/admin/insights')->assertInertia(fn (Assert $page) => $page->component('Admin/Insights')->where('range', 'month'));
        $this->get("/admin/insights?range=custom&from={$today}&to={$today}")
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Insights')->where('tab', 'analytics')->has('analytics.units', 6)
                ->where('analytics.metrics.occupiedNights', 1)->where('finance', null));
        $this->get('/admin/reports?range=7d')->assertRedirect('/admin/insights?range=7d');

        $this->post('/admin/insights/expenses', ['spent_on' => today()->toDateString(), 'category' => 'Utilities', 'amount' => 1200.5, 'description' => 'Electric bill'])->assertRedirect();
        $this->post('/admin/insights/expenses', ['spent_on' => today()->addDay()->toDateString(), 'category' => 'Utilities', 'amount' => 10])->assertSessionHasErrors('spent_on');
        $this->get('/admin/insights?tab=expenses&range=7d')
            ->assertInertia(fn (Assert $page) => $page->has('expenses', 1)->where('finance.totals.expenses', 1200.5));
        $this->get('/admin/insights?tab=finance&range=custom&from=2020-01-01&to=2022-01-01')->assertSessionHasErrors('to');

        $expense = Expense::firstOrFail();
        $this->actingAs($this->user('staff@example.com'))->delete('/admin/insights/expenses/'.$expense->id)->assertForbidden();
        $this->actingAs($this->user('admin2@example.com'))->delete('/admin/insights/expenses/'.$expense->id)->assertNotFound();
    }

    public function test_target_and_checklist_settings(): void
    {
        $admin = $this->user('admin@example.com');
        $this->actingAs($admin)->put('/admin/settings/targets', ['monthly_revenue_target' => 150000])->assertRedirect();
        $this->put('/admin/settings/cleaning', ['cleaning_checklist' => [' Make beds ', 'Mop floors']])->assertRedirect();
        $settings = $admin->tenant->fresh()->settings;
        $this->assertSame(150000, $settings['monthly_revenue_target']);
        $this->assertSame(['Make beds', 'Mop floors'], $settings['cleaning_checklist']);
        $this->put('/admin/settings/cleaning', ['cleaning_checklist' => ['A', 'a']])->assertSessionHasErrors('cleaning_checklist.1');
        $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('target', 150000));
    }

    public function test_inertia_refusals_come_back_as_errors_not_error_pages(): void
    {
        $booking = Booking::where('reference', 'EAJ-DEMO-COMPLETED')->firstOrFail();
        $this->actingAs($this->user('admin@example.com'))
            ->withHeaders(['X-Inertia' => 'true'])->from('/admin/bookings')
            ->put('/admin/bookings/'.$booking->id.'/status', ['status' => 'CONFIRMED'])
            ->assertRedirect('/admin/bookings')->assertSessionHasErrors('action');
    }
}
