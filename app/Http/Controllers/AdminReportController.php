<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\DashboardPeriod;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    public function export(Request $request)
    {
        $period = new DashboardPeriod($request);
        $bookings = Booking::with('property', 'room')->where('tenant_id', $request->user()->tenant_id)
            ->whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()])->orderBy('created_at')->get();
        $filename = 'bookings_'.$period->from->toDateString().'_to_'.$period->to->toDateString().'.csv';

        $currency = $request->user()->tenant->setting('currency');

        return response()->streamDownload(function () use ($bookings, $currency) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Booked At', 'Property', 'Room', 'Guest', 'Email', 'Check In', 'Check Out', 'Guests', 'Status', 'Payment Status', 'Source', 'Total '.$currency]);
            foreach ($bookings as $booking) {
                fputcsv($out, array_map(fn ($value) => $this->csvCell((string) $value), [
                    $booking->reference, $booking->created_at->format('Y-m-d H:i:s'), $booking->property?->name,
                    $booking->room?->name, $booking->guest_name, $booking->guest_email,
                    $booking->check_in->toDateString(), $booking->check_out->toDateString(), $booking->guests,
                    $booking->status, $booking->payment_status, Booking::SOURCES[$booking->source] ?? $booking->source, $booking->total,
                ]));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvCell(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
