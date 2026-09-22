<?php

namespace App\Http\Controllers\Members;

use App\Configuration\Countries;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

class PostalCodeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('viewAny', Member::class) || Gate::allows('manage-configuration'), 403);
        $data = $request->validate(['country' => ['required', 'string', 'max:255'], 'postal_code' => ['required', 'string', 'max:20']]);
        $country = Countries::code($data['country']);
        if ($country !== 'DE' || ! preg_match('/^\d{5}$/', $data['postal_code'])) {
            return response()->json(['cities' => [], 'supported' => $country === 'DE']);
        }
        $index = File::json(resource_path('data/postal/DE.json'), JSON_THROW_ON_ERROR);

        return response()->json(['cities' => $index[$data['postal_code']] ?? [], 'supported' => true]);
    }
}
