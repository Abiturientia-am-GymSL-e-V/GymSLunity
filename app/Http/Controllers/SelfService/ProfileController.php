<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelfService\UpdateProfileRequest;
use App\Members\MemberFields;
use App\Members\MemberMandates;
use App\Members\MemberValidation;
use App\Models\Member;
use App\SelfService\PortalRules;
use App\SelfService\ProfileChanges;
use App\Support\FormOfAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, ProfileChanges $changes, MemberMandates $mandates): RedirectResponse
    {
        $member = $request->member();
        $values = $request->changes();
        $keys = $request->editableKeys();
        $changed = DB::transaction(function () use ($member, $values, $changes, $keys, $mandates): bool {
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            PortalRules::assertUnchanged($current, (int) $values['lock_version']);
            $updates = Arr::only($values, $keys);
            if ($current->payment_method === 'SEPA-Lastschrift'
                && array_key_exists('payment_method', $updates)
                && $updates['payment_method'] !== 'SEPA-Lastschrift') {
                $mandates->revoke($current, 'Zahlungsart geändert zu '.($updates['payment_method'] ?: 'nicht hinterlegt'));
                $updates = [...$updates, ...$mandates->clearedDetails()];
            }
            MemberValidation::validateDates(array_replace(MemberFields::snapshot($current), $updates));

            return $changes->save($current, $updates);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $changed ? FormOfAddress::choose('Deine Änderungen wurden gespeichert.', 'Ihre Änderungen wurden gespeichert.') : 'Es waren keine Änderungen zu speichern.']);

        return back();
    }
}
