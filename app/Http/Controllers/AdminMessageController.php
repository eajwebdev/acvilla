<?php

namespace App\Http\Controllers;

use App\Mail\GuestMessage;
use App\Models\AuditLog;
use App\Models\BookingMessage;
use App\Models\MessageTemplate;
use App\Services\GuestMessenger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminMessageController extends Controller
{
    public function index(Request $request, GuestMessenger $messenger)
    {
        $tenant = $request->user()->tenant;

        return Inertia::render('Admin/Messages', [
            'templates' => collect(GuestMessenger::EVENTS)->map(fn ($label, $event) => ['event' => $event, 'label' => $label,
                'defaults' => config("platform.message_defaults.{$event}"), ...$messenger->template($tenant, $event)])->values(),
            'placeholders' => GuestMessenger::placeholders(),
            'sample' => $messenger->sampleValues($tenant),
        ]);
    }

    public function update(Request $request, string $event)
    {
        abort_unless(array_key_exists($event, GuestMessenger::EVENTS), 404);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'subject' => 'required|string|max:150',
            'body' => 'required|string|max:5000',
            'send_days_before' => $event === 'pre_arrival' ? 'required|integer|min:0|max:30' : 'prohibited',
        ]);
        $tenantId = $request->user()->tenant_id;
        MessageTemplate::updateOrCreate(['tenant_id' => $tenantId, 'event' => $event], $data);
        $this->audit($request, 'message_template.updated', $event);

        return back()->with('success', 'Message saved.');
    }

    public function destroy(Request $request, string $event)
    {
        abort_unless(array_key_exists($event, GuestMessenger::EVENTS), 404);
        MessageTemplate::where('tenant_id', $request->user()->tenant_id)->where('event', $event)->delete();
        $this->audit($request, 'message_template.reset', $event);

        return back()->with('success', 'Message reset to the default.');
    }

    /** Send the current draft, filled with sample booking details, to the signed-in admin. */
    public function test(Request $request, GuestMessenger $messenger, string $event)
    {
        abort_unless(array_key_exists($event, GuestMessenger::EVENTS), 404);
        $data = $request->validate(['subject' => 'required|string|max:150', 'body' => 'required|string|max:5000']);
        $tenant = $request->user()->tenant;
        $values = $messenger->sampleValues($tenant);
        try {
            Mail::to($request->user()->email)->send(new GuestMessage($tenant, '[Test] '.$messenger->render($data['subject'], $values), $messenger->render($data['body'], $values)));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['action' => 'The mail server refused the test email. Check the mail settings with your provider.']);
        }

        return back()->with('success', 'Test email sent to '.$request->user()->email.'.');
    }

    /** Resend a guest email that failed, from the booking drawer. */
    public function retry(Request $request, GuestMessenger $messenger, BookingMessage $message)
    {
        abort_unless($message->booking()->withTrashed()->value('tenant_id') === $request->user()->tenant_id, 404);
        abort_unless($message->status === 'FAILED', 422, 'This email is not waiting to be resent.');
        $message = $messenger->retry($message);

        return match ($message->status) {
            'SENT' => back()->with('success', 'Email sent to '.$message->recipient.'.'),
            'SKIPPED' => back()->with('success', 'The booking has moved on, so this email is no longer needed.'),
            default => throw ValidationException::withMessages(['action' => 'Still could not send: '.$message->error]),
        };
    }

    private function audit(Request $request, string $action, string $event): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $request->user()->tenant_id,
            'action' => $action, 'entity_type' => 'Tenant', 'entity_id' => $request->user()->tenant_id,
            'metadata' => ['event' => $event], 'ip_address' => $request->ip()]);
    }
}
