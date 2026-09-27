<?php

namespace App\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use App\Security\MemberDocumentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CreateMember
{
    public function __construct(private readonly MemberDocumentStore $documentStore) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, UploadedFile|null>  $documents
     */
    public function handle(User $actor, int $memberNumber, array $values, int $configurationVersion, array $documents = []): Member
    {
        return DB::transaction(function () use ($actor, $memberNumber, $values, $configurationVersion, $documents): Member {
            $configuration = ClubSetting::query()->whereKey(1)->sharedLock()->firstOrFail();
            if ($configuration->fields_version !== $configurationVersion) {
                throw ValidationException::withMessages(['form' => 'Die Feldkonfiguration wurde inzwischen geändert. Bitte das Formular neu laden.']);
            }
            abort_unless($actor->fresh()?->can('create', Member::class), 403);

            $values = array_replace(array_fill_keys(MemberFields::writable(), null), $values);
            if (! isset($values['mandate_type']) || trim((string) $values['mandate_type']) === '') {
                $values['mandate_type'] = 'recurring';
            }
            Validator::make($values, MemberValidation::rules(new Member), MemberValidation::messages(), MemberValidation::attributes())->validate();

            $member = new Member(['member_number' => $memberNumber]);
            $custom = [];
            foreach (MemberFieldDefinition::query()->whereIn('key', array_keys($values))->get() as $definition) {
                if (! $definition->is_active) {
                    throw ValidationException::withMessages(['form' => 'Ein Feld wurde inzwischen deaktiviert. Bitte das Formular neu laden.']);
                }
                $value = $values[$definition->key];
                if ($definition->is_custom) {
                    $custom[$definition->key] = $value === null ? null : match ($definition->type) {
                        'boolean' => (bool) $value, 'number' => (int) $value,
                        'decimal' => number_format((float) $value, 2, '.', ''), default => $value,
                    };
                } else {
                    $member->setAttribute($definition->key, $value);
                }
            }
            $member->custom_values = $custom;
            MemberValidation::validateDates(MemberFields::snapshot($member));
            $member->save();
            foreach ($documents as $kind => $document) {
                if ($document instanceof UploadedFile) {
                    $contents = $this->documentStore->uploadedPdf($document, $kind.'_file');
                    $this->documentStore->store($member->getKey(), $kind, $contents, false, $kind === 'sepa' ? [
                        'mandate_reference' => $member->mandate_reference,
                        'mandate_signed_at' => $member->mandate_signed_at?->format('Y-m-d'),
                    ] : []);
                }
            }

            return $member;
        }, attempts: 3);
    }
}
