<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use App\Models\MemberFieldDefinition;
use App\Payments\CreateContributions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ContributionController extends Controller
{
    public function store(Request $request, CreateContributions $creator): RedirectResponse
    {
        $filterKeys = MemberFieldDefinition::query()->where('is_active', true)->where('filterable', true)->pluck('key')->all();
        $data = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'amount_mode' => ['required', Rule::in(['fixed', 'member'])],
            'amount' => ['nullable', 'required_if:amount_mode,fixed', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'membership_type' => ['nullable', 'string', 'max:80'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'honorary' => ['required', Rule::in(['include', 'exclude', 'only'])],
            'tax_deductible' => ['required', 'boolean'],
            'filters' => ['nullable', 'array', 'max:20'],
            'filters.*.key' => ['required', 'string', 'distinct', Rule::in($filterKeys)],
            'filters.*.value' => ['required', 'string', 'max:255'],
        ]);
        if (! (bool) (ClubSetting::current()->data['contributions_tax_deductible'] ?? false)) {
            $data['tax_deductible'] = false;
        }
        $result = $creator->handle($request->user(), $data);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['created'].' Beiträge angelegt'.($result['skipped'] ? ', '.$result['skipped'].' übersprungen.' : '.'),
        ]);

        return to_route('payments', $request->only(['from', 'to']));
    }
}
