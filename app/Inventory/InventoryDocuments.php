<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Models\InventoryDocument;
use App\Models\InventoryItem;
use App\Models\User;
use App\Security\MemberDocumentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;

final class InventoryDocuments
{
    public function __construct(private readonly MemberDocumentStore $pdfs) {}

    public function store(InventoryItem $item, UploadedFile $file, User $actor): InventoryDocument
    {
        $contents = $this->pdfs->uploadedPdf($file, 'document');

        return $item->documents()->create([
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'content_sha256' => hash('sha256', $contents),
            'contents' => Crypt::encryptString($contents),
            'uploaded_by' => $actor->getKey(),
            'uploaded_by_name' => $actor->name,
        ]);
    }

    public function read(InventoryDocument $document): string
    {
        return $this->pdfs->read($document->contents, true, $document->content_sha256);
    }
}
