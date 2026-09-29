<?php

declare(strict_types=1);

namespace App\Demo;

use App\Bookings\BookingManager;
use App\Donations\DonationAudit;
use App\Donations\DonationCertificateGenerator;
use App\Donations\DonationPurposes;
use App\Donations\DonationSequence;
use App\Finance\IssueFinanceInvoice;
use App\Inventory\InventorySequence;
use App\Models\BookingResource;
use App\Models\ClubCalendar;
use App\Models\ClubCalendarEvent;
use App\Models\Donation;
use App\Models\InventoryItem;
use App\Models\Member;
use App\Models\User;
use App\Payments\ContributionLedger;
use App\Payments\CreateContributions;
use App\Receipts\IssueReceipt;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Sample records for the optional modules, created through the same
 * services as in the application, so balances, number ranges and
 * documents are consistent. All dates are relative to today.
 */
final class DemoModules
{
    private CarbonImmutable $today;

    public function __construct(
        private readonly CreateContributions $contributions,
        private readonly ContributionLedger $ledger,
        private readonly IssueFinanceInvoice $invoices,
        private readonly DonationCertificateGenerator $certificates,
        private readonly BookingManager $bookings,
        private readonly IssueReceipt $receipts,
    ) {
        $this->today = Clock::today();
    }

    public function seed(User $admin): void
    {
        $this->seedContributions($admin);
        $this->seedFinanceInvoices($admin);
        $this->seedDonations($admin);
        $beamer = $this->seedInventory($admin);
        $this->seedCalendar($admin);
        $this->seedBookings($admin, $beamer);
        $this->seedReceipts($admin);
    }

    private function seedContributions(User $admin): void
    {
        foreach ([$this->today->year - 1, $this->today->year] as $year) {
            $period = ['period_start' => "$year-01-01", 'period_end' => "$year-12-31", 'due_date' => "$year-03-31", 'honorary' => 'exclude', 'tax_deductible' => true, 'filters' => []];
            $this->contributions->handle($admin, [...$period, 'description' => "Mitgliedsbeitrag $year", 'amount_mode' => 'fixed', 'amount' => '60.00', 'membership_type' => 'Aktiv/ordentliches Mitglied']);
            $this->contributions->handle($admin, [...$period, 'description' => "Förderbeitrag $year", 'amount_mode' => 'member', 'membership_type' => 'Fördermitglied']);
        }

        // The previous year is settled; this year a quarter of the members is still open.
        $members = Member::query()->with('contributionAccount.contributions')->orderBy('member_number')->get();
        foreach ($members as $i => $member) {
            foreach ($member->contributionAccount->contributions as $contribution) {
                $year = $contribution->period_start->year;
                if ($year === $this->today->year && $i % 4 === 3) {
                    continue;
                }
                $bookingDate = $contribution->due_date->subDays(20 - $i % 15);
                if ($bookingDate->greaterThan($this->today)) {
                    $bookingDate = $this->today->subDays(1 + $i % 10);
                }
                $kind = $member->payment_method === 'SEPA-Lastschrift' ? 'sepa_payment' : 'bank_payment';
                $this->ledger->payment($member, $admin, $contribution->amount_cents, $bookingDate->toDateString(), $contribution->description, sprintf('TVM-%d-%04d', $year, $member->member_number), $contribution, $kind);
            }
        }
    }

