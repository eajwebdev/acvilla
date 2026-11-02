<?php

use App\Models\Booking;
use App\Models\BookingNotification;
use App\Models\Payment;
use App\Services\BookingNotifier;
use Illuminate\Database\Migrations\Migration;

/**
 * The bell starts with what is still open from before it existed: every website request waiting for the owner
 * and every downpayment receipt waiting to be checked, dated when it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (BookingNotification::exists()) {
            return;
        }
        $notifier = app(BookingNotifier::class);
        Booking::whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])->where('source', 'website')->orderBy('id')->get()
            ->groupBy(fn (Booking $b) => $b->group_code ?? 'b'.$b->id)
            ->each(function ($group) use ($notifier) {
                $notifier->record($group, 'requested');
                BookingNotification::latest('id')->first()?->forceFill(['created_at' => $group->first()->created_at])->save();
            });
        Payment::where('status', 'PENDING')->whereNotNull('proof_path')->with('booking')->orderBy('id')->get()
            ->groupBy(fn (Payment $p) => $p->batch ?? 'p'.$p->id)
            ->each(function ($batch) use ($notifier) {
                $notifier->record($batch->pluck('booking')->filter(), 'receipt_uploaded', null, ['amount' => (float) $batch->sum('amount'), 'method' => $batch->first()->method]);
                BookingNotification::latest('id')->first()?->forceFill(['created_at' => $batch->first()->created_at])->save();
            });
    }

    public function down(): void {}
};
