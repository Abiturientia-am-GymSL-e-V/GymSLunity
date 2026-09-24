<?php

namespace App\Communication;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

final class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
        'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'a', 'img', 'hr',
    ];

    private const REMOVED_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'];

    private const MAX_EMBEDDED_IMAGE_BYTES = 2_000_000;

    public function sanitize(string $html, string $field = 'body'): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="rich-text-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('rich-text-root');
        if (! $root instanceof DOMElement) {
            throw ValidationException::withMessages([$field => 'Der formatierte Inhalt konnte nicht verarbeitet werden.']);
        }

        $embeddedBytes = 0;
        $this->cleanChildren($root, $embeddedBytes, $document);
        $clean = '';
        foreach ($root->childNodes as $child) {
            $clean .= $document->saveHTML($child);
        }
        $plainText = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plainText === '' && ! str_contains($clean, '<img')) {
            throw ValidationException::withMessages([$field => 'Bitte einen Inhalt eingeben.']);
        }
        if ($embeddedBytes > self::MAX_EMBEDDED_IMAGE_BYTES) {
            throw ValidationException::withMessages([$field => 'Eingebettete Bilder dürfen zusammen höchstens 2 MB groß sein.']);
        }

        return $clean;
    }

    private function cleanChildren(DOMNode $parent, int &$embeddedBytes, DOMDocument $document): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($node);
            } elseif ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, self::REMOVED_WITH_CONTENT, true)) {
                    $parent->removeChild($node);
                } elseif (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    $this->cleanChildren($node, $embeddedBytes, $document);
                    while ($node->firstChild !== null) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                } else {
                    $this->cleanElement($node, $tag, $embeddedBytes);
                    $this->cleanChildren($node, $embeddedBytes, $document);
                }
            }
            $node = $next;
        }
    }

    private function cleanElement(DOMElement $element, string $tag, int &$embeddedBytes): void
    {
        $allowedAttributes = match ($tag) {
            'a' => ['href', 'title'],
            'img' => ['src', 'alt', 'title'],
            'p', 'h1', 'h2', 'h3' => ['style'],
            default => [],
        };
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->name), $allowedAttributes, true)) {
                $element->removeAttributeNode($attribute);
            }
        }
        if ($element->hasAttribute('style')) {
            if (preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\s*;?/i', $element->getAttribute('style'), $match)) {
                $element->setAttribute('style', 'text-align: '.strtolower($match[1]));
            } else {
                $element->removeAttribute('style');
            }
        }
        if ($tag === 'a') {
            $href = trim($element->getAttribute('href'));
            if (! preg_match('/^(https?:\/\/|mailto:)/i', $href)) {
                $element->removeAttribute('href');
            } else {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
        if ($tag === 'img') {
            $source = $element->getAttribute('src');
            if (! preg_match('/^data:image\/(png|jpeg|gif|webp);base64,([a-z0-9+\/=\r\n]+)$/i', $source, $match)) {
                $element->parentNode?->removeChild($element);

                return;
            }
            $bytes = base64_decode(preg_replace('/\s+/', '', $match[2]) ?? '', true);
            if (! is_string($bytes)) {
                $element->parentNode?->removeChild($element);

                return;
            }
            $element->setAttribute('src', 'data:image/'.strtolower($match[1]).';base64,'.base64_encode($bytes));
            $embeddedBytes += strlen($bytes);
        }
    }
}
