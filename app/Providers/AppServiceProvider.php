<?php

namespace App\Providers;

use App\Models\User;
use App\Support\NepaliDate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Currency and app name follow the workspace settings.
        config([
            'app.currency' => \App\Models\Setting::get('currency'),
            'app.name' => \App\Models\Setting::get('business_name'),
        ]);

        // CCTV: admins manage cameras and read the logs; watching needs admin or the CCTV permission.
        Gate::define('manage-cctv', fn (User $user) => $user->is_admin);
        Gate::define('view-cctv', fn (User $user) => $user->is_admin || $user->can_view_cctv);

        // `php artisan dev` also runs the scheduler, which syncs the attendance device every 5 minutes.
        \Illuminate\Foundation\DevCommands::artisan('schedule:work', 'scheduler');

        foreach ([Carbon::class, CarbonImmutable::class] as $class) {
            $class::macro('isHoliday', fn (): bool => $this->dayOfWeek === \App\Models\Setting::weeklyHoliday());
            $class::macro('toNepali', fn (bool $withYear = true): string => NepaliDate::format($this, $withYear));
            $class::macro('toNepaliMonth', fn (): string => NepaliDate::monthYear($this));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
