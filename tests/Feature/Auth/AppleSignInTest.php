<?php

namespace Tests\Feature\Auth;

use App\AuthProviders\AppleAuthProvider;
use App\Models\AuthProvider;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AppleSignInTest extends TestCase
{
  use RefreshDatabase;

  private string $pem = '';

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed(SettingsSeeder::class);

    // A throwaway P-256 key, like the .p8 Apple issues
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $this->pem);
  }

  private function appleProvider(): AuthProvider
  {
    return AuthProvider::create([
      'name' => 'Apple',
      'provider_class' => 'Apple',
      'uuid' => '11111111-2222-3333-4444-555555555555',
      'enabled' => true,
      'allow_registration' => false,
      // pasted into a one-line field, so the line breaks are gone
      'provider_config' => [
        'client_id' => 'cloud.example.signin',
        'team_id' => 'ABCDE12345',
        'key_id' => 'KEY1234567',
        'private_key' => str_replace("\n", ' ', $this->pem),
      ],
    ]);
  }

  public function test_apple_is_offered_as_a_provider_type(): void
  {
    $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('secret-password'), 'admin' => true, 'active' => true]);

    $apple = collect($this->actingAs($admin)->getJson('/api/auth-providers/available-types')->assertOk()->json('data.providers'))
      ->firstWhere('class', 'Apple');

    $this->assertNotNull($apple);
    $this->assertSame(['client_id', 'team_id', 'key_id', 'private_key'], array_keys($apple['provider_config']));
  }

  public function test_login_sends_people_to_apple_with_a_form_post_callback(): void
  {
    $provider = $this->appleProvider();

    $location = $this->get("/auth/provider/{$provider->id}/login")->assertRedirect()->headers->get('Location');

    $this->assertStringStartsWith('https://appleid.apple.com/auth/authorize?', $location);
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    $this->assertSame('cloud.example.signin', $query['client_id']);
    $this->assertSame('form_post', $query['response_mode']);
    $this->assertSame('name email', $query['scope']);
    $this->assertStringEndsWith("/auth/provider/{$provider->uuid}/callback", $query['redirect_uri']);
    $this->assertNotEmpty($query['state']);
  }

  public function test_the_client_secret_is_a_jwt_signed_with_the_teams_key(): void
  {
    $client = (new \ReflectionMethod(AppleAuthProvider::class, 'createClient'))->invoke(new AppleAuthProvider($this->appleProvider()));
    $secret = (new \ReflectionMethod($client, 'getClientSecret'))->invoke($client);

    [$header, $claims] = array_map(
      fn($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true),
      array_slice(explode('.', $secret), 0, 2)
    );
    $this->assertSame('ES256', $header['alg']);
    $this->assertSame('KEY1234567', $header['kid']);
    $this->assertSame('ABCDE12345', $claims['iss']);
    $this->assertSame('cloud.example.signin', $claims['sub']);
    $this->assertSame('https://appleid.apple.com', is_array($claims['aud']) ? $claims['aud'][0] : $claims['aud']);
  }

  public function test_apples_posted_callback_moves_on_to_the_get_callback_from_our_own_page(): void
  {
    $expected = '/auth/provider/11111111-2222-3333-4444-555555555555/callback?' . http_build_query([
      'code' => 'abc',
      'state' => 'xyz',
      'user' => '{"name":{"firstName":"Sam"}}',
    ]);

    // no CSRF token: Apple can't send one
    $response = $this->post('/auth/provider/11111111-2222-3333-4444-555555555555/callback', [
      'code' => 'abc',
      'state' => 'xyz',
      'user' => '{"name":{"firstName":"Sam"}}',
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $this->assertStringContainsString('content="0;url=' . e($expected) . '"', $response->getContent());
    $this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $response->getContent());

    // must not hand out a fresh session cookie, or the one holding the link state is replaced
    $this->assertEmpty($response->headers->getCookies());
  }

  public function test_a_pasted_key_is_rebuilt_into_a_usable_pem(): void
  {
    $flattened = str_replace("\n", ' ', $this->pem);
    $this->assertNotFalse(openssl_pkey_get_private(AppleAuthProvider::normalisePrivateKey($flattened)));
  }
}
