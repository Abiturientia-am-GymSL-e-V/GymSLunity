<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Members\MemberFieldFilter;
use App\Models\MemberFieldDefinition;
use App\Security\SecureUploadInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use RuntimeException;

class CommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-communication') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $custom = MemberFieldDefinition::query()
            ->where('is_active', true)
            ->where('is_custom', true)
            ->where('filterable', true)
            ->get();
        $customKeys = $custom->pluck('key')->all();
        $mail = $this->routeIs('communication.mail.send');
        $letters = $this->routeIs('communication.letters.generate');
        $rules = [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'contacts', 'former', 'future', 'all'])],
            'membership' => ['nullable', 'string', 'max:80'],
            'department_role' => ['nullable', 'string', 'max:100'],
            'club_role' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['m', 'w', 'd', 'o', '__none__'])],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:255'],
            'honorary' => ['nullable', Rule::in(['yes', 'no'])],
            'email_status' => ['nullable', Rule::in(['with', 'without'])],
            'address_status' => ['nullable', Rule::in(['complete', 'incomplete'])],
            'joined_from' => ['nullable', 'date_format:Y-m-d'],
            'joined_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:joined_from'],
            'custom' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail) use ($customKeys): void {
                if (is_array($value) && array_diff(array_keys($value), $customKeys) !== []) {
                    $fail('Ein Zusatzfeld-Filter ist nicht mehr verfügbar.');
                }
            }],
            'subject' => [$mail || $letters ? 'required' : 'nullable', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'body' => [$mail || $letters ? 'required' : 'nullable', 'string', 'max:3000000'],
            'format' => [$letters ? 'required' : 'nullable', Rule::in(['pdf', 'zip'])],
            'confirmed' => [$mail || $letters ? 'accepted' : 'nullable'],
            'attachments' => [$mail ? 'nullable' : 'prohibited', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:2048',
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,text/csv,image/png,image/jpeg,image/webp',
            ],
            'campaign' => ['nullable', 'integer', 'min:1', 'exists:communication_campaigns,id'],
            'campaign_template' => ['nullable', 'integer', 'min:1'],
            'fields' => ['nullable', 'array', 'max:30'],
            'fields.*.key' => ['required', 'string', Rule::in(array_column(MemberFieldFilter::fields(), 'key'))],
            'fields.*.value' => ['nullable', 'string', 'max:255'],
            'fields.*.value_to' => ['nullable', 'string', 'max:255'],
        ];
        foreach ($custom as $field) {
            $rules['custom.'.$field->key] = ['nullable', ...match ($field->type) {
                'boolean' => [Rule::in(['0', '1'])],
                'number' => ['integer', 'between:-2147483648,2147483647'],
                'decimal' => ['numeric', 'between:-99999999.99,99999999.99'],
                'date' => ['date_format:Y-m-d'],
                default => ['string', 'max:255'],
            }];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $files = $this->file('attachments', []);
            $total = array_sum(array_map(fn ($file): int => $file->getSize(), is_array($files) ? $files : []));
            if ($total > 5_000_000) {
                $validator->errors()->add('attachments', 'Die Anhänge dürfen zusammen höchstens 5 MB groß sein.');
            }
            foreach (is_array($files) ? $files : [] as $index => $file) {
                try {
                    app(SecureUploadInspector::class)->inspectAttachment($file);
                } catch (ValidationException|RuntimeException $exception) {
                    $message = $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : $exception->getMessage();
                    $validator->errors()->add('attachments.'.$index, (string) $message);
                }
            }
        }];
    }

    /**
     * Recipient filters: status, search, contact data quality and a list of
     * field filters. The older single parameters (membership, city, custom,
     * …) are converted into field filters, so saved campaigns keep working.
     *
     * @return array{q: string, status: string, email_status: string, address_status: string, fields: list<array{key: string, value: string, value_to: string}>}
     */
    public function filters(): array
    {
        return self::normalizeFilters($this->validated());
    }

    /**
     * Also used for the stored filters of an earlier campaign; keys of
     * fields that no longer exist are dropped.
     *
     * @param  array<string, mixed>  $data
     * @return array{q: string, status: string, email_status: string, address_status: string, fields: list<array{key: string, value: string, value_to: string}>}
     */
    public static function normalizeFilters(array $data): array
    {
        $text = fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $fields = [];
        $add = function (string $key, string $value, string $to = '') use (&$fields): void {
            if ($value !== '' || $to !== '') {
                $fields[] = ['key' => $key, 'value' => $value, 'value_to' => $to];
            }
        };
        $add('membership_type', $text($data['membership'] ?? ''));
        foreach (['department_role', 'club_role', 'gender', 'payment_method', 'city'] as $key) {
            $add($key, $text($data[$key] ?? ''));
        }
        $add('is_honorary', match ($data['honorary'] ?? '') {
            'yes' => '1', 'no' => '0', default => '',
        });
        $add('joined_at', $text($data['joined_from'] ?? ''), $text($data['joined_to'] ?? ''));
        foreach (is_array($data['custom'] ?? null) ? $data['custom'] : [] as $key => $value) {
            // Exact values of number and date fields become a one-value range.
            $add((string) $key, $text($value), $text($value));
        }
        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
            if (is_array($field)) {
                $add($text($field['key'] ?? ''), $text($field['value'] ?? ''), $text($field['value_to'] ?? ''));
            }
        }
        $known = collect(MemberFieldFilter::fields())->keyBy('key');
        $fields = array_values(array_map(function (array $field) use ($known): array {
            // A one-value range only makes sense for dates and numbers.
            if (! in_array($known[$field['key']]['type'] ?? '', ['date', 'number', 'decimal'], true)) {
                $field['value_to'] = '';
            }

            return $field;
        }, array_filter($fields, fn (array $field): bool => $known->has($field['key']))));

        $choose = fn (mixed $value, array $allowed, string $default): string => in_array($value, $allowed, true) ? $value : $default;

        return [
            'q' => mb_substr($text($data['q'] ?? ''), 0, 120),
            'status' => $choose($data['status'] ?? null, ['active', 'contacts', 'former', 'future', 'all'], 'active'),
            'email_status' => $choose($data['email_status'] ?? null, ['with', 'without'], ''),
            'address_status' => $choose($data['address_status'] ?? null, ['complete', 'incomplete'], ''),
            'fields' => array_slice($fields, 0, 30),
        ];
    }
}
