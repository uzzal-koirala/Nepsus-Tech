<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_shows_the_role_instead_of_the_email(): void
    {
        $admin = User::factory()->create(['email' => 'boss@example.test']);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['data-test="sidebar-role"', 'Admin'], false);

        foreach (['video-editor' => 'Video Editor', 'content-creator' => 'Content Creator'] as $dept => $label) {
            $user = User::factory()->staff()->create();
            $e = Employee::factory()->create(['department' => $dept, 'status' => 'active']);
            Employee::whereKey($e->id)->update(['user_id' => $user->id]);
            $this->actingAs($user)->get(route('my.dashboard'))->assertOk()
                ->assertSeeInOrder(['data-test="sidebar-role"', $label], false);
        }
    }
}
