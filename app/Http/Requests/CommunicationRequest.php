<?php

namespace App\Http\Requests;

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

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'q' => trim($data['q'] ?? ''),
            'status' => $data['status'] ?? 'active',
            'membership' => $data['membership'] ?? '',
            'department_role' => $data['department_role'] ?? '',
            'club_role' => $data['club_role'] ?? '',
            'gender' => $data['gender'] ?? '',
            'payment_method' => $data['payment_method'] ?? '',
            'city' => $data['city'] ?? '',
            'honorary' => $data['honorary'] ?? '',
            'email_status' => $data['email_status'] ?? '',
            'address_status' => $data['address_status'] ?? '',
            'joined_from' => $data['joined_from'] ?? '',
            'joined_to' => $data['joined_to'] ?? '',
            'custom' => array_map(
                fn ($value): string => (string) $value,
                array_filter($data['custom'] ?? [], fn ($value): bool => $value !== null && $value !== ''),
            ),
        ];
    }
}
