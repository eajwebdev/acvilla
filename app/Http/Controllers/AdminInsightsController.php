<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Room;
use App\Services\InsightsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminInsightsController extends Controller
{
    public function index(Request $request, InsightsService $insights)
    {
        $data = $request->validate([
            'tab' => ['nullable', 'in:analytics,finance,expenses'],
            'range' => ['nullable', 'in:month,7d,30d,90d,custom'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:range,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:range,custom', 'after_or_equal:from'],
        ]);
        $range = $data['range'] ?? (isset($data['from'], $data['to']) ? 'custom' : 'month');
        [$from, $to] = $this->period($range, $data);
        $tab = $data['tab'] ?? 'analytics';
        $tenantId = $request->user()->tenant_id;

        return Inertia::render('Admin/Insights', [
            'tab' => $tab, 'range' => $range, 'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'analytics' => $tab === 'analytics' ? $insights->analytics($tenantId, $from, $to) : null,
            'finance' => $tab !== 'analytics' ? $insights->finance($tenantId, $from, $to) : null,
            'expenses' => $tab === 'expenses' ? Expense::with('room:id,name')->where('tenant_id', $tenantId)
                ->whereDate('spent_on', '>=', $from->toDateString())->whereDate('spent_on', '<=', $to->toDateString())->orderByDesc('spent_on')->orderByDesc('id')->get() : null,
            'categories' => Expense::CATEGORIES,
            'rooms' => Room::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeExpense(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'category' => ['required', Rule::in(Expense::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'description' => ['nullable', 'string', 'max:200'],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->where('tenant_id', $tenantId)],
        ]);
        $expense = Expense::create(['tenant_id' => $tenantId, 'created_by' => $request->user()->id, ...$data]);
        $this->audit($request, $expense, 'expense.created');

        return back()->with('success', 'Expense recorded.');
    }

    public function destroyExpense(Request $request, Expense $expense)
    {
        abort_unless($expense->tenant_id === $request->user()->tenant_id, 404);
        $expense->delete();
        $this->audit($request, $expense, 'expense.deleted');

        return back()->with('success', 'Expense deleted.');
    }

    private function period(string $range, array $data): array
    {
        $today = CarbonImmutable::today();
        [$from, $to] = match ($range) {
            '7d' => [$today->subDays(6), $today],
            '30d' => [$today->subDays(29), $today],
            '90d' => [$today->subDays(89), $today],
            'custom' => [CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to'])],
            default => [$today->startOfMonth(), $today],
        };
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose a range of up to one year.']);
        }

        return [$from, $to];
    }

    private function audit(Request $request, Expense $expense, string $action): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $expense->tenant_id, 'action' => $action,
            'entity_type' => 'Expense', 'entity_id' => $expense->id,
            'metadata' => ['category' => $expense->category, 'amount' => (float) $expense->amount, 'spent_on' => $expense->spent_on->toDateString()],
            'ip_address' => $request->ip()]);
    }
}
