<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\FormOfAddress;
use PHPUnit\Framework\TestCase;

class FormOfAddressTest extends TestCase
{
    public function test_it_defaults_to_informal_and_selects_formal_text_explicitly(): void
    {
        $this->assertSame('du', FormOfAddress::value([]));
        $this->assertSame('du', FormOfAddress::value(['form_of_address' => 'invalid']));
        $this->assertSame('Du-Text', FormOfAddress::choose('Du-Text', 'Sie-Text', []));
        $this->assertSame('Sie-Text', FormOfAddress::choose('Du-Text', 'Sie-Text', ['form_of_address' => 'sie']));
    }
}
