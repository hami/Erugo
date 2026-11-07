<?php

namespace Tests\Feature\Shares;

use App\Models\Setting;

class AdminDownloadTest extends ShareTestCase
{
  public function test_an_admin_can_download_an_expired_share_without_it_counting(): void
  {
    $share = $this->makeShare($this->makeUser(), ['expires_at' => now()->subDay(), 'download_limit' => 1]);
    $admin = $this->makeUser(['admin' => true]);

    // the public link refuses an expired share
    $this->get("/api/shares/{$share->long_id}/download")->assertRedirect("/shares/{$share->long_id}");

    $url = $this->actingAs($admin)
      ->postJson("/api/shares/{$share->id}/admin-download-link")
      ->assertOk()
      ->json('data.url');

    $this->assertStringStartsWith("/api/shares/{$share->id}/admin-download?", $url);

    $this->get($url)->assertOk()->assertDownload('Holiday photos.zip');
    $this->assertSame(0, $share->fresh()->download_count);
  }

  public function test_the_link_is_for_admins_only_and_cannot_be_forged(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner, ['expires_at' => now()->subDay()]);

    $this->actingAs($owner)->postJson("/api/shares/{$share->id}/admin-download-link")->assertStatus(403);
    $this->get("/api/shares/{$share->id}/admin-download")->assertStatus(403);
    $this->get("/api/shares/{$share->id}/admin-download?expires=9999999999&signature=nope")->assertStatus(403);
  }

  public function test_turning_it_off_stops_new_links_and_ones_already_issued(): void
  {
    $share = $this->makeShare($this->makeUser(), ['expires_at' => now()->subDay()]);
    $admin = $this->makeUser(['admin' => true]);
    $url = $this->actingAs($admin)->postJson("/api/shares/{$share->id}/admin-download-link")->json('data.url');

    Setting::where('key', 'admin_download_expired_enabled')->update(['value' => 'false']);

    $this->actingAs($admin)->postJson("/api/shares/{$share->id}/admin-download-link")->assertStatus(403);
    $this->get($url)->assertNotFound();
  }

  public function test_a_deleted_share_has_nothing_to_download(): void
  {
    $share = $this->makeShare($this->makeUser());
    $admin = $this->makeUser(['admin' => true]);
    $share->cleanFiles(true);

    $this->actingAs($admin)->postJson("/api/shares/{$share->id}/admin-download-link")->assertStatus(410);
  }
}
