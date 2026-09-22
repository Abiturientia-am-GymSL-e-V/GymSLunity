<?php

namespace App\Http\Middleware;

use App\Configuration\Countries;
use App\Models\ClubSetting;
use App\Models\Member;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => fn () => (ClubSetting::current()->data['short_name'] ?? null) ?: ((ClubSetting::current()->data['name'] ?? null) ?: config('app.name')),
            'clubName' => fn () => (ClubSetting::current()->data['short_name'] ?? null) ?: (ClubSetting::current()->data['name'] ?? null),
            'logoUrl' => fn () => ! empty(ClubSetting::current()->data['logo_path']) ? route('branding.logo', ['v' => ClubSetting::current()->version]) : null,
            'defaultCountry' => fn () => Countries::code(ClubSetting::current()->data['country'] ?? null) ?? 'DE',
            'auth' => [
                'user' => $request->user(),
            ],
            'can' => [
                'viewMembers' => $request->user()?->can('viewAny', Member::class) ?? false,
                'manageConfiguration' => $request->user()?->can('manage-configuration') ?? false,
                'viewPayments' => $request->user()?->can('view-payments') ?? false,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
