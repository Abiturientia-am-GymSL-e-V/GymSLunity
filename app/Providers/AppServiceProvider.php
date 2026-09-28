<?php

declare(strict_types=1);

namespace App\Providers;

use App\Configuration\ClubSettings;
use App\Configuration\MailConfigurator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mailer\Transport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ClubSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        // Scoped instances only reset per queue job; also start every HTTP
        // request (including consecutive requests in one test) fresh.
        Event::listen(RequestHandled::class, fn () => app(ClubSettings::class)->refresh());
        $mailManager = app('mail.manager');
        $mailManager->extend('native', fn (array $config) => Transport::fromDsn('native://default'));
        // Composer package discovery and the release build run before .env is
        // created. Do not require a database connection during that phase.
        if (is_file(base_path('.env')) && Schema::hasTable('mail_settings')) {
            app(MailConfigurator::class)->applyStored();
        }
        Gate::define('manage-configuration', fn (User $user): bool => $user->isAdministrator());
        Gate::define('view-payments', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'bv'])) > 0);
        Gate::define('view-statistics', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'vereinsverwaltung', 'mv', 'auditor', 'bh', 'bv', 'kp'])) > 0);
        Gate::define('view-finance', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'bh', 'kp'])) > 0);
        Gate::define('view-forms', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'vereinsverwaltung', 'mv'])) > 0);
        Gate::define('view-donations', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'bh'])) > 0);
        Gate::define('view-inventory', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'vereinsverwaltung'])) > 0);
        Gate::define('view-calendar', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'vereinsverwaltung', 'mv'])) > 0);
        Gate::define('view-bookings', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'vereinsverwaltung', 'mv'])) > 0);
        Gate::define('view-communication', fn (User $user): bool => $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'vereinsverwaltung', 'mv'])) > 0);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols());
    }
}
