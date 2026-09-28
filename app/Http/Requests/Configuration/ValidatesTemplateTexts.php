<?php

declare(strict_types=1);

namespace App\Http\Requests\Configuration;

use App\SelfService\FormTemplates;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/** Reject unknown {{verein.*}} placeholders in configurable texts. */
trait ValidatesTemplateTexts
{
    /** @param list<string> $keys */
    private function validateTemplates(Validator $validator, array $keys): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }
        foreach ($keys as $key) {
            try {
                FormTemplates::validate((string) $this->input($key));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $validator->errors()->add($field, $messages[0]);
                }

                return;
            }
        }
    }
}
