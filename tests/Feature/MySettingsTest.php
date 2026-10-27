<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_edits_their_own_settings_but_not_their_work_details(): void
    {
        Storage::fake('public');
        $user = User::factory()->staff()->create(['name' => 'Sita Rai', 'email' => 'sita@example.com']);
        $sita = Employee::factory()->create(['name' => 'Sita Rai', 'department' => 'content', 'designation' => 'Camera Person', 'salary' => 30000, 'status' => 'active', 'work_start' => '10:00', 'work_end' => '18:00']);
        Employee::whereKey($sita->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);

        $this->get('/settings')->assertRedirect(route('my.settings'));
        $this->get(route('my.settings'))->assertOk()
            ->assertSee(['Sita Rai', 'Camera Person', 'Personal details', 'Emergency contact', 'At work', '10:00 AM – 6:00 PM', 'Password &amp; security', 'Theme'], false)
            ->assertDontSee('30,000');

        // Personal details.
        Livewire::test('pages::my-settings')
            ->set('phone', '9800000000')->set('bloodGroup', 'O+')->set('address', 'Baneshwor')
            ->set('emergencyName', 'Gita Rai')->set('emergencyRelation', 'Mother')->set('emergencyPhone', '9811111111')
            ->set('birthday', today()->subYears(25)->toDateString())
            ->call('savePersonal')->assertHasNoErrors();
        $sita->refresh();
        $this->assertSame(['9800000000', 'O+', 'Baneshwor', 'Gita Rai', 'Mother', '9811111111'], [$sita->phone, $sita->blood_group, $sita->current_address, $sita->emergency_name, $sita->emergency_relation, $sita->emergency_phone]);
        Livewire::test('pages::my-settings')->set('bloodGroup', 'Z')->set('birthday', today()->addDay()->toDateString())->call('savePersonal')->assertHasErrors(['bloodGroup', 'birthday']);

        // Login name and email.
        Livewire::test('pages::my-settings')->set('name', 'Sita K. Rai')->set('email', 'sita.rai@example.com')->call('saveAccount')->assertHasNoErrors();
        $this->assertSame(['Sita K. Rai', 'sita.rai@example.com'], [$user->fresh()->name, $user->fresh()->email]);

        // Photo.
        // A real 1x1 PNG, so the test needs no GD extension.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        Livewire::test('pages::my-settings')->set('photo', UploadedFile::fake()->createWithContent('me.png', $png));
        $this->assertNotNull($path = $sita->fresh()->avatar_path);
        Storage::disk('public')->assertExists($path);
        Livewire::test('pages::my-settings')->call('removePhoto');
        $this->assertNull($sita->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);

        // Work details stay as the admin set them.
        $this->assertSame(['content', 'Camera Person', '10:00'], [$sita->fresh()->department, $sita->fresh()->designation, $sita->fresh()->work_start]);
    }
}
