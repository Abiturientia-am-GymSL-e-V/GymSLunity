<?php

namespace App\Http\Middleware;

use App\Configuration\Countries;
use App\Configuration\SoftwareModules;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Support\FormOfAddress;
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
        $moduleValues = function (): array {
            static $values;

            return $values ??= SoftwareModules::values();
        };

        return [
            ...parent::share($request),
            'name' => fn () => (ClubSetting::current()->data['short_name'] ?? null) ?: ((ClubSetting::current()->data['name'] ?? null) ?: config('app.name')),
            'clubName' => fn () => (ClubSetting::current()->data['short_name'] ?? null) ?: (ClubSetting::current()->data['name'] ?? null),
            'logoUrl' => fn () => ! empty(ClubSetting::current()->data['logo_path']) ? route('branding.logo', ['v' => ClubSetting::current()->version]) : null,
            'defaultCountry' => fn () => Countries::code(ClubSetting::current()->data['country'] ?? null) ?? 'DE',
            'formOfAddress' => fn () => FormOfAddress::value(),
            'auth' => [
                'user' => $request->user(),
            ],
            'can' => [
                'viewMembers' => $request->user()?->can('viewAny', Member::class) ?? false,
                'createMembers' => $request->user()?->can('create', Member::class) ?? false,
                'manageConfiguration' => $request->user()?->can('manage-configuration') ?? false,
                'viewPayments' => fn () => ($request->user()?->can('view-payments') ?? false) && $moduleValues()['payments'],
                'viewStatistics' => fn () => ($request->user()?->can('view-statistics') ?? false) && $moduleValues()['statistics'],
                'viewFinance' => fn () => ($request->user()?->can('view-finance') ?? false) && $moduleValues()['finance'],
                'viewForms' => fn () => ($request->user()?->can('view-forms') ?? false) && $moduleValues()['forms'],
                'viewDonations' => fn () => ($request->user()?->can('view-donations') ?? false) && $moduleValues()['donations'],
                'viewInventory' => fn () => ($request->user()?->can('view-inventory') ?? false) && $moduleValues()['inventory'],
                'viewCalendar' => fn () => ($request->user()?->can('view-calendar') ?? false) && $moduleValues()['calendar'],
                'viewBookings' => fn () => ($request->user()?->can('view-bookings') ?? false) && $moduleValues()['bookings'],
                'viewCommunication' => fn () => ($request->user()?->can('view-communication') ?? false) && $moduleValues()['communication'],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
