<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StoreContributionsRequest;
use App\Payments\CreateContributions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ContributionController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function store(StoreContributionsRequest $request, CreateContributions $creator): RedirectResponse
    {
        $data = $request->contributionRun();
        if (! $this->clubSettings->enabled('contributions_tax_deductible')) {
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
