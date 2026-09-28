<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Inventory\InventoryOptions;
use App\Members\MemberReportWriter;
use App\Models\InventoryItem;
use Illuminate\Http\Response;

class InventorySheetController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function __invoke(InventoryItem $inventoryItem): Response
    {
        $club = $this->clubSettings->data();
        $logo = $this->clubSettings->logoDataUri();
        $options = [
            'categories' => InventoryOptions::categories(),
            'acquisitionTypes' => InventoryOptions::acquisitionTypes(),
            'depreciationMethods' => InventoryOptions::depreciationMethods(),
            'statuses' => InventoryOptions::statuses(),
        ];
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $html = view('inventory.sheet', compact('inventoryItem', 'club', 'logo', 'options', 'printedAt'))->render();

        return response(MemberReportWriter::pdf($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="inventarblatt-'.$inventoryItem->inventory_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