    private function seedFinanceInvoices(User $admin): void
    {
        $invoices = [
            ['days' => 70, 'paid' => true, 'name' => 'Bäckerei Beispiel GmbH', 'street' => 'Marktplatz 3', 'reference' => 'Bandenwerbung', 'items' => [
                ['description' => 'Bandenwerbung Sportplatz, 12 Monate', 'quantity' => '1', 'unit_code' => 'C62', 'price_mode' => 'net', 'unit_price' => '480.00', 'vat_rate' => 19, 'tax_exemption_reason' => null],
            ]],
            ['days' => 40, 'paid' => false, 'name' => 'SV Nachbarort e. V.', 'street' => 'Sportweg 12', 'reference' => 'Hallennutzung', 'items' => [
                ['description' => 'Nutzung Turnhalle, Dienstag 18–20 Uhr', 'quantity' => '8', 'unit_code' => 'HUR', 'price_mode' => 'net', 'unit_price' => '25.00', 'vat_rate' => 19, 'tax_exemption_reason' => null],
                ['description' => 'Reinigungspauschale', 'quantity' => '1', 'unit_code' => 'C62', 'price_mode' => 'net', 'unit_price' => '40.00', 'vat_rate' => 19, 'tax_exemption_reason' => null],
            ]],
            ['days' => 6, 'paid' => false, 'name' => 'Gemeinde Musterstadt', 'street' => 'Rathausplatz 1', 'reference' => 'Ferienprogramm', 'items' => [
                ['description' => 'Ferienprogramm Kinderturnen, 3 Tage', 'quantity' => '3', 'unit_code' => 'DAY', 'price_mode' => 'gross', 'unit_price' => '150.00', 'vat_rate' => 0, 'tax_exemption_reason' => 'Steuerfreie Leistung nach § 4 Nr. 22 Buchst. b UStG'],
            ]],
        ];
        foreach ($invoices as $invoice) {
            $issued = $this->today->subDays($invoice['days']);
            $created = $this->invoices->handle([
                'creation_key' => (string) Str::uuid(),
                'recipient_name' => $invoice['name'], 'recipient_street' => $invoice['street'],
                'recipient_postal_code' => '12345', 'recipient_city' => 'Musterstadt', 'recipient_country' => 'DE',
                'recipient_email' => 'buchhaltung@example.org', 'buyer_reference' => $invoice['reference'],
                'issue_date' => $issued->toDateString(), 'service_date' => $issued->subDays(3)->toDateString(),
                'due_date' => $issued->addDays(14)->toDateString(), 'currency' => 'EUR', 'payment_method' => 'bank_transfer',
                'notes' => 'Vielen Dank für die Unterstützung unseres Vereins.', 'items' => $invoice['items'],
            ], $admin);
            if ($invoice['paid']) {
                $created->update(['status' => 'paid', 'paid_at' => $issued->addDays(9), 'paid_by' => $admin->id, 'paid_by_name' => $admin->name]);
            }
        }
    }

    private function seedDonations(User $admin): void
    {
        $donations = [
            ['days' => 200, 'name' => 'Anna Albers', 'type' => 'money', 'amount' => '250.00', 'purpose' => '52-21', 'certificate' => true, 'description' => null],
            ['days' => 95, 'name' => 'Autohaus Muster KG', 'type' => 'money', 'amount' => '1000.00', 'purpose' => '52-4', 'certificate' => true, 'description' => null],
            ['days' => 30, 'name' => 'Gustav Hoffmann', 'type' => 'material', 'amount' => '180.00', 'purpose' => '52-21', 'certificate' => false, 'description' => 'Zwei gebrauchte Weichbodenmatten'],
            ['days' => 4, 'name' => 'Frieda Graf', 'type' => 'money', 'amount' => '50.00', 'purpose' => '52-21', 'certificate' => false, 'description' => null],
        ];
        foreach ($donations as $data) {
            $donatedAt = $this->today->subDays($data['days']);
            $donation = Donation::query()->create([
                'receipt_number' => sprintf('SP-%d-%06d', $donatedAt->year, DonationSequence::next('donation', $donatedAt->year)),
                'created_by' => $admin->id, 'created_by_name' => $admin->name,
                'donor_name' => $data['name'], 'donor_street' => 'Lindenallee 7', 'donor_postal_code' => '12345',
                'donor_city' => 'Musterstadt', 'donor_country' => 'DE', 'donor_email' => null,
                'donation_type' => $data['type'], 'amount_cents' => (int) round((float) $data['amount'] * 100),
                'donated_at' => $donatedAt->toDateString(), 'purpose_code' => $data['purpose'],
                'purpose_label' => DonationPurposes::options()[$data['purpose']], 'description' => $data['description'],
                'expense_waiver' => false, 'asset_origin' => $data['type'] === 'material' ? 'private' : null,
                'valuation_document_reference' => null, 'created_at' => $donatedAt,
            ]);
            DonationAudit::record($admin, 'donation_created', ['receipt_number' => $donation->receipt_number, 'amount_cents' => $donation->amount_cents, 'donation_type' => $donation->donation_type, 'donated_at' => $donation->donated_at->format('Y-m-d')], $donation);
            if ($data['certificate']) {
                $this->certificates->issue($donation, $admin, 'digital');
            }
        }
    }

