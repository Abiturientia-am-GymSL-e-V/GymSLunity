<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Models\ContributionTransaction;
use App\Payments\TransactionReceipt;
use App\SelfService\Access;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Members download receipts for entries of their own contribution account. */
class TransactionReceiptController extends Controller
{
    public function __invoke(Request $request, int $transaction, TransactionReceipt $receipt): Response
    {
        $member = Access::member($request);
        $entry = ContributionTransaction::query()
            ->whereKey($transaction)
            ->whereHas('account', fn ($query) => $query->where('member_id', $member->id))
            ->firstOrFail();

        return response($receipt->pdf($entry), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipt->filename($entry).'"',
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
