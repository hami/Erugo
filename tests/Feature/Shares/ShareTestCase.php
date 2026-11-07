<?php

namespace Tests\Feature\Shares;

use App\Models\File;
use App\Models\Share;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use App\Jobs\sendEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Shares with real files on disk, in a storage folder of their own that is removed afterwards.
 */
abstract class ShareTestCase extends TestCase
{
  use RefreshDatabase;

  protected string $storage;

  protected function setUp(): void
  {
    parent::setUp();

    $this->storage = sys_get_temp_dir() . '/erugo-tests-' . uniqid();
    mkdir($this->storage . '/app/shares', 0777, true);
    $this->app->useStoragePath($this->storage);

    $this->seed(SettingsSeeder::class);

    // No mail server here; tests that care about emails assert on the queued jobs
    Bus::fake([sendEmail::class]);
  }

  protected function tearDown(): void
  {
    exec('rm -rf ' . escapeshellarg($this->storage));
    parent::tearDown();
  }

  protected function makeUser(array $attributes = []): User
  {
    return User::create(array_merge([
      'name' => 'Sam Sender',
      'email' => uniqid() . '@example.com',
      'password' => Hash::make('secret-password'),
      'admin' => false,
      'active' => true,
    ], $attributes));
  }

  /**
   * A ready share holding two text files in a zip, as the zip job leaves them.
   */
  protected function makeShare(User $owner, array $attributes = []): Share
  {
    $longId = $attributes['long_id'] ?? 'test-' . uniqid();
    $path = $owner->id . '/' . $longId;
    $dir = storage_path('app/shares/' . $path);
    mkdir($dir, 0777, true);

    $share = Share::create(array_merge([
      'name' => 'Holiday photos',
      'description' => 'Here you go',
      'user_id' => $owner->id,
      'path' => $path,
      'long_id' => $longId,
      'size' => 20,
      'file_count' => 2,
      'status' => 'ready',
      'expires_at' => now()->addDays(3),
    ], $attributes));

    foreach (['one.txt', 'two.txt'] as $name) {
      file_put_contents($dir . '/' . $name, "contents of $name\n");
      File::create(['name' => $name, 'original_name' => $name, 'size' => 10, 'type' => 'text/plain', 'share_id' => $share->id]);
    }
    exec('cd ' . escapeshellarg($dir) . ' && zip -qr ' . escapeshellarg($dir . '.zip') . ' .');
    exec('rm -rf ' . escapeshellarg($dir));

    return $share;
  }

  protected function lockedShare(User $owner, array $hidden = [], string $password = 'open-sesame'): Share
  {
    return $this->makeShare($owner, [
      'password' => Hash::make($password),
      'locked_hidden' => $hidden,
    ]);
  }
}
