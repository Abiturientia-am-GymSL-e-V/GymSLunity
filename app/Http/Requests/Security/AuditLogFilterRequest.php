<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use App\Http\Requests\DocumentFilterRequest;
use App\Security\AuditLog;
use Illuminate\Validation\Rule;

class AuditLogFilterRequest extends DocumentFilterRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-audit') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'format' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf'])]];
    }

    /** @return array<string, list<string>> */
    protected function choices(): array
    {
        return ['area' => array_keys(AuditLog::AREAS)];
    }
}
