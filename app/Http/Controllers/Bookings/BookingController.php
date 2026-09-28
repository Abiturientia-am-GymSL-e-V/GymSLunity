<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bookings;

use App\Bookings\BookingManager;
use App\Http\Controllers\Controller;
use App\Models\BookingResource;
use App\Models\InventoryItem;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Models\ResourceBooking;
use App\Payments\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = (string) $request->route('tab', 'calendar');
        abort_unless(in_array($tab, ['calendar', 'resources', 'requests', 'create'], true), 404);
        $month = (string) $request->query('month', now()->setTimezone(config('app.display_timezone'))->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now()->setTimezone(config('app.display_timezone'))->format('Y-m');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, config('app.display_timezone'))->utc();
        $end = $start->setTimezone(config('app.display_timezone'))->addMonth()->utc();

        return Inertia::render('Bookings', [
            'activeTab' => $tab,
            'month' => $month,
            'resources' => $this->resources(),
            'inventoryItems' => InventoryItem::query()->where('status', 'active')->orderBy('inventory_number')->get()
                ->map(fn (InventoryItem $item): array => [
                    'id' => $item->id, 'number' => $item->inventory_number, 'name' => $item->name,
                    'description' => $item->description, 'location' => $item->location,
                ]),
            'membershipTypes' => $this->membershipTypes(),
            'members' => Member::query()
                ->whereNotNull('joined_at')->whereNull('deceased_at')
                ->where(fn ($query) => $query->whereNull('left_at')->orWhereDate('left_at', '>=', today()))
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
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, config('app.display_timezone'))->utc();
        $end = $start->setTimezone(config('app.display_timezone'))->addMonth()->utc();
        $ids = $this->relatedResourceIds($resource);

        return Inertia::render('BookingResource', [
            'resource' => $this->resource($resource),
            'month' => $month,
            'bookings' => $this->bookingQuery()->whereIn('resource_id', $ids)->whereIn('status', ['requested', 'confirmed'])
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
                ->get()->map(fn (ResourceBooking $booking): array => $this->booking($booking)),
        ]);
    }

    public function storeResource(Request $request): RedirectResponse
    {
        $data = $this->resourceData($request);
        BookingResource::query()->create($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ressource wurde angelegt.']);

        return back();
    }

    public function updateResource(Request $request, BookingResource $resource): RedirectResponse
    {
        $data = $this->resourceData($request, $resource);
        $resource->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ressource wurde gespeichert.']);

        return back();
    }

    public function store(Request $request, BookingManager $manager): RedirectResponse
    {
        $request->merge(['price' => is_string($request->input('price')) ? str_replace(',', '.', trim($request->input('price'))) : $request->input('price')]);
        $data = $request->validate($this->bookingRules(true));
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
        $manager->cancel($booking, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der einzelne Buchungstermin wurde storniert.']);

        return back();
    }

    /** @return array<string, mixed> */
    private function resourceData(Request $request, ?BookingResource $current = null): array
    {
        if (is_string($request->input('price'))) {
            $request->merge(['price' => str_replace(',', '.', trim($request->input('price')))]);
        }
        $membershipTypes = array_keys($this->membershipTypes());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:booking_resources,id'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'allowed_membership_types' => ['present', 'array'],
            'allowed_membership_types.*' => ['string', Rule::in($membershipTypes)],
            'auto_approve_membership_types' => ['present', 'array'],
            'auto_approve_membership_types.*' => ['string', Rule::in($membershipTypes)],
            'price_mode' => ['required', Rule::in(['free', 'once', 'hour', 'day'])],
            'price' => ['required_unless:price_mode,free', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'is_active' => ['required', 'boolean'],
        ]);
        $invalidAuto = array_diff($data['auto_approve_membership_types'], $data['allowed_membership_types']);
        if ($data['allowed_membership_types'] !== [] && $invalidAuto !== []) {
            throw ValidationException::withMessages(['auto_approve_membership_types' => 'Automatische Freigaben sind nur für zugelassene Mitgliedsarten möglich.']);
        }
        if ($current && $data['parent_id']) {
            if (in_array((int) $data['parent_id'], $this->descendantIds($current), true) || (int) $data['parent_id'] === $current->id) {
                throw ValidationException::withMessages(['parent_id' => 'Eine Ressource kann nicht unter sich selbst oder einer eigenen Teilressource eingeordnet werden.']);
            }
        }

        return [
            ...Arr::except($data, ['price']),
            'description' => ($data['description'] ?? null) ?: null,
            'location' => ($data['location'] ?? null) ?: null,
            'price_cents' => $data['price_mode'] === 'free' ? 0 : Money::cents($data['price']),
        ];
    }

    /** @return array<string, mixed> */
    private function bookingRules(bool $manual): array
    {
        return [
            'resource_id' => ['required', 'integer', 'exists:booking_resources,id'],
            'member_id' => [$manual ? 'nullable' : 'required', 'integer', 'exists:members,id'],
            'requester_name' => [$manual ? 'required_without:member_id' : 'nullable', 'nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'recurrence' => ['required', Rule::in(['none', 'weekly', 'monthly'])],
            'occurrences' => ['required_if:recurrence,weekly,monthly', 'integer', 'min:1', 'max:52'],
        ];
    }

    /** @return Builder<ResourceBooking> */
    private function bookingQuery(): Builder
    {
        return ResourceBooking::query()->with(['resource:id,name,parent_id', 'member:id,member_number,first_name,last_name'])->orderBy('starts_at')->orderBy('id');
    }

    /** @return array<string, string> */
    private function membershipTypes(): array
    {
        $field = MemberFieldDefinition::query()->where('key', 'membership_type')->first();
        if (! $field) {
            return Member::query()->distinct()->orderBy('membership_type')->pluck('membership_type', 'membership_type')->filter()->all();
        }

        return collect($field->options)->where('active', true)->pluck('label', 'value')->all();
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
            'created_by_name' => $booking->created_by_name, 'decided_by_name' => $booking->decided_by_name,
        ];
    }

    /** @return list<int> */
    private function relatedResourceIds(BookingResource $resource): array
    {
        $resources = BookingResource::query()->get(['id', 'parent_id']);
        $ids = [$resource->id];
        $parent = $resource->parent_id;
        while ($parent) {
            $ids[] = (int) $parent;
            $parent = $resources->firstWhere('id', $parent)?->parent_id;
        }

        return array_values(array_unique([...$ids, ...$this->descendantIds($resource)]));
    }

    /** @return list<int> */
    private function descendantIds(BookingResource $resource): array
    {
        $resources = BookingResource::query()->get(['id', 'parent_id']);
        $ids = [];
        $frontier = [$resource->id];
        while ($frontier !== []) {
            $children = $resources->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return array_values($ids);
    }
}
