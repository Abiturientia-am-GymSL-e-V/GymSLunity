<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bookings;

use App\Bookings\BookingManager;
use App\Configuration\SoftwareModules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\BookingResourceRequest;
use App\Http\Requests\Bookings\StoreManualBookingRequest;
use App\Http\Requests\Bookings\UpdateBookingRequest;
use App\Models\BookingResource;
use App\Models\InventoryItem;
use App\Models\Member;
use App\Models\ResourceBooking;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(Request $request, BookingManager $manager): Response
    {
        $tab = (string) $request->route('tab', 'calendar');
        abort_unless(in_array($tab, ['calendar', 'resources', 'resource-create', 'requests', 'create'], true), 404);
        $month = (string) $request->query('month', now()->setTimezone(config('app.display_timezone'))->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now()->setTimezone(config('app.display_timezone'))->format('Y-m');
        }
        $focus = CarbonImmutable::createFromFormat('!Y-m', $month, config('app.display_timezone'));
        $start = $focus->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY)->utc();
        $end = $focus->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->addDay()->utc();

        return Inertia::render('Bookings', [
            'activeTab' => $tab,
            'month' => $month,
            'resources' => $this->resources(),
            'inventoryItems' => InventoryItem::query()->where('status', 'active')->orderBy('inventory_number')->get()
                ->map(fn (InventoryItem $item): array => [
                    'id' => $item->id, 'number' => $item->inventory_number, 'name' => $item->name,
                    'description' => $item->description, 'location' => $item->location,
                ]),
            'membershipTypes' => $manager->membershipTypes(),
            'memberFields' => $manager->memberFields(),
            'editingResourceId' => $tab === 'resource-create' ? $request->integer('edit') ?: null : null,
            'members' => Member::query()
                ->whereNotNull('joined_at')->whereNull('deceased_at')
                ->where(fn ($query) => $query->whereNull('left_at')->orWhereDate('left_at', '>=', Clock::today()))
                ->orderBy('last_name')->orderBy('first_name')->get(['id', 'member_number', 'first_name', 'last_name', 'membership_type'])
                ->map(fn (Member $member): array => [
                    'id' => $member->id,
                    'label' => trim($member->last_name.', '.$member->first_name).' · Nr. '.$member->member_number,
                    'membership_type' => $member->membership_type,
                ]),
            'bookings' => $tab === 'calendar' ? $this->bookingQuery()->whereIn('status', ['requested', 'confirmed'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get()->map(fn (ResourceBooking $booking): array => $this->booking($booking)) : [],
            'requests' => $tab === 'requests' ? $this->bookingQuery()->where('status', 'requested')->orderBy('starts_at')->get()->map(fn (ResourceBooking $booking): array => $this->booking($booking)) : [],
        ]);
    }

    public function show(Request $request, BookingResource $resource): Response
    {
        $month = (string) $request->query('month', now()->setTimezone(config('app.display_timezone'))->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now()->setTimezone(config('app.display_timezone'))->format('Y-m');
        }
        $focus = CarbonImmutable::createFromFormat('!Y-m', $month, config('app.display_timezone'));
        $start = $focus->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY)->utc();
        $end = $focus->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->addDay()->utc();
        $ids = $resource->relatedIds();

        return Inertia::render('BookingResource', [
            'resource' => $this->resource($resource),
            'month' => $month,
            'bookings' => $this->bookingQuery()->whereIn('resource_id', $ids)->whereIn('status', ['requested', 'confirmed'])
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
                ->get()->map(fn (ResourceBooking $booking): array => $this->booking($booking)),
        ]);
    }

    public function showBooking(Request $request, ResourceBooking $booking, BookingManager $manager): Response
    {
        $booking->load(['resource:id,name,parent_id', 'member:id,member_number,first_name,last_name']);
        $scope = $request->query('scope') === 'series' && $booking->series_id !== null ? 'series' : 'occurrence';
        $series = $booking->series_id === null ? collect() : $this->bookingQuery()
            ->where('series_id', $booking->series_id)->get()->map(fn (ResourceBooking $item): array => $this->booking($item));

        return Inertia::render('BookingDetail', [
            'booking' => $this->booking($booking),
            'scope' => $scope,
            'series' => $series,
            'resources' => $this->resources(),
            'canCreateInvoice' => SoftwareModules::enabled('finance') && $request->user()->can('view-finance')
                && $booking->member_id === null && $booking->status === 'confirmed'
                && $booking->price_cents > 0 && $booking->finance_invoice_id === null
                && $manager->isChargeDue($booking),
        ]);
    }

    public function storeResource(BookingResourceRequest $request): RedirectResponse
    {
        BookingResource::query()->create($request->resourceAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ressource wurde angelegt.']);

        return back();
    }

    public function updateResource(BookingResourceRequest $request, BookingResource $resource): RedirectResponse
    {
        $resource->update($request->resourceAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ressource wurde gespeichert.']);

        return back();
    }

    public function store(StoreManualBookingRequest $request, BookingManager $manager): RedirectResponse
    {
        $data = $request->validated();
        $resource = BookingResource::query()->whereKey((int) $data['resource_id'])->firstOrFail();
        $member = isset($data['member_id']) ? Member::query()->whereKey((int) $data['member_id'])->firstOrFail() : null;
        $manager->create($resource, $member, $data, $request->user(), true);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Buchung wurde angelegt.']);

        return back();
    }

    public function decide(Request $request, ResourceBooking $booking, BookingManager $manager): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $data['decision'] === 'approve' ? $manager->approve($booking, $request->user()) : $manager->reject($booking, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['decision'] === 'approve' ? 'Buchung wurde bestätigt.' : 'Buchungsanfrage wurde abgelehnt.']);

        return back();
    }

    public function cancel(Request $request, ResourceBooking $booking, BookingManager $manager): RedirectResponse
    {
        $data = $request->validate(['scope' => ['nullable', Rule::in(['occurrence', 'series'])]]);
        $scope = (string) ($data['scope'] ?? 'occurrence');
        $count = $manager->cancelScope($booking, $scope, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => $count > 1 ? "$count Buchungstermine wurden storniert." : 'Der Buchungstermin wurde storniert.']);

        return to_route('bookings.index');
    }

    public function updateBooking(UpdateBookingRequest $request, ResourceBooking $booking, BookingManager $manager): RedirectResponse
    {
        $count = $manager->update($booking, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => $count > 1 ? "$count Buchungstermine wurden geändert." : 'Der Buchungstermin wurde geändert.']);

        return to_route('bookings.booking.show', ['booking' => $booking, 'scope' => $request->validated('scope')]);
    }

    /** @return Builder<ResourceBooking> */
    private function bookingQuery(): Builder
    {
        return ResourceBooking::query()->with(['resource:id,name,parent_id', 'member:id,member_number,first_name,last_name'])->orderBy('starts_at')->orderBy('id');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function resources(): Collection
    {
        return BookingResource::query()->orderBy('name')->get()->map(fn (BookingResource $resource): array => $this->resource($resource));
    }

    /** @return array<string, mixed> */
    private function resource(BookingResource $resource): array
    {
        return [
            'id' => $resource->id, 'parent_id' => $resource->parent_id, 'inventory_item_id' => $resource->inventory_item_id, 'name' => $resource->name,
            'description' => $resource->description, 'location' => $resource->location,
            'allowed_membership_types' => $resource->allowed_membership_types ?? [],
            'auto_approve_membership_types' => $resource->auto_approve_membership_types ?? [],
            'price_mode' => $resource->price_mode, 'price_cents' => $resource->price_cents,
            'access_rules' => $resource->access_rules ?? [],
            'auto_approve_rules' => $resource->auto_approve_rules ?? [],
            'pricing_rules' => $resource->pricing_rules ?? [],
            'is_active' => $resource->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private function booking(ResourceBooking $booking): array
    {
        $timezone = config('app.display_timezone');

        return [
            'id' => $booking->id, 'resource_id' => $booking->resource_id,
            'resource_name' => $booking->resource->name, 'member_id' => $booking->member_id,
            'member_number' => $booking->member?->member_number,
            'requester_name' => $booking->member ? trim($booking->member->first_name.' '.$booking->member->last_name) : $booking->requester_name,
            'title' => $booking->title, 'notes' => $booking->notes,
            'starts_at' => $booking->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'ends_at' => $booking->ends_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'series_id' => $booking->series_id, 'occurrence' => $booking->occurrence,
            'status' => $booking->status, 'price_cents' => $booking->price_cents,
            'finance_invoice_id' => $booking->finance_invoice_id,
            'created_by_name' => $booking->created_by_name, 'decided_by_name' => $booking->decided_by_name,
        ];
    }
}
