<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialMediaManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_media_managers_get_the_employee_pages_and_nothing_more(): void
    {
        $this->assertArrayHasKey('social-media-manager', Employee::choosableDepartments());

        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'social-media-manager', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);

        $this->assertSame('Social Media Manager', $user->roleLabel());
        $this->assertFalse($user->canPlanContent());
        $this->assertFalse($user->isVideoEditor());

        foreach (['my.dashboard', 'my.tasks', 'calendar.index', 'my.attendance', 'my.clients'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('my.dashboard'))->assertSee(['Social Media Manager', 'My tasks', 'Calendar', 'My attendance', 'Clients']);
        foreach (['scripts.index', 'videos.index', 'employees.index', 'finance.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('my.dashboard'));
        }
    }
}
