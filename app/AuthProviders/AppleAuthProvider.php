<?php

namespace App\AuthProviders;

use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Apple\Provider as AppleProvider;
use App\AuthProviders\AuthProviderUser;
use App\Models\AuthProvider as AuthProviderModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

/**
 * Sign in with Apple.
 *
 * Apple has no fixed client secret: each sign-in signs a short-lived JWT with the
 * team's private key (.p8), so nothing needs renewing. Apple posts back to the
 * callback (response_mode=form_post); routes/web.php turns that POST into a GET so
 * the session, and with it the state check and account linking, still works.
 *
 * With "Hide My Email" the address Apple gives is a private relay address, so it
 * won't match an existing user's email. Those users link Apple from their profile
 * first, after which the stable Apple user id is used.
 */
class AppleAuthProvider extends BaseAuthProvider
{
  protected $client_id;
  protected $team_id;
  protected $key_id;
  protected $private_key;
  protected $provider;

  public function __construct(AuthProviderModel $provider)
  {
    $config = $provider->provider_config;
    $this->client_id = $config->client_id ?? null;
    $this->team_id = $config->team_id ?? null;
    $this->key_id = $config->key_id ?? null;
    $this->private_key = $config->private_key ?? null;
    $this->provider = $provider;
  }

  private function createClient()
  {
    if (!$this->client_id || !$this->team_id || !$this->key_id || !$this->private_key) {
      $this->throwMissingDataException();
    }

    // The package builds its client-secret JWT from these config values
    config([
      'services.apple.client_id' => $this->client_id,
      'services.apple.team_id' => $this->team_id,
      'services.apple.key_id' => $this->key_id,
    ]);

    return Socialite::buildProvider(AppleProvider::class, [
      'client_id' => $this->client_id,
      'client_secret' => '',
      'redirect' => route('social.provider.callback', ['provider' => $this->provider->uuid]),
    ])->setConfig(new \SocialiteProviders\Manager\Config(
      $this->client_id,
      '',
      route('social.provider.callback', ['provider' => $this->provider->uuid]),
      ['private_key' => self::normalisePrivateKey($this->private_key)]
    ));
  }

  /**
   * A .p8 key pasted into a one-line field loses its line breaks; rebuild the PEM.
   */
  public static function normalisePrivateKey(string $key): string
  {
    $body = preg_replace('/-----(BEGIN|END) PRIVATE KEY-----|\s+/', '', $key);
    return "-----BEGIN PRIVATE KEY-----\n" . chunk_split($body, 64, "\n") . "-----END PRIVATE KEY-----\n";
  }

  public function redirect()
  {
    return $this->createClient()->scopes(['name', 'email'])->redirect();
  }

  public function handleCallback(): AuthProviderUser
  {
    try {
      $user = $this->createClient()->user();
    } catch (\Exception $e) {
      Log::error('Apple authentication error: ' . $e->getMessage());
      throw $e;
    }

    $verified = $user->user['email_verified'] ?? false;

    return new AuthProviderUser([
      'sub' => $user->id,
      // Apple only sends the name the first time someone signs in to the app
      'name' => $user->name ?: ($user->email ?? 'Apple user'),
      'email' => $user->email,
      'avatar' => null,
      'verified' => $verified === true || $verified === 'true'
    ]);
  }

  public static function getIcon(): string
  {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><!--!Font Awesome Free 6.7.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>';
  }

  public static function getName(): string
  {
    return 'Apple';
  }

  public static function getDescription(): string
  {
    return 'Sign in with Apple lets people use their Apple Account. You need an Apple Developer account: a Services ID (the client ID), your Team ID, and a Sign in with Apple key (its Key ID and the contents of the .p8 file).';
  }

  public static function getValidator(array $data): Validator
  {
    return ValidatorFacade::make($data, [
      'client_id' => ['required', 'string'],
      'team_id' => ['required', 'string', 'size:10'],
      'key_id' => ['required', 'string', 'size:10'],
      'private_key' => ['required', 'string', 'regex:/^[\sA-Za-z0-9+\/=-]+$/', 'not_regex:/^\s*$/'],
    ]);
  }

  public static function getInformationUrl(): ?string
  {
    return 'https://developer.apple.com/sign-in-with-apple/get-started/';
  }

  public static function getEmptyProviderConfig(): array
  {
    return [
      'client_id' => '',
      'team_id' => '',
      'key_id' => '',
      'private_key' => '',
    ];
  }
}
