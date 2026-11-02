<?php

namespace App\Http\Controllers;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\BookingNotification;
use App\Models\CleaningTask;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\User;
use App\Services\BookingNotifier;
use Illuminate\Http\Request;

/** The notification bell at the top of the admin: every booking step, newest first, with an unread count per user. */
class AdminNotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $seen = $user->notifications_seen_id;
        $items = BookingNotification::where('tenant_id', $user->tenant_id)->latest('created_at')->latest('id')->limit(60)->get()
            ->map(fn (BookingNotification $n) => [...$n->only('id', 'booking_id', 'event', 'title', 'detail', 'actor'),
                'created_at' => $n->created_at->toIso8601String(), 'unread' => $seen === null ? $n->created_at->greaterThan(now()->subDays(30)) : $n->id > $seen,
                'needs_action' => in_array($n->event, BookingNotifier::GUEST_EVENTS, true)]);

        return response()->json(['items' => $items, 'unread' => self::unreadCount($user)]);
    }

    public function count(Request $request)
    {
        return response()->json(['unread' => self::unreadCount($request->user())]);
    }

    /**
     * What the open admin page polls every few seconds: a fingerprint of the business's bookings, payments,
     * cleaning, blocks and room charges, plus the bell count. The page reloads its data only when the fingerprint changes.
     */
    public function live(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant_id;
        $parts = collect([Booking::withTrashed(), Payment::query(), CleaningTask::query(), BlockedDate::query(), PosSale::query()])
            ->map(fn ($query) => $query->where('tenant_id', $tenant)->selectRaw('count(*) as total, max(updated_at) as changed')->first()?->only('total', 'changed'));

        return response()->json(['stamp' => md5(json_encode([...$parts->all(), BookingNotification::where('tenant_id', $tenant)->max('id')])),
            'unread' => self::unreadCount($user)]);
    }

    /** Opening the bell marks everything shown as read for this user (others keep their own count). */
    public function seen(Request $request)
    {
        $user = $request->user();
        // Remember the newest notification the user was shown, not a time: anything newer stays unread, even within the same second.
        $data = $request->validate(['up_to' => 'nullable|integer|min:1']);
        $latest = (int) BookingNotification::where('tenant_id', $user->tenant_id)->max('id');
        $upTo = isset($data['up_to']) ? min((int) $data['up_to'], $latest) : $latest;
        $user->forceFill(['notifications_seen_id' => max((int) $user->notifications_seen_id, $upTo)])->save();

        return response()->json(['unread' => self::unreadCount($user->fresh())]);
    }

    public static function unreadCount(User $user): int
    {
        return BookingNotification::where('tenant_id', $user->tenant_id)
            ->when($user->notifications_seen_id !== null, fn ($q) => $q->where('id', '>', $user->notifications_seen_id))
            ->when($user->notifications_seen_id === null, fn ($q) => $q->where('created_at', '>', now()->subDays(30)))
            ->count();
    }
}
