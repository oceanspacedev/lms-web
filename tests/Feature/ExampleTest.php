<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Halaman utama tidak punya isi sendiri: pengunjung diarahkan ke panel admin.
     */
    public function test_the_root_page_redirects_to_the_admin_panel(): void
    {
        $response = $this->get('/');

        $response->assertStatus(302);
        $response->assertRedirect('/admin');
    }
}
