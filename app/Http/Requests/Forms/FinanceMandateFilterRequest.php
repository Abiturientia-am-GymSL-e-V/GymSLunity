<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Http\Requests\DocumentFilterRequest;

class FinanceMandateFilterRequest extends DocumentFilterRequest
{
    protected function choices(): array
    {
        return ['status' => ['pending', 'signed', 'revoked'], 'mandate_type' => ['recurring', 'one_off']];
    }
}
