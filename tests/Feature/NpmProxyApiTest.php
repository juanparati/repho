<?php

namespace Tests\Feature;

use App\Models\ProxySetting;
use App\Models\ProxyUpstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NpmProxyApiTest extends TestCase
{
    use RefreshDatabase;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/repho-npm-proxy-'.uniqid();
        config(['repho.proxy_cache_path' => $this->cachePath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function upstreamPackument(string $name = 'left-pad', string $tarball = 'https://registry.npmjs.org/left-pad/-/left-pad-1.3.0.tgz'): array
    {
        return [
            'name' => $name,
            'dist-tags' => ['latest' => '1.3.0'],
            'versions' => [
                '1.3.0' => [
                    'name' => $name,
                    'version' => '1.3.0',
                    'dist' => [
                        'tarball' => $tarball,
                        'integrity' => 'sha512-abc',
                    ],
                ],
            ],
        ];
    }

    public function test_proxy_returns_404_when_npm_proxy_disabled(): void
    {
        ProxySetting::factory()->composerEnabled()->create();
        ProxyUpstream::factory()->npm()->create();

        $this->getJson('/proxy/npm/left-pad')->assertNotFound();
        $this->getJson('/proxy/npm/-/ping')->assertNotFound();
    }

    public function test_npm_proxy_can_be_enabled_independently_of_composer_proxy(): void
    {
        ProxySetting::factory()->npmEnabled()->create();
        ProxyUpstream::factory()->npm()->create();

        Http::fake(['registry.npmjs.org/left-pad' => Http::response($this->upstreamPackument())]);

        $this->getJson('/proxy/npm/-/ping')->assertOk();
        $this->getJson('/proxy/npm/left-pad')->assertOk();
        $this->getJson('/proxy/packages.json')->assertNotFound();
    }

    public function test_packument_is_proxied_with_tarball_urls_rewritten(): void
    {
        ProxySetting::factory()->enabled()->create();
        ProxyUpstream::factory()->npm()->create();

        Http::fake(['registry.npmjs.org/left-pad' => Http::response($this->upstreamPackument())]);

        $response = $this->getJson('/proxy/npm/left-pad');

        $response->assertOk()
            ->assertJsonPath('name', 'left-pad')
            ->assertJsonPath('dist-tags.latest', '1.3.0');

        $version = $response->json('versions')['1.3.0'];
        $this->assertSame('sha512-abc', $version['dist']['integrity']);

        $tarball = $version['dist']['tarball'];
        $this->assertStringStartsWith(url('/proxy/npm/-/tarball/'), $tarball);
        $this->assertMatchesRegularExpression('#/proxy/npm/-/tarball/[A-Za-z0-9_-]+/[a-f0-9]{64}$#', $tarball);
    }

    public function test_scoped_packument_is_requested_upstream_with_encoded_slash(): void
    {
        ProxySetting::factory()->enabled()->create();
        ProxyUpstream::factory()->npm()->create();

        Http::fake(['registry.npmjs.org/*' => Http::response($this->upstreamPackument('@types/node', 'https://registry.npmjs.org/@types/node/-/node-1.3.0.tgz'))]);

        $this->getJson('/proxy/npm/@types%2Fnode')->assertOk()->assertJsonPath('name', '@types/node');
        $this->getJson('/proxy/npm/@types/node')->assertOk()->assertJsonPath('name', '@types/node');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://registry.npmjs.org/@types%2Fnode');
    }

    public function test_abbreviated_metadata_is_forwarded_and_cached_separately(): void
    {
        ProxySetting::factory()->enabled()->create();
        $upstream = ProxyUpstream::factory()->npm()->create();

        Http::fake(['registry.npmjs.org/left-pad' => Http::response($this->upstreamPackument())]);

        $this->get('/proxy/npm/left-pad', ['Accept' => 'application/vnd.npm.install-v1+json'])->assertOk();
        $this->getJson('/proxy/npm/left-pad')->assertOk();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Accept', 'application/vnd.npm.install-v1+json'));
        $this->assertFileExists("{$this->cachePath}/npm/metadata/{$upstream->id}/left-pad.abbreviated.json");
        $this->assertFileExists("{$this->cachePath}/npm/metadata/{$upstream->id}/left-pad.json");
    }

    public function test_packument_uses_first_upstream_that_has_it(): void
    {
        ProxySetting::factory()->enabled()->create();
        ProxyUpstream::factory()->npm()->create(['upstream_url' => 'https://private.example.com', 'sort_order' => 0]);
        ProxyUpstream::factory()->npm()->create(['sort_order' => 1]);
        ProxyUpstream::factory()->create(['upstream_url' => 'https://composer.example.com', 'sort_order' => -1]);

        Http::fake([
            'private.example.com/left-pad' => Http::response(null, 404),
            'registry.npmjs.org/left-pad' => Http::response($this->upstreamPackument()),
        ]);

        $this->getJson('/proxy/npm/left-pad')->assertOk()->assertJsonPath('name', 'left-pad');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'composer.example.com'));
    }

    public function test_packument_returns_404_when_no_upstream_has_it(): void
    {
        ProxySetting::factory()->enabled()->create();
        ProxyUpstream::factory()->npm()->create();

        Http::fake(['registry.npmjs.org/*' => Http::response(null, 404)]);

        $this->getJson('/proxy/npm/does-not-exist')->assertNotFound();
    }

    public function test_tarball_is_fetched_and_cached_via_signed_url(): void
    {
        ProxySetting::factory()->enabled()->create();
        ProxyUpstream::factory()->npm()->create();

        Http::fake([
            'registry.npmjs.org/left-pad' => Http::response($this->upstreamPackument()),
            'registry.npmjs.org/left-pad/-/left-pad-1.3.0.tgz' => Http::response('tgz-bytes'),
        ]);

        $tarballUrl = $this->getJson('/proxy/npm/left-pad')->json('versions')['1.3.0']['dist']['tarball'];

        $response = $this->get($tarballUrl);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame('tgz-bytes', $response->streamedContent());
        $this->assertCount(1, File::allFiles("{$this->cachePath}/npm/dists"));
    }

    public function test_tarball_rejects_forged_signature(): void
    {
        ProxySetting::factory()->enabled()->create();
        ProxyUpstream::factory()->npm()->create();

        $encoded = rtrim(strtr(base64_encode('https://evil.example.com/x.tgz'), '+/', '-_'), '=');

        $this->get("/proxy/npm/-/tarball/{$encoded}/".str_repeat('0', 64))->assertNotFound();
    }

    public function test_basic_auth_applies_to_npm_proxy(): void
    {
        ProxySetting::factory()->enabled()->basicAuth('npm', 'pw')->create();
        ProxyUpstream::factory()->npm()->create();

        Http::fake(['registry.npmjs.org/left-pad' => Http::response($this->upstreamPackument())]);

        $this->getJson('/proxy/npm/left-pad')->assertUnauthorized();
        $this->withBasicAuth('npm', 'pw')->getJson('/proxy/npm/left-pad')->assertOk();
    }

    public function test_token_auth_applies_to_npm_proxy(): void
    {
        ProxySetting::factory()->enabled()->tokenAuth('tok')->create();
        ProxyUpstream::factory()->npm()->create();

        Http::fake(['registry.npmjs.org/left-pad' => Http::response($this->upstreamPackument())]);

        $this->getJson('/proxy/npm/left-pad')->assertUnauthorized();
        $this->withToken('tok')->getJson('/proxy/npm/left-pad')->assertOk();
    }

    public function test_composer_proxy_ignores_npm_upstreams(): void
    {
        ProxySetting::factory()->enabled()->create();
        ProxyUpstream::factory()->npm()->create();

        $this->getJson('/proxy/packages.json')->assertStatus(502);
    }
}
