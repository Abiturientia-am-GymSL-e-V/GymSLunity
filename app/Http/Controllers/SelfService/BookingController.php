<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Bookings\BookingManager;
use App\Http\Controllers\Controller;
use App\Models\BookingResource;
use App\Models\Member;
use App\Models\ResourceBooking;
use App\SelfService\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(Request $request, BookingManager $manager): Response
    {
        $member = Access::member($request);
        $this->ensureActive($member);
        $resources = BookingResource::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (BookingResource $resource): bool => $manager->canRequest($resource, $member));
        $bookings = ResourceBooking::query()->with('resource:id,name')->where('member_id', $member->id)
            ->orderByDesc('starts_at')->limit(100)->get();
        $timezone = config('app.display_timezone');

        return Inertia::render('selfservice/Bookings', [
            'member' => ['first_name' => $member->first_name, 'membership_type' => $member->membership_type],
            'minimumDateTime' => now()->setTimezone(config('app.display_timezone'))->format('Y-m-d\TH:i'),
            'resources' => $resources->map(fn (BookingResource $resource): array => [
                'id' => $resource->id, 'name' => $resource->name, 'description' => $resource->description,
                'location' => $resource->location, 'price_mode' => $resource->price_mode,
                'price_cents' => $resource->price_cents,
                'automatic' => in_array($member->membership_type, $resource->auto_approve_membership_types ?? [], true),
            ])->values(),
            'bookings' => $bookings->map(fn (ResourceBooking $booking): array => [
                'id' => $booking->id, 'resource_name' => $booking->resource->name,
                'title' => $booking->title, 'status' => $booking->status,
                'starts_at' => $booking->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'ends_at' => $booking->ends_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'price_cents' => $booking->price_cents, 'series_id' => $booking->series_id,
                'can_cancel' => in_array($booking->status, ['requested', 'confirmed'], true) && $booking->ends_at->isFuture(),
            ]),
        ]);
    }

    public function store(Request $request, BookingManager $manager): RedirectResponse
    {
        $member = Access::member($request);
        $this->ensureActive($member);
        $data = $request->validate([
            'resource_id' => ['required', 'integer', 'exists:booking_resources,id'],
            'title' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:now'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'recurrence' => ['required', Rule::in(['none', 'weekly', 'monthly'])],
            'occurrences' => ['required_if:recurrence,weekly,monthly', 'integer', 'min:1', 'max:52'],
        ]);
        $resource = BookingResource::query()->whereKey((int) $data['resource_id'])->firstOrFail();
        $bookings = $manager->create($resource, $member, $data);
        $confirmed = collect($bookings)->every(fn (ResourceBooking $booking): bool => $booking->status === 'confirmed');
        Inertia::flash('toast', ['type' => 'success', 'message' => $confirmed ? 'Buchung wurde bestätigt.' : 'Buchungsanfrage wurde übermittelt.']);

        return back();
    }

    public function cancel(Request $request, ResourceBooking $booking, BookingManager $manager): RedirectResponse
    {
        $member = Access::member($request);
        abort_unless($booking->member_id === $member->id, 403);
        $manager->cancel($booking);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der einzelne Termin wurde storniert.']);

        return back();
    }

    private function ensureActive(Member $member): void
    {
        abort_unless($member->joined_at !== null && ! $member->joined_at->isFuture()
            && $member->deceased_at === null && ($member->left_at === null || $member->left_at->isFuture()), 403);
    }
}
