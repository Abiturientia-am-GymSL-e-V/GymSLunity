<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Http\Requests\DocumentFilterRequest;

class FinanceInvoiceFilterRequest extends DocumentFilterRequest
{
    protected function choices(): array
    {
        return ['status' => ['open', 'paid', 'cancelled'], 'document_type' => ['invoice', 'cancellation']];
    }
}
