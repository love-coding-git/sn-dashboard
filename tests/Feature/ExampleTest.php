<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_opens_the_soma_overview()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('soma.overview'));
    }
}
