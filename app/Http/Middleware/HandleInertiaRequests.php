<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Configuration\ClubSettings;
use App\Configuration\Countries;
use App\Configuration\SoftwareModules;
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

    public function __construct(private readonly ClubSettings $clubSettings) {}

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
            'name' => fn () => $this->clubSettings->displayName() ?? config('app.name'),
            'clubName' => fn () => $this->clubSettings->displayName(),
            'logoUrl' => fn () => $this->clubSettings->logoUrl(),
            'defaultCountry' => fn () => Countries::code($this->clubSettings->text('country') ?: null) ?? 'DE',
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
                'viewAudit' => fn () => $request->user()?->can('view-audit') ?? false,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
