<?php

namespace App\Providers;

use App\Support\NepaliDate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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

        // Clicks on an admin page are checked again, not only the first visit.
        \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\AdminOnly::class]);

        // Uploads up to 20 MB (Documents page); Livewire's own default stops at 12 MB.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:20480']]);

        // Currency and app name follow the workspace settings.
        config([
            'app.currency' => \App\Models\Setting::get('currency'),
            'app.name' => \App\Models\Setting::get('business_name'),
        ]);

        // `php artisan dev` also runs the scheduler, which syncs the attendance device every 5 minutes.
        \Illuminate\Foundation\DevCommands::artisan('schedule:work', 'scheduler');

        foreach ([Carbon::class, CarbonImmutable::class] as $class) {
            // The weekly holiday, or a day an admin marked on the Calendar.
            $class::macro('isHoliday', fn (): bool => $this->dayOfWeek === \App\Models\Setting::weeklyHoliday() || \App\Models\Holiday::nameOn($this) !== null);
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
