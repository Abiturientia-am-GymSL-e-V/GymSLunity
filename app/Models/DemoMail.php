<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $subject
 * @property string $sender
 * @property array{to: list<string>, cc: list<string>, bcc: list<string>} $recipients
 * @property string|null $html
 * @property string|null $text
 * @property list<array{name: string, mime: string, size: int, content: string|null}> $attachments
 * @property CarbonInterface|null $created_at
 */
class DemoMail extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recipients' => 'array', 'attachments' => 'array'];
    }
}
