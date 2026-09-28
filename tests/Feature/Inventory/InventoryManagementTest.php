<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Inventory\InventoryOptions;
use App\Models\InventoryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as BladeView;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'vereinsverwaltung'): User
    {
        $user = User::factory()->create(['roles' => [$role]]);
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function itemData(array $overrides = []): array
    {
        return [
            'name' => 'Wettkampf-Trampolin',
            'category' => 'sports_equipment',
            'description' => 'Guter Zustand bei Inventarisierung',
            'manufacturer' => 'Eurotramp',
            'model' => 'Ultimate',
            'serial_number' => 'ET-4711',
            'location' => 'Turnhalle, Geräteraum 1',
            'responsible_person' => 'Abteilung Turnen',
            'acquisition_type' => 'purchase',
            'acquisition_date' => '2025-01-15',
            'acquisition_cost' => '1200,00',
            'document_reference' => 'RE-2025-001',
            'depreciation_method' => 'linear',
            'useful_life_years' => 10,
            ...$overrides,
        ];
    }

    public function test_inventory_items_receive_unique_numbers_and_current_book_values(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $actor = $this->signIn();

        $this->post(route('inventory.store'), $this->itemData())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory'));
        $this->post(route('inventory.store'), $this->itemData([
            'name' => 'Laptop',
            'category' => 'it',
            'serial_number' => 'NB-9',
            'acquisition_cost' => '799,00',
            'depreciation_method' => 'immediate',
            'useful_life_years' => null,
        ]))->assertSessionHasNoErrors();

        $first = InventoryItem::query()->oldest('id')->firstOrFail();
        $second = InventoryItem::query()->latest('id')->firstOrFail();
        $this->assertSame('INV-000001', $first->inventory_number);
        $this->assertSame('INV-000002', $second->inventory_number);
        $this->assertSame(120000, $first->acquisition_cost_cents);
        $this->assertSame(99000, $first->bookValueCents());
        $this->assertSame(12000, $first->annualDepreciationCents());
        $this->assertSame(0, $second->bookValueCents());
        $this->assertSame($actor->name, $first->created_by_name);

        $this->get(route('inventory'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Inventory')
            ->has('items', 2)
            ->where('items.1.inventory_number', 'INV-000001')
            ->where('items.1.book_value_cents', 99000)
            ->where('summary.active_count', 2)
            ->where('summary.retired_count', 0)
            ->where('summary.acquisition_value_cents', 199900)
            ->where('summary.book_value_cents', 99000));
    }

    public function test_inventory_tabs_have_canonical_routes_and_breadcrumbs(): void
    {
        $this->signIn();

        foreach ([
            'inventory' => ['overview', 'Übersicht'],
            'inventory.create' => ['create', 'Inventarisieren'],
        ] as $route => [$tab, $title]) {
            $this->get(route($route))->assertInertia(fn (Assert $page) => $page
                ->component('Inventory')
                ->where('activeTab', $tab)
                ->where('navigationBreadcrumb.title', $title));
        }
    }

    public function test_filtered_inventory_can_be_exported_as_pdf(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $this->signIn();

        $this->post(route('inventory.store'), $this->itemData())->assertSessionHasNoErrors();
        $this->post(route('inventory.store'), $this->itemData([
            'name' => 'Vereins-Laptop',
            'category' => 'it',
            'serial_number' => 'NB-9',
            'location' => 'Geschäftsstelle',
        ]))->assertSessionHasNoErrors();
        $this->post(route('inventory.store'), $this->itemData([
            'name' => 'Altes Tablet',
            'category' => 'it',
            'serial_number' => 'TAB-1',
        ]))->assertSessionHasNoErrors();

        $tablet = InventoryItem::query()->where('serial_number', 'TAB-1')->firstOrFail();
        $this->patch(route('inventory.dispose', $tablet->inventory_number), [
            'status' => 'sold',
            'disposed_at' => '2026-09-20',
            'disposal_proceeds' => '50,00',
        ])->assertSessionHasNoErrors();

        $renderedNumbers = [];
        View::composer('inventory.report', function (BladeView $view) use (&$renderedNumbers): void {
            $renderedNumbers = $view->getData()['items']->pluck('inventory_number')->all();
        });

        $response = $this->get(route('inventory.report', [
            'search' => 'NB-9',
            'category' => 'it',
            'status' => 'active',
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('%PDF-', false);
        $this->assertStringStartsWith(
            'attachment; filename="inventarliste-',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame(['INV-000002'], $renderedNumbers);
    }

    public function test_inventory_report_prints_linear_depreciation_duration_and_annual_amount(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $this->signIn();
        $this->post(route('inventory.store'), $this->itemData())->assertSessionHasNoErrors();

        $html = view('inventory.report', [
            'items' => InventoryItem::query()->get(),
            'club' => ['name' => 'Testverein'],
            'logo' => null,
            'options' => [
                'categories' => InventoryOptions::categories(),
                'depreciationMethods' => InventoryOptions::depreciationMethods(),
                'statuses' => InventoryOptions::statuses(),
            ],
            'printedAt' => now(),
        ])->render();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertIsString($text);
        $this->assertStringContainsString(
            'Lineare Abschreibung 10 Jahre · 120,00 €/Jahr',
            $text,
        );
    }

    public function test_inventory_report_filters_are_validated(): void
    {
        $this->signIn();

        $this->get(route('inventory.report', [
            'search' => str_repeat('a', 101),
            'category' => 'invalid',
            'status' => 'invalid',
        ]))->assertSessionHasErrors(['search', 'category', 'status']);
    }

    public function test_inventory_report_rejects_more_than_five_thousand_rows(): void
    {
        $this->signIn();
        $timestamp = now();
        $rows = [];

        for ($number = 1; $number <= 5001; $number++) {
            $rows[] = [
                'inventory_number' => sprintf('INV-%06d', $number),
                'name' => 'Inventargegenstand '.$number,
                'category' => 'other',
                'location' => 'Lager',
                'acquisition_type' => 'purchase',
                'acquisition_date' => '2026-01-01',
                'acquisition_cost_cents' => 100,
                'depreciation_method' => 'none',
                'status' => 'active',
                'created_by_name' => 'Testperson',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            InventoryItem::query()->insert($chunk);
        }

        $this->get(route('inventory.report'))->assertSessionHasErrors('scope');
    }

    public function test_linear_depreciation_requires_a_useful_life_and_input_is_validated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $this->signIn();

        $this->post(route('inventory.store'), $this->itemData([
            'useful_life_years' => null,
            'acquisition_date' => '2026-10-01',
        ]))->assertSessionHasErrors(['useful_life_years', 'acquisition_date']);

        $this->post(route('inventory.store'), $this->itemData([
            'category' => 'invalid',
            'acquisition_cost' => '-1',
        ]))->assertSessionHasErrors(['category', 'acquisition_cost']);
        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_sale_is_recorded_without_deleting_the_item(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $actor = $this->signIn();
        $this->post(route('inventory.store'), $this->itemData())->assertSessionHasNoErrors();
        $item = InventoryItem::sole();

        $this->patch(route('inventory.dispose', $item->inventory_number), [
            'status' => 'sold',
            'disposed_at' => '2026-09-01',
            'disposal_proceeds' => '250,50',
            'disposal_note' => 'Verkauf laut Vorstandsbeschluss 09/2026',
        ])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('sold', $item->status);
        $this->assertSame(25050, $item->disposal_proceeds_cents);
        $this->assertSame($actor->name, $item->disposed_by_name);
        $this->assertSame(99000, $item->bookValueCents());
        $this->assertDatabaseCount('inventory_items', 1);

        $this->patch(route('inventory.dispose', $item->inventory_number), [
            'status' => 'lost',
            'disposed_at' => '2026-09-02',
            'disposal_note' => 'Nicht auffindbar',
        ])->assertSessionHasErrors('status');

        $this->get(route('inventory'))->assertInertia(fn (Assert $page) => $page
            ->where('items.0.status', 'sold')
            ->where('items.0.disposal_proceeds_cents', 25050)
            ->where('summary.active_count', 0)
            ->where('summary.retired_count', 1)
            ->where('summary.book_value_cents', 0));
    }

    public function test_loss_and_disposal_are_recorded_without_proceeds(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $this->signIn();

        foreach (['lost', 'disposed'] as $index => $status) {
            $this->post(route('inventory.store'), $this->itemData([
                'name' => $status === 'lost' ? 'Verlorener Laptop' : 'Defekter Drucker',
                'serial_number' => 'SER-'.$index,
            ]))->assertSessionHasNoErrors();
            $item = InventoryItem::query()->latest('id')->firstOrFail();

            $this->patch(route('inventory.dispose', $item->inventory_number), [
                'status' => $status,
                'disposed_at' => '2026-09-20',
                'disposal_proceeds' => '999,00',
                'disposal_note' => $status === 'lost' ? 'Nach Inventur nicht auffindbar' : 'Fachgerecht entsorgt',
            ])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('inventory_items', [
            'status' => 'lost',
            'disposal_proceeds_cents' => null,
        ]);
        $this->assertDatabaseHas('inventory_items', [
            'status' => 'disposed',
            'disposal_proceeds_cents' => null,
        ]);
        $this->assertDatabaseCount('inventory_items', 2);
    }

    public function test_disposal_date_cannot_precede_acquisition_and_sale_requires_proceeds(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $this->signIn();
        $this->post(route('inventory.store'), $this->itemData())->assertSessionHasNoErrors();
        $item = InventoryItem::sole();

        $this->patch(route('inventory.dispose', $item->inventory_number), [
            'status' => 'sold',
            'disposed_at' => '2026-01-01',
            'disposal_proceeds' => '',
        ])->assertSessionHasErrors('disposal_proceeds');
        $this->patch(route('inventory.dispose', $item->inventory_number), [
            'status' => 'lost',
            'disposed_at' => '2024-12-31',
            'disposal_note' => '',
        ])->assertSessionHasErrors('disposed_at');
        $this->assertSame('active', $item->fresh()->status);
    }

    public function test_inventory_mutations_use_the_section_permission(): void
    {
        $this->signIn('bh');

        $this->post(route('inventory.store'), $this->itemData())->assertForbidden();
        $this->get(route('inventory.report'))->assertForbidden();
        $this->assertDatabaseCount('inventory_items', 0);
    }
}
