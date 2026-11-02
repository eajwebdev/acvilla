<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Review;
use Illuminate\Http\Request;

class GuestReviewController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $booking = Booking::whereKey($data['booking_id'])->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($booking->status === 'COMPLETED', 422, 'A review can be submitted after the stay is completed.');
        abort_if($booking->review()->exists(), 422, 'You already reviewed this stay.');

        Review::create(['tenant_id' => $booking->tenant_id, 'booking_id' => $booking->id, 'user_id' => $request->user()->id,
            'property_id' => $booking->property_id, 'room_id' => $booking->room_id,
            'rating' => $data['rating'], 'comment' => $data['comment'] ?? null]);

        return back()->with('success', 'Thank you. Your review will appear after moderation.');
    }
}
