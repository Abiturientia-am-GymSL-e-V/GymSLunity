<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberFieldDefinition;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read models of the overview pages for offices, departments and honors.
 * All periods follow MemberAssignment: whole days, inclusive on both ends.
 * Deactivated fields and options stay visible as long as assignments use them.
 */
final class AssignmentReports
{
    /**
     * Fields of one type: active ones plus deactivated ones with assignments.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(string $type): array
    {
        $used = MemberAssignment::query()->distinct()->pluck('field_key')->all();

        return array_values(MemberFieldDefinition::query()->where('type', $type)
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereIn('key', $used))
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (MemberFieldDefinition $definition): array => [...MemberFields::descriptor($definition), 'readOnly' => ! $definition->is_active])
            ->all());
    }

    /** @return list<string> field types with at least one active field */
    public static function activeTypes(): array
    {
        return array_values(MemberFieldDefinition::query()->where('is_active', true)
            ->whereIn('type', MemberFieldDefinition::TEMPORAL_TYPES)->distinct()->pluck('type')->all());
    }

    /**
     * Office holders per field and option in rank order. With $history all
     * assignments overlapping the period are listed, otherwise those valid on
     * $filters['date']. Mandatory options without holders are vacant.
     *
     * @param  array{field: string, date: string, from: string|null, to: string|null, board: bool, members: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function offices(array $filters, bool $history): array
    {
        $fields = $this->selected('office', $filters['field']);
        $query = $this->assignments(array_column($fields, 'key'), $filters['members']);
        $history ? $query->overlapping($filters['from'], $filters['to']) : $query->activeOn($filters['date']);
        $assignments = $query->orderByRaw('starts_on IS NULL')->orderByDesc('starts_on')->orderByDesc('id')->get()->groupBy(['field_key', 'option_value']);

        $result = [];
        foreach ($fields as $field) {
            $options = [];
            foreach ($field['optionDetails'] as $option) {
                if ($filters['board'] && ! $option['board']) {
                    continue;
                }
                $holders = array_map(fn (MemberAssignment $assignment): array => $this->row($assignment), ($assignments[$field['key']][$option['value']] ?? new Collection)->all());
                if ($holders === [] && ! $option['active']) {
                    continue;
                }
                $options[] = [
                    ...$option, 'holders' => $holders,
                    'vacant' => ! $history && $option['active'] && $option['mandatory'] && $holders === [],
                    'exceeded' => ! $history && $option['maxHolders'] !== null && count($holders) > $option['maxHolders'],
                ];
            }
            $result[] = ['key' => $field['key'], 'label' => $field['label'], 'archived' => $field['readOnly'], 'options' => $options];
        }

        return $result;
    }

    /**
     * Per department option: members at the end of the period, entries and
     * exits within it. With an option, also the members during the period.
     *
     * @param  array{field: string, from: string, to: string, option: string, members: string}  $filters
     * @return array{groups: list<array<string, mixed>>, detail: list<array<string, mixed>>|null}
     */
    public function departments(array $filters): array
    {
        $fields = $this->selected('department', $filters['field']);
        $assignments = $this->assignments(array_column($fields, 'key'), $filters['members'])
            ->overlapping($filters['from'], $filters['to'])->get();
        $inPeriod = fn (?string $day): bool => $day !== null && $day >= $filters['from'] && $day <= $filters['to'];

        $result = [];
        foreach ($fields as $field) {
            $options = [];
            foreach ($field['optionDetails'] as $option) {
                $matching = $assignments->where('field_key', $field['key'])->where('option_value', $option['value']);
                if ($matching->isEmpty() && ! $option['active']) {
                    continue;
                }
                $options[] = [
                    'value' => $option['value'], 'label' => $option['label'], 'active' => $option['active'],
                    'members' => $matching->filter(fn (MemberAssignment $assignment): bool => $assignment->isActiveOn($filters['to']))->unique('member_id')->count(),
                    'joined' => $matching->filter(fn (MemberAssignment $assignment): bool => $inPeriod($assignment->startsOn()))->count(),
                    'left' => $matching->filter(fn (MemberAssignment $assignment): bool => $inPeriod($assignment->endsOn()))->count(),
                ];
            }
            $result[] = ['key' => $field['key'], 'label' => $field['label'], 'archived' => $field['readOnly'], 'options' => $options];
        }

        $detail = null;
        if ($filters['option'] !== '' && count($fields) === 1) {
            $detail = array_values($assignments->where('option_value', $filters['option'])
                ->sortBy(fn (MemberAssignment $assignment): string => mb_strtolower($assignment->member->last_name.' '.$assignment->member->first_name))
                ->map(fn (MemberAssignment $assignment): array => $this->row($assignment))->all());
        }

        return ['groups' => $result, 'detail' => $detail];
    }

