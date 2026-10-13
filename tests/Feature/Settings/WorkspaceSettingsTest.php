<?php

namespace Tests\Feature\Settings;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::forget();
    }

    public function test_settings_pages_render(): void
    {
        $this->get(route('settings.business'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get('/settings')->assertRedirect(route('settings.business'));
        foreach (['settings.business', 'settings.preferences', 'settings.work-types', 'profile.edit', 'appearance.edit'] as $route) {
            $this->get(route($route))->assertOk()->assertSee(['Business', 'Preferences', 'Work types']);
        }
    }

    public function test_business_details_and_logo_are_saved(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.business')
            ->assertSet('name', 'Nepsus Tech')
            ->set('name', '')
            ->set('website', 'not a url')
            ->call('save')
            ->assertHasErrors(['name', 'website'])
            ->set('name', 'Nepsus Digital')
            ->set('website', 'https://nepsus.com')
            ->set('pan', '609876543')
            ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Nepsus Digital', Setting::get('business_name'));
        $this->assertSame('609876543', Setting::get('business_pan'));
        Storage::disk('public')->assertExists(Setting::get('business_logo'));
        $this->assertStringContainsString('/storage/branding/', Setting::logoUrl());
    }

    public function test_preferences_drive_currency_reminders_and_holiday(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.preferences')
            ->set('currency', 'XYZ')
            ->call('save')
            ->assertHasErrors(['currency'])
            ->set('currency', 'USD')
            ->set('remindDays', '3')
            ->set('weeklyHoliday', '5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('USD 25,000', Client::money(25000));
        $this->assertSame(3, Payment::remindDays());
        $this->assertTrue(today()->next(\Carbon\CarbonInterface::FRIDAY)->isHoliday());
        $this->assertFalse(today()->next(\Carbon\CarbonInterface::SATURDAY)->isHoliday());

        // Friday is now the day off on the attendance calendar.
        $employee = Employee::factory()->create(['status' => 'active']);
        Livewire::test('pages::employee-profile', ['employee' => $employee])
            ->call('markDay', today()->next(\Carbon\CarbonInterface::FRIDAY)->toDateString(), 'present')
            ->assertStatus(422);
    }

    public function test_work_types_can_be_managed(): void
    {
        $this->actingAs(User::factory()->create());
        $post = WorkType::firstWhere('slug', 'post');

        $page = Livewire::test('pages::settings.work-types')
            ->set('newName', 'Podcast')
            ->call('add')
            ->assertSee('Podcast')
            ->set("names.{$post->id}", 'Feed post')
            ->call('rename', $post->id)
            ->call('recolor', $post->id, 'teal');

        $this->assertSame(['Feed post', 'teal'], [$post->fresh()->name, $post->fresh()->color]);

        $podcast = WorkType::firstWhere('slug', 'podcast');
        $page->call('move', $podcast->id, -1);
        $this->assertLessThan($podcast->getOriginal('position'), $podcast->fresh()->position);

        $page->call('remove', $podcast->id);
        $this->assertNull(WorkType::find($podcast->id));
    }
}
