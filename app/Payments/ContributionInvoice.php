<?php

declare(strict_types=1);

namespace App\Payments;

use App\Configuration\ClubSettings;
use App\Members\MemberReportWriter;
use App\Models\Contribution;
use App\Support\Clock;
use App\Support\DocumentSequence;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ContributionInvoice
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function number(Contribution $contribution): Contribution
    {
        if ($contribution->invoice_number) {
            return $contribution;
        }

        return DB::transaction(function () use ($contribution): Contribution {
            $locked = Contribution::query()->whereKey($contribution->id)->lockForUpdate()->firstOrFail();
            if ($locked->invoice_number) {
                return $locked;
            }
            $year = Clock::today()->year;
            $next = DocumentSequence::next('invoice_sequences', ['year' => $year]);
            $locked->update([
                'invoice_number' => sprintf('RE-%d-%06d', $year, $next),
                'invoice_created_at' => now(),
            ]);

            return $locked->fresh();
        }, attempts: 3);
    }

    public function html(Contribution $contribution, bool $print = false): string
    {
        $contribution->loadMissing('account.member');
        $settings = $this->clubSettings;
        $club = $settings->data();

        return view('payments.invoice', [
            'contribution' => $contribution,
            'member' => $contribution->account->member,
            'club' => $club,
            'logo' => $settings->logoDataUri(),
            'print' => $print,
            'giroCode' => $this->giroCode($contribution, $club),
        ])->render();
    }

    public function pdf(Contribution $contribution): string
    {
        return MemberReportWriter::pdf($this->html($contribution));
    }

    /** @param Collection<int, Contribution> $contributions */
    public function combinedPdf(Collection $contributions): string
    {
        $contributions->loadMissing('account.member');
        $settings = $this->clubSettings;
        $club = $settings->data();
        $giroCodes = $contributions->mapWithKeys(fn (Contribution $contribution): array => [
            $contribution->id => $this->giroCode($contribution, $club),
        ])->all();

        return MemberReportWriter::pdf(view('payments.invoices', [
            'contributions' => $contributions,
            'club' => $club,
            'logo' => $settings->logoDataUri(),
            'giroCodes' => $giroCodes,
        ])->render());
    }

    /** @param array<string, mixed> $club
     * @return array{amount: string, recipient: string, iban: string, bic: string, purpose: string, image: string}|null
     */
    private function giroCode(Contribution $contribution, array $club): ?array
    {
        if ($contribution->payment_method !== 'Überweisung' || empty($club['iban']) || empty($club['name'])) {
            return null;
        }

        return app(GiroCode::class)->create(
            $contribution->remainingCents() ?: $contribution->amount_cents,
            (string) $club['name'],
            (string) $club['iban'],
            is_string($club['bic'] ?? null) ? $club['bic'] : null,
            $contribution->payment_reference ?? (string) $contribution->invoice_number,
        );
    }
}
