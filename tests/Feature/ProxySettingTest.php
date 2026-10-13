<?php

namespace Tests\Feature;

use App\Models\ProxySetting;
use App\Models\ProxyUpstream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_proxy_settings(): void
    {
        $response = $this->get('/proxy');

        $response->assertRedirect('/login');
    }

    public function test_user_can_view_proxy_settings(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/proxy');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('proxy/edit')
            ->has('settings')
            ->has('upstreams')
        );
    }

    public function test_user_can_update_proxy_settings(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/proxy', [
            'composer_enabled' => true,
            'npm_enabled' => false,
            'auth_type' => 'none',
            'metadata_cache_ttl' => 7200,
        ]);

        $response->assertRedirect('/proxy');

        $settings = ProxySetting::first();
        $this->assertTrue($settings->composer_enabled);
        $this->assertFalse($settings->npm_enabled);
        $this->assertEquals(7200, $settings->metadata_cache_ttl);
    }

    public function test_user_can_enable_basic_auth(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/proxy', [
            'composer_enabled' => true,
            'npm_enabled' => false,
            'auth_type' => 'basic',
            'auth_username' => 'composer',
            'auth_password' => 'secret123',
            'metadata_cache_ttl' => 3600,
        ]);

        $response->assertRedirect('/proxy');

        $settings = ProxySetting::first();
        $this->assertEquals('basic', $settings->auth_type->value);
        $this->assertEquals('composer', $settings->auth_username);
    }

    public function test_validation_requires_valid_cache_ttl(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/proxy', [
            'composer_enabled' => true,
            'npm_enabled' => false,
            'auth_type' => 'none',
            'metadata_cache_ttl' => 10,
        ]);

        $response->assertSessionHasErrors('metadata_cache_ttl');
    }

    public function test_validation_requires_basic_auth_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/proxy', [
            'composer_enabled' => true,
            'npm_enabled' => false,
            'auth_type' => 'basic',
            'auth_username' => '',
            'auth_password' => '',
            'metadata_cache_ttl' => 3600,
        ]);

        $response->assertSessionHasErrors(['auth_username', 'auth_password']);
    }

    // Upstream CRUD tests

    public function test_user_can_create_upstream(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/proxy/upstreams', [
            'name' => 'Packagist',
            'upstream_url' => 'https://repo.packagist.org',
            'auth_type' => 'none',
            'sort_order' => 0,
        ]);

        $response->assertRedirect('/proxy');
        $this->assertDatabaseHas('proxy_upstreams', ['name' => 'Packagist']);
    }

    public function test_user_can_edit_upstream(): void
    {
        $user = User::factory()->create();
        $upstream = ProxyUpstream::factory()->create();

        $response = $this->actingAs($user)->get("/proxy/upstreams/{$upstream->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('proxy/upstreams/edit')
            ->has('upstream')
        );
    }

    public function test_user_can_update_upstream(): void
    {
        $user = User::factory()->create();
        $upstream = ProxyUpstream::factory()->create();

        $response = $this->actingAs($user)->put("/proxy/upstreams/{$upstream->id}", [
            'name' => 'Updated Name',
            'upstream_url' => 'https://updated.example.com',
            'auth_type' => 'none',
            'sort_order' => 5,
        ]);

        $response->assertRedirect('/proxy');

        $upstream->refresh();
        $this->assertEquals('Updated Name', $upstream->name);
        $this->assertEquals('https://updated.example.com', $upstream->upstream_url);
        $this->assertEquals(5, $upstream->sort_order);
    }

    public function test_user_can_delete_upstream(): void
    {
        $user = User::factory()->create();
        $upstream = ProxyUpstream::factory()->create();

        $response = $this->actingAs($user)->delete("/proxy/upstreams/{$upstream->id}");

        $response->assertRedirect('/proxy');
        $this->assertDatabaseMissing('proxy_upstreams', ['id' => $upstream->id]);
    }

    public function test_upstream_validation_requires_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/proxy/upstreams', [
            'name' => 'Test',
            'upstream_url' => '',
            'auth_type' => 'none',
        ]);

        $response->assertSessionHasErrors('upstream_url');
    }

    public function test_upstream_validation_requires_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/proxy/upstreams', [
            'name' => '',
            'upstream_url' => 'https://repo.packagist.org',
            'auth_type' => 'none',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_upstream_with_token_auth(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/proxy/upstreams', [
            'name' => 'Private Repo',
            'upstream_url' => 'https://private.example.com',
            'auth_type' => 'token',
            'auth_token' => 'my-secret-token',
            'sort_order' => 1,
        ]);

        $response->assertRedirect('/proxy');

        $upstream = ProxyUpstream::first();
        $this->assertEquals('token', $upstream->auth_type->value);
    }

    public function test_guest_cannot_manage_upstreams(): void
    {
        $response = $this->post('/proxy/upstreams', [
            'name' => 'Test',
            'upstream_url' => 'https://repo.packagist.org',
            'auth_type' => 'none',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_user_can_enable_npm_proxy_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/proxy', [
            'composer_enabled' => false,
            'npm_enabled' => true,
            'auth_type' => 'none',
            'metadata_cache_ttl' => 3600,
        ]);

        $response->assertRedirect('/proxy');

        $settings = ProxySetting::instance();
        $this->assertFalse($settings->composer_enabled);
        $this->assertTrue($settings->npm_enabled);
    }

    public function test_user_can_create_npm_upstream(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/proxy/upstreams', [
            'ecosystem' => 'npm',
            'name' => 'npmjs',
            'upstream_url' => 'https://registry.npmjs.org',
            'auth_type' => 'none',
            'sort_order' => 0,
        ]);

        $response->assertRedirect('/proxy');
        $this->assertDatabaseHas('proxy_upstreams', ['name' => 'npmjs', 'ecosystem' => 'npm']);
    }

    public function test_upstream_ecosystem_must_be_known(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/proxy/upstreams', [
            'ecosystem' => 'cargo',
            'name' => 'crates',
            'upstream_url' => 'https://crates.io',
            'auth_type' => 'none',
        ]);

        $response->assertSessionHasErrors('ecosystem');
    }

    public function test_proxy_settings_page_exposes_upstream_ecosystem(): void
    {
        $user = User::factory()->create();
        ProxyUpstream::factory()->npm()->create(['name' => 'npmjs']);

        $this->actingAs($user)->get('/proxy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('proxy/edit')
                ->where('upstreams.0.ecosystem', 'npm')
                ->has('settings.npm_enabled'));
    }
}
