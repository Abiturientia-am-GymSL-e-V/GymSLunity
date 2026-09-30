<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\IndexMembersRequest;
use App\Members\CurrentAssignments;
use App\Members\MemberDirectory;
use App\Members\MemberFields;
use App\Members\WelcomeMails;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class MemberIndexController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function __invoke(IndexMembersRequest $request): Response
    {
        $filters = $request->filters();
        $query = MemberDirectory::query($filters)->select(Member::LIST_FIELDS);
        $customFields = MemberFieldDefinition::query()->where('is_active', true)->where('is_custom', true)->get()->keyBy('key');

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $filters['per_page']));
        $page = max(1, min($request->integer('page', 1), $lastPage));
        $members = $query->paginate($filters['per_page'], ['*'], 'page', $page, $total);
        // JSON may contain hidden or archived fields. Only configured directory
        // fields leave the server in a list response.
        $inactive = MemberFieldDefinition::query()->where('is_active', false)->where('is_custom', false)->pluck('key')->all();
        $temporalKeys = $customFields->filter(fn (MemberFieldDefinition $field): bool => $field->isTemporal())->keys()->all();
        if ($temporalKeys !== []) {
            $members->getCollection()->load('currentAssignments');
        }
        $members->getCollection()->each(function (Member $member) use ($customFields, $inactive, $temporalKeys): void {
            foreach ($inactive as $key) {
                if (in_array($key, Member::LIST_FIELDS, true)) {
                    $member->setAttribute($key, null);
                }
            }
            $member->custom_values = Arr::only($member->custom_values ?? [], $customFields->keys()->all());
            if ($temporalKeys !== []) {
                // Current option values of department, office and honor fields.
                $member->setAttribute('assignments', (object) Arr::only(CurrentAssignments::values($member), $temporalKeys));
                $member->unsetRelation('currentAssignments');
            }
        });
        $members->appends(array_filter($filters, fn ($value) => $value !== ''));

        return Inertia::render('members/Index', [
            'members' => $members,
            'filters' => $filters,
            'totalMembers' => fn () => Member::query()->count(),
            'filterOptions' => fn () => [
                'memberships' => $this->options('membership_type'),
            ],
            'fieldDefinitions' => fn () => MemberFields::directoryFields(),
            'configurationVersion' => fn () => $this->clubSettings->fieldsVersion(),
            'canBulkEdit' => $request->user()?->can('updateAny', Member::class) ?? false,
            'canManageAssignments' => $request->user()?->can('manage-assignments') ?? false,
            'welcomeMailAvailable' => fn () => app(WelcomeMails::class)->available(),
        ]);
    }

    /** @return list<string> */
    private function options(string $column): array
    {
        return array_values(Member::query()->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()->orderBy($column)->pluck($column)
            ->map(fn ($value): string => (string) $value)->all());
    }
}
