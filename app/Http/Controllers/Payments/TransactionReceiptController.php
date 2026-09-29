<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\ContributionTransaction;
use App\Payments\TransactionReceipt;
use Illuminate\Http\Response;

class TransactionReceiptController extends Controller
{
    public function __invoke(ContributionTransaction $transaction, TransactionReceipt $receipt): Response
    {
        return response($receipt->pdf($transaction), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipt->filename($transaction).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
