<?php

namespace Tests\Feature\Shares;

use App\Models\Setting;
use App\Models\Share;

class LockedShareTest extends ShareTestCase
{
  public function test_a_locked_share_hides_the_details_chosen_for_it(): void
  {
    $share = $this->lockedShare($this->makeUser(), ['files', 'stats', 'message', 'sender', 'name']);

    $this->getJson("/api/shares/{$share->long_id}")
      ->assertOk()
      ->assertJsonPath('data.share.name', null)
      ->assertJsonPath('data.share.description', null)
      ->assertJsonPath('data.share.size', null)
      ->assertJsonPath('data.share.file_count', null)
      ->assertJsonPath('data.share.user.name', null)
      ->assertJsonPath('data.share.files', [])
      ->assertJsonPath('data.share.unlocked', false)
      ->assertJsonPath('data.share.hidden', ['files', 'stats', 'message', 'sender', 'name']);
  }

  public function test_details_not_chosen_still_show_while_locked(): void
  {
    $share = $this->lockedShare($this->makeUser(), ['files']);

    $this->getJson("/api/shares/{$share->long_id}")
      ->assertOk()
      ->assertJsonPath('data.share.name', 'Holiday photos')
      ->assertJsonPath('data.share.file_count', 2)
      ->assertJsonPath('data.share.files', [])
      ->assertJsonPath('data.share.hidden', ['files']);
  }

  public function test_a_share_without_a_password_hides_nothing(): void
  {
    $share = $this->makeShare($this->makeUser(), ['locked_hidden' => ['files', 'name']]);

    $this->getJson("/api/shares/{$share->long_id}")
      ->assertOk()
      ->assertJsonPath('data.share.name', 'Holiday photos')
      ->assertJsonCount(2, 'data.share.files')
      ->assertJsonPath('data.share.unlocked', true)
      ->assertJsonPath('data.share.hidden', []);
  }

  public function test_the_right_password_unlocks_and_is_remembered(): void
  {
    $share = $this->lockedShare($this->makeUser(), ['files', 'name']);

    $response = $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 'open-sesame'])
      ->assertOk()
      ->assertJsonPath('data.share.name', 'Holiday photos')
      ->assertJsonCount(2, 'data.share.files')
      ->assertCookie('share_unlock_' . $share->id, null, false);

    $cookie = $response->getCookie('share_unlock_' . $share->id, false);
    $this->assertTrue($cookie->isHttpOnly());
    $this->assertEqualsWithDelta($share->expires_at->getTimestamp(), $cookie->getExpiresTime(), 120);

    // the cookie now stands in for the password everywhere
    $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
      ->getJson("/api/shares/{$share->long_id}")
      ->assertJsonPath('data.share.unlocked', true)
      ->assertJsonCount(2, 'data.share.files');

    $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
      ->get("/api/shares/{$share->long_id}/download")
      ->assertOk()
      ->assertDownload('Holiday photos.zip');

    $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
      ->get("/api/shares/{$share->long_id}/download/file/one.txt")
      ->assertOk();
  }

  public function test_a_wrong_or_forged_unlock_is_refused(): void
  {
    $share = $this->lockedShare($this->makeUser(), ['files']);

    $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 'nope'])->assertStatus(401);

    $this->withCredentials()->withUnencryptedCookie('share_unlock_' . $share->id, str_repeat('a', 64))
      ->getJson("/api/shares/{$share->long_id}")
      ->assertJsonPath('data.share.unlocked', false)
      ->assertJsonPath('data.share.files', []);

    $this->get("/api/shares/{$share->long_id}/download/file/one.txt")->assertStatus(401);
    $this->get("/api/shares/{$share->long_id}/download")
      ->assertRedirect("/shares/{$share->long_id}?error=password_required");
  }

  public function test_wrong_passwords_are_rate_limited(): void
  {
    $share = $this->lockedShare($this->makeUser());

    for ($i = 0; $i < 10; $i++) {
      $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 'nope'])->assertStatus(401);
    }

    // even the right password waits now, and downloads are held back too
    $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 'open-sesame'])->assertStatus(429);
    $this->post("/api/shares/{$share->long_id}/download", ['password' => 'open-sesame'])
      ->assertRedirect("/shares/{$share->long_id}?error=too_many_attempts");
  }

  public function test_new_shares_take_the_hidden_details_from_the_system_defaults(): void
  {
    Setting::where('key', 'locked_hidden_default_files')->update(['value' => 'true']);
    Setting::where('key', 'locked_hidden_default_name')->update(['value' => 'true']);

    $this->assertSame(['files', 'name'], Share::defaultLockedHidden());
  }

  public function test_share_listings_never_include_the_password_hash(): void
  {
    $owner = $this->makeUser();
    $this->lockedShare($owner);

    $this->actingAs($owner)
      ->getJson('/api/shares?show_deleted=false')
      ->assertOk()
      ->assertJsonPath('data.shares.0.password_protected', true)
      ->assertJsonMissingPath('data.shares.0.password');
  }
}
