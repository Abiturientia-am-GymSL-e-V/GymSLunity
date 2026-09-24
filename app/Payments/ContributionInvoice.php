<?php

namespace App\Payments;

use App\Members\MemberReportWriter;
use App\Models\ClubSetting;
use App\Models\Contribution;
use Illuminate\Support\Facades\DB;

final class ContributionInvoice
{
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
            $year = now()->year;
            $sequence = DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->first();
            if (! $sequence) {
                DB::table('invoice_sequences')->insert(['year' => $year, 'next_number' => 2]);
                $next = 1;
            } else {
                $next = (int) $sequence->next_number;
                DB::table('invoice_sequences')->where('year', $year)->update(['next_number' => $next + 1]);
            }
            $locked->update([
                'invoice_number' => sprintf('RE-%d-%06d', $year, $next),
                'invoice_created_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function html(Contribution $contribution, bool $print = false): string
    {
        $contribution->loadMissing('account.member');
        $settings = ClubSetting::current();

        return view('payments.invoice', [
            'contribution' => $contribution,
            'member' => $contribution->account->member,
            'club' => $settings->data,
            'logo' => $settings->logoDataUri(),
            'print' => $print,
        ])->render();
    }

    public function pdf(Contribution $contribution): string
    {
        return MemberReportWriter::pdf($this->html($contribution));
    }
}
