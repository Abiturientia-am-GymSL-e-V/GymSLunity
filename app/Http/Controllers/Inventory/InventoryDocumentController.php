<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryDocumentRequest;
use App\Inventory\InventoryDocuments;
use App\Models\InventoryDocument;
use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;

class InventoryDocumentController extends Controller
{
    public function store(StoreInventoryDocumentRequest $request, InventoryItem $inventoryItem, InventoryDocuments $documents): RedirectResponse
    {
        $documents->store($inventoryItem, $request->file('document'), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Beleg wurde sicher hinterlegt.']);

        return back();
    }

    public function show(InventoryItem $inventoryItem, InventoryDocument $inventoryDocument, InventoryDocuments $documents): Response
    {
        abort_unless($inventoryDocument->inventory_item_id === $inventoryItem->id, 404);

        return response($documents->read($inventoryDocument), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="beleg-'.$inventoryItem->inventory_number.'-'.$inventoryDocument->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
