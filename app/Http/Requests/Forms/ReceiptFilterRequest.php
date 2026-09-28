<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Http\Requests\DocumentFilterRequest;

class ReceiptFilterRequest extends DocumentFilterRequest
{
    protected function choices(): array
    {
        return ['status' => ['available', 'exported', 'cancelled']];
    }
}