    private function seedInventory(User $admin): InventoryItem
    {
        $items = [
            ['Stufenbarren', 'sports_equipment', 'Turnhalle, Geräteraum', 'purchase', 900, '3200.00', 'linear', 10],
            ['Weichbodenmatte (2 Stück)', 'sports_equipment', 'Turnhalle, Geräteraum', 'donation', 30, '180.00', 'immediate', null],
            ['Beamer', 'it', 'Vereinsheim, Schrank 2', 'purchase', 400, '649.00', 'linear', 3],
            ['Laptop Geschäftsstelle', 'it', 'Geschäftsstelle', 'purchase', 1500, '899.00', 'linear', 3],
            ['Vereinsfahne', 'facility', 'Vereinsheim', 'transfer', 5000, '0.00', 'none', null],
        ];
        $beamer = null;
        foreach ($items as [$name, $category, $location, $acquisition, $days, $cost, $depreciation, $life]) {
            $item = InventoryItem::query()->create([
                'inventory_number' => sprintf('INV-%06d', InventorySequence::next()),
                'name' => $name, 'category' => $category, 'description' => null, 'manufacturer' => null, 'model' => null,
                'serial_number' => null, 'location' => $location, 'responsible_person' => 'Ben Brandt',
                'acquisition_type' => $acquisition, 'acquisition_date' => $this->today->subDays($days)->toDateString(),
                'acquisition_cost_cents' => (int) round((float) $cost * 100), 'document_reference' => null,
                'depreciation_method' => $depreciation, 'useful_life_years' => $life,
                'created_by' => $admin->id, 'created_by_name' => $admin->name,
            ]);
            $beamer = $name === 'Beamer' ? $item : $beamer;
            if ($name === 'Laptop Geschäftsstelle') {
                $item->update(['status' => 'sold', 'disposed_at' => $this->today->subDays(60)->toDateString(), 'disposal_proceeds_cents' => 15000,
                    'disposal_note' => 'An ein Mitglied verkauft.', 'disposed_by' => $admin->id, 'disposed_by_name' => $admin->name]);
            }
        }

        return $beamer ?? throw new \LogicException('Beamer fehlt in den Demodaten.');
    }

    private function seedCalendar(User $admin): void
    {
        $calendar = ClubCalendar::query()->where('type', 'general')->firstOrFail();
        $events = [
            [-12, '19:00', '21:00', 'Vorstandssitzung', 'Vereinsheim'],
            [3, '18:00', '20:00', 'Kinderturnen – Schnuppertraining', 'Turnhalle'],
            [9, '19:30', '21:30', 'Mitgliederversammlung', 'Vereinsheim'],
            [16, null, null, 'Sommerfest', 'Sportplatz'],
            [23, '10:00', '16:00', 'Übungsleiter-Fortbildung', 'Turnhalle'],
            [40, null, null, 'Vereinsausflug', 'Treffpunkt Bahnhof'],
        ];
        foreach ($events as [$offset, $from, $until, $title, $location]) {
            $day = $this->today->addDays($offset)->toDateString();
            // Same representation as CalendarEventRequest: local input in the application time zone.
            $start = CarbonImmutable::parse($day.' '.($from ?? '00:00'), (string) config('app.timezone'));
            $end = $from === null ? $start->addDay() : CarbonImmutable::parse($day.' '.$until, (string) config('app.timezone'));
            ClubCalendarEvent::query()->create([
                'club_calendar_id' => $calendar->id, 'title' => $title, 'location' => $location, 'description' => null,
                'starts_at' => $start, 'ends_at' => $end, 'all_day' => $from === null, 'created_by' => $admin->id,
            ]);
        }
    }

