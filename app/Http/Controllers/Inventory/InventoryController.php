<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\DisposeInventoryItemRequest;
use App\Http\Requests\Inventory\InventoryFilterRequest;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Inventory\InventoryDocuments;
use App\Inventory\InventoryOptions;
use App\Inventory\InventorySequence;
use App\Members\MemberReportWriter;
use App\Models\InventoryItem;
use App\Payments\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(
        private readonly ClubSettings $clubSettings,
    ) {}

    public function index(Request $request): Response
    {
        $tab = (string) $request->route('tab', 'overview');
        $tabs = [
            'overview' => ['Übersicht', route('inventory')],
            'create' => ['Inventarisieren', route('inventory.create')],
        ];
        abort_unless(isset($tabs[$tab]), 404);
        $items = InventoryItem::query()->orderByDesc('id')->get();
        $active = $items->where('status', 'active');

        return Inertia::render('Inventory', [
            'activeTab' => $tab,
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            'items' => $items->map(fn (InventoryItem $item): array => $this->row($item))->values(),
            'options' => [
                'categories' => InventoryOptions::categories(),
                'acquisitionTypes' => InventoryOptions::acquisitionTypes(),
                'depreciationMethods' => InventoryOptions::depreciationMethods(),
                'statuses' => InventoryOptions::statuses(),
            ],
            'summary' => [
                'active_count' => $active->count(),
                'retired_count' => $items->count() - $active->count(),
                'acquisition_value_cents' => $active->sum('acquisition_cost_cents'),
                'book_value_cents' => $active->sum(fn (InventoryItem $item): int => $item->bookValueCents()),
            ],
        ]);
    }

    public function store(StoreInventoryItemRequest $request, InventoryDocuments $documents): RedirectResponse
    {
        $data = $request->validated();

        $item = DB::transaction(function () use ($data, $request, $documents): InventoryItem {
            $number = InventorySequence::next();

            $item = InventoryItem::query()->create([
                'inventory_number' => sprintf('INV-%06d', $number),
                'name' => $data['name'],
                'category' => $data['category'],
                'description' => ($data['description'] ?? null) ?: null,
                'manufacturer' => ($data['manufacturer'] ?? null) ?: null,
                'model' => ($data['model'] ?? null) ?: null,
                'serial_number' => ($data['serial_number'] ?? null) ?: null,
                'location' => $data['location'],
                'responsible_person' => ($data['responsible_person'] ?? null) ?: null,
                'acquisition_type' => $data['acquisition_type'],
                'acquisition_date' => $data['acquisition_date'],
                'acquisition_cost_cents' => Money::cents($data['acquisition_cost']),
                'document_reference' => ($data['document_reference'] ?? null) ?: null,
                'depreciation_method' => $data['depreciation_method'],
                'useful_life_years' => $data['depreciation_method'] === 'linear' ? $data['useful_life_years'] : null,
                'created_by' => $request->user()->id,
                'created_by_name' => $request->user()->name,
            ]);

            if ($request->hasFile('document')) {
                $documents->store($item, $request->file('document'), $request->user());
            }

            return $item;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $item->inventory_number.' wurde inventarisiert.']);

        return to_route('inventory');
    }

    public function show(InventoryItem $inventoryItem): Response
    {
        $inventoryItem->load('documents');

        return Inertia::render('inventory/Show', [
            'navigationBreadcrumb' => [
                'title' => $inventoryItem->inventory_number,
                'href' => route('inventory.show', $inventoryItem),
            ],
            'item' => $this->row($inventoryItem),
            'documents' => $inventoryItem->documents->map(fn ($document): array => [
                'id' => $document->id,
                'original_name' => $document->original_name,
                'uploaded_by_name' => $document->uploaded_by_name,
                'created_at' => $document->created_at->toIso8601String(),
                'url' => route('inventory.documents.show', [$inventoryItem, $document]),
            ])->values(),
            'options' => $this->options(),
        ]);
    }

    public function edit(InventoryItem $inventoryItem): Response
    {
        return Inertia::render('inventory/Edit', [
            'navigationBreadcrumb' => [
                'title' => $inventoryItem->inventory_number.' bearbeiten',
                'href' => route('inventory.edit', $inventoryItem),
            ],
            'item' => $this->row($inventoryItem),
        ]);
    }

    public function update(UpdateInventoryItemRequest $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $data = $request->validated();
        $inventoryItem->update([
            'location' => $data['location'],
            'responsible_person' => ($data['responsible_person'] ?? null) ?: null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Standort und Verantwortlichkeit wurden gespeichert.']);

        return to_route('inventory.show', $inventoryItem);
    }

    public function dispose(DisposeInventoryItemRequest $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $inventoryItem, $request): void {
            $lockedItem = InventoryItem::query()->lockForUpdate()->findOrFail($inventoryItem->id);
            if ($lockedItem->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'Für diesen Gegenstand wurde bereits ein Abgang erfasst.']);
            }
            $lockedItem->update([
                'status' => $data['status'],
                'disposed_at' => $data['disposed_at'],
                'disposal_proceeds_cents' => $data['status'] === 'sold' ? Money::cents($data['disposal_proceeds']) : null,
                'disposal_note' => ($data['disposal_note'] ?? null) ?: null,
                'disposed_by' => $request->user()->id,
                'disposed_by_name' => $request->user()->name,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Abgang von '.$inventoryItem->inventory_number.' wurde erfasst.']);

        return to_route('inventory');
    }

    public function report(InventoryFilterRequest $request): HttpResponse
    {
        $filters = $request->validated();

        $query = $this->filteredQuery($filters)->orderByDesc('id');
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages([
                'scope' => 'Der Bericht ist auf 5.000 Inventareinträge begrenzt. Bitte die Filter einschränken.',
            ]);
        }
        $items = $query->get();

        $club = $this->clubSettings->data();
        $logo = $this->clubSettings->logoDataUri();
        $options = [
            'categories' => InventoryOptions::categories(),
            'acquisitionTypes' => InventoryOptions::acquisitionTypes(),
            'depreciationMethods' => InventoryOptions::depreciationMethods(),
            'statuses' => InventoryOptions::statuses(),
        ];
        $printedAt = now()->setTimezone(config('app.display_timezone'));

        $html = view('inventory.report', compact(
            'items',
            'filters',
            'club',
            'logo',
            'options',
            'printedAt',
        ))->render();

        $pdf = MemberReportWriter::pdf($html, true);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="inventarliste-'.now()->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'inventory_number' => $item->inventory_number,
            'name' => $item->name,
            'category' => $item->category,
            'description' => $item->description,
            'manufacturer' => $item->manufacturer,
            'model' => $item->model,
            'serial_number' => $item->serial_number,
            'location' => $item->location,
            'responsible_person' => $item->responsible_person,
            'acquisition_type' => $item->acquisition_type,
            'acquisition_date' => $item->acquisition_date->format('Y-m-d'),
            'acquisition_cost_cents' => $item->acquisition_cost_cents,
            'document_reference' => $item->document_reference,
            'depreciation_method' => $item->depreciation_method,
            'useful_life_years' => $item->useful_life_years,
            'annual_depreciation_cents' => $item->annualDepreciationCents(),
            'book_value_cents' => $item->bookValueCents(),
            'status' => $item->status,
            'disposed_at' => $item->disposed_at?->format('Y-m-d'),
            'disposal_proceeds_cents' => $item->disposal_proceeds_cents,
            'disposal_note' => $item->disposal_note,
            'created_by_name' => $item->created_by_name,
            'disposed_by_name' => $item->disposed_by_name,
        ];
    }

    /** @return array<string, array<string, string>> */
    private function options(): array
    {
        return [
            'categories' => InventoryOptions::categories(),
            'acquisitionTypes' => InventoryOptions::acquisitionTypes(),
            'depreciationMethods' => InventoryOptions::depreciationMethods(),
            'statuses' => InventoryOptions::statuses(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<InventoryItem>
     */
    private function filteredQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return InventoryItem::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function (Builder $query) use ($term): void {
                    foreach ([
                        'inventory_number',
                        'name',
                        'manufacturer',
                        'model',
                        'serial_number',
                        'location',
                        'responsible_person',
                    ] as $column) {
                        $query->orWhere($column, 'like', $term);
                    }
                });
            })
            ->when(
                ! empty($filters['category']),
                fn (Builder $query) => $query->where('category', $filters['category']),
            )
            ->when(
                ! empty($filters['status']),
                fn (Builder $query) => $query->where('status', $filters['status']),
            );
    }
}
