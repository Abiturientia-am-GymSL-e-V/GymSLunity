<?php

namespace Tests\Unit\Communication;

use App\Communication\RichTextSanitizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RichTextSanitizerTest extends TestCase
{
    public function test_it_keeps_supported_formatting_and_removes_executable_markup(): void
    {
        $html = (new RichTextSanitizer)->sanitize(
            '<h2 style="color:red;text-align:right" onclick="evil()">Titel</h2><p><strong>Fett</strong> <a href="https://example.org" target="_blank">Link</a></p><iframe src="https://example.org">unsicher</iframe>',
        );

        $this->assertStringContainsString('<h2 style="text-align: right">Titel</h2>', $html);
        $this->assertStringContainsString('<strong>Fett</strong>', $html);
        $this->assertStringContainsString('href="https://example.org"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringNotContainsString('iframe', $html);
        $this->assertStringNotContainsString('unsicher', $html);
    }

    public function test_it_rejects_empty_content_and_oversized_embedded_images(): void
    {
        try {
            (new RichTextSanitizer)->sanitize('<p><br></p>');
            $this->fail('Empty rich text should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('body', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        (new RichTextSanitizer)->sanitize('<img src="data:image/png;base64,'.base64_encode(str_repeat('x', 2_000_001)).'">');
    }
}
