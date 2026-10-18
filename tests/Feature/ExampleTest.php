<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_opens_the_app(): void
    {
        $this->get(route('home'))->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('login')); // guests sign in first
    }
}
