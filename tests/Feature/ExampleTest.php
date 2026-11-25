<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_is_the_cross_subnet_overview()
    {
        $this->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page->component('hub')->has('soma')->has('sn34'));
    }
}