    /**
     * Honors, newest first.
     *
     * @param  array{field: string, option: string, year: int|null, members: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function honors(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $fields = $this->selected('honor', $filters['field']);
        $paginator = $this->honorQuery(array_column($fields, 'key'), $filters)->paginate($perPage);
        $rows = array_map(fn (MemberAssignment $assignment): array => $this->honorRow($assignment, $fields), $paginator->items());

        return (new LengthAwarePaginator($rows, $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->getOptions()))->withQueryString();
    }

    /**
     * @param  array{field: string, option: string, year: int|null, members: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function allHonors(array $filters): array
    {
        $fields = $this->selected('honor', $filters['field']);

        return array_map(fn (MemberAssignment $assignment): array => $this->honorRow($assignment, $fields), array_values($this->honorQuery(array_column($fields, 'key'), $filters)->get()->all()));
    }

    /**
     * Years with honors, newest first, for the year filter.
     *
     * @return list<int>
     */
    public function honorYears(): array
    {
        return array_values(MemberAssignment::query()->whereIn('field_key', array_column(self::fields('honor'), 'key'))->whereNotNull('starts_on')
            ->get(['starts_on'])->map(fn (MemberAssignment $assignment): int => (int) $assignment->starts_on?->format('Y'))->unique()->sortDesc()->all());
    }

    /**
     * Current board members as "Name (Amt)", sorted by field and option rank,
     * for the {{verein.vorstand}} placeholder.
     */
    public static function boardText(): string
    {
        $fields = MemberFieldDefinition::query()->where('type', 'office')->where('is_active', true)->orderBy('position')->orderBy('id')->get();
        $board = [];
        foreach ($fields as $field) {
            foreach ($field->options as $option) {
                if ($option['board'] ?? false) {
                    $board[$field->key][$option['value']] = $option['label'];
                }
            }
        }
        if ($board === []) {
            return '';
        }
        $names = [];
        foreach (MemberAssignment::query()->whereIn('field_key', array_keys($board))->activeOn(Clock::today())
            ->with('member:id,first_name,middle_name,last_name')->orderBy('id')->get() as $assignment) {
            $names[$assignment->field_key][$assignment->option_value][] = self::name($assignment->member);
        }
        $lines = [];
        foreach ($board as $key => $options) {
            foreach ($options as $value => $label) {
                foreach ($names[$key][$value] ?? [] as $name) {
                    $lines[] = $name.' ('.$label.')';
                }
            }
        }

        return implode(', ', array_unique($lines));
    }

    public static function name(Member $member): string
    {
        return trim(implode(' ', array_filter([$member->first_name, $member->middle_name, $member->last_name])));
    }

    /**
     * @param  list<string>  $fieldKeys
     * @param  array{option: string, year: int|null, members: string}  $filters
     * @return Builder<MemberAssignment>
     */
    private function honorQuery(array $fieldKeys, array $filters): Builder
    {
        return $this->assignments($fieldKeys, $filters['members'])
            ->when($filters['option'] !== '', fn (Builder $query) => $query->where('option_value', $filters['option']))
            ->when($filters['year'] !== null, fn (Builder $query) => $query->whereBetween('starts_on', [$filters['year'].'-01-01', $filters['year'].'-12-31']))
            ->orderByRaw('starts_on IS NULL')->orderByDesc('starts_on')->orderByDesc('id');
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function honorRow(MemberAssignment $assignment, array $fields): array
    {
        $field = collect($fields)->firstWhere('key', $assignment->field_key) ?? [];

        return [...$this->row($assignment), 'field' => $field['label'] ?? $assignment->field_key, 'label' => $field['options'][$assignment->option_value] ?? $assignment->option_value];
    }

    /** @return list<array<string, mixed>> */
    private function selected(string $type, string $field): array
    {
        $fields = self::fields($type);

        return $field === '' ? $fields : array_values(array_filter($fields, fn (array $item): bool => $item['key'] === $field));
    }

    /**
     * @param  list<string>  $fieldKeys
     * @return Builder<MemberAssignment>
     */
    private function assignments(array $fieldKeys, string $members): Builder
    {
        $today = Clock::todayString();

        return MemberAssignment::query()->whereIn('field_key', $fieldKeys)
            ->with('member:id,member_number,first_name,middle_name,last_name,joined_at,left_at,deceased_at')
            ->when($members === 'current', fn (Builder $query) => $query->whereHas('member', fn (Builder $member) => $member
                ->whereNotNull('joined_at')->whereDate('joined_at', '<=', $today)
                ->where(fn (Builder $left) => $left->whereNull('left_at')->orWhereDate('left_at', '>', $today))
                ->where(fn (Builder $deceased) => $deceased->whereNull('deceased_at')->orWhereDate('deceased_at', '>', $today))));
    }

    /** @return array<string, mixed> */
    private function row(MemberAssignment $assignment): array
    {
        $member = $assignment->member;

        return [
            'id' => $assignment->id, 'member_number' => $member->member_number, 'name' => self::name($member),
            'current_member' => $member->isCurrentMember(), 'option' => $assignment->option_value,
            'starts_on' => $assignment->startsOn(), 'ends_on' => $assignment->endsOn(), 'note' => $assignment->note,
        ];
    }
}