    private function seedBookings(User $admin, InventoryItem $beamer): void
    {
        $noRules = ['parent_id' => null, 'is_active' => true, 'pricing_rules' => [], 'access_rules' => [], 'auto_approve_rules' => [],
            'allowed_membership_types' => [], 'auto_approve_membership_types' => []];
        $clubhouse = BookingResource::query()->create([...$noRules,
            'name' => 'Vereinsheim', 'description' => 'Saal mit Küche für bis zu 40 Personen.', 'location' => 'Am Sportplatz 1',
            'inventory_item_id' => null, 'price_mode' => 'once', 'price_cents' => 3000,
        ]);
        $projector = BookingResource::query()->create([...$noRules,
            'name' => 'Beamer', 'description' => 'Mit HDMI-Kabel und Leinwand.', 'location' => 'Vereinsheim',
            'inventory_item_id' => $beamer->id, 'price_mode' => 'free', 'price_cents' => 0,
        ]);
        $members = Member::query()->whereNull('left_at')->orderBy('member_number')->get();
        $slot = fn (int $days, string $from, string $until): array => [
            'starts_at' => $this->today->addDays($days)->format('Y-m-d').'T'.$from,
            'ends_at' => $this->today->addDays($days)->format('Y-m-d').'T'.$until,
        ];
        $this->bookings->create($clubhouse, $members[2], [...$slot(-20, '14:00', '22:00'), 'title' => 'Geburtstagsfeier', 'recurrence' => 'none'], $admin, true);
        $this->bookings->create($clubhouse, null, [...$slot(5, '09:00', '13:00'), 'title' => 'Erste-Hilfe-Kurs', 'requester_name' => 'DRK Musterstadt', 'recurrence' => 'none'], $admin, true);
        $this->bookings->create($projector, $members[1], [...$slot(2, '18:00', '20:00'), 'title' => 'Trainerbesprechung', 'recurrence' => 'weekly', 'recurrence_interval' => 1, 'occurrences' => 4], $admin, true);
        // An open request from the self-service portal, waiting for a decision.
        $this->bookings->create($clubhouse, $members[0], [...$slot(12, '15:00', '19:00'), 'title' => 'Jugendabend', 'recurrence' => 'none']);
    }

    private function seedReceipts(User $admin): void
    {
        $receipts = [
            [25, '45.00', 'club', 'Ben Brandt, Gartenweg 2, 12345 Musterstadt', 'Auslagenersatz Kreide und Tapes'],
            [8, '120.00', 'other', 'SV Nachbarort e. V., Sportweg 12, 12345 Musterstadt', 'Startgelder Kreismeisterschaft (bar)'],
        ];
        foreach ($receipts as [$days, $amount, $payerSource, $counterpart, $purpose]) {
            $this->receipts->handle([
                'creation_key' => (string) Str::uuid(), 'receipt_number' => null,
                'receipt_date' => $this->today->subDays($days)->toDateString(), 'amount' => $amount, 'currency' => 'EUR',
                'vat_rate' => 0, 'vat_reason' => 'Kein Ausweis der Umsatzsteuer (Auslage bzw. Barkauf)',
                'payer_source' => $payerSource, 'payee_source' => $payerSource === 'club' ? 'other' : 'club',
                'payer' => $payerSource === 'club' ? null : $counterpart, 'payee' => $payerSource === 'club' ? $counterpart : null,
                'payer_email' => null, 'payee_email' => null, 'purpose' => $purpose,
                'signer_name' => 'Emilia Engel', 'signature_method' => 'digital', 'signature_data' => null,
            ], $admin, DemoAccounts::documentIp(null));
        }
    }
}
