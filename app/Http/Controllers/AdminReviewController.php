<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Review;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminReviewController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,approved,all']]);
        $status = $filters['status'] ?? 'pending';
        $reviews = Review::with('booking:id,reference,guest_name', 'property:id,name', 'room:id,name')
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($status === 'pending', fn ($query) => $query->where('approved', false))
            ->when($status === 'approved', fn ($query) => $query->where('approved', true))
            ->latest()->paginate(30)->withQueryString();

        return Inertia::render('Admin/Reviews', [
            'reviews' => $reviews,
            'status' => $status,
            'pending' => Review::where('tenant_id', $request->user()->tenant_id)->where('approved', false)->count(),
        ]);
    }

    public function update(Request $request, Review $review)
    {
        $this->owned($request, $review);
        $data = $request->validate(['approved' => ['required', 'boolean']]);
        $review->update($data);
        $this->audit($request, $review, $review->approved ? 'review.approved' : 'review.hidden');

        return back()->with('success', $review->approved ? 'Review published.' : 'Review hidden.');
    }

    public function destroy(Request $request, Review $review)
    {
        $this->owned($request, $review);
        $id = $review->id;
        $review->delete();
        $this->audit($request, $review, 'review.deleted', $id);

        return back()->with('success', 'Review deleted.');
    }

    private function owned(Request $request, Review $review): void
    {
        abort_unless($review->tenant_id === $request->user()->tenant_id, 404);
    }

    private function audit(Request $request, Review $review, string $action, ?int $id = null): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $request->user()->tenant_id,
            'action' => $action, 'entity_type' => 'Review', 'entity_id' => $id ?? $review->id,
            'metadata' => ['booking_id' => $review->booking_id], 'ip_address' => $request->ip()]);
    }
}
