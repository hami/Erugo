<?php

namespace Tests\Feature\Shares;

use App\Models\Setting;

class ExpiredDownloadTest extends ShareTestCase
{
  private function linkFor($user, $share)
  {
    return $this->actingAs($user)->postJson("/api/shares/{$share->id}/download-link");
  }

  private function turnOff(string $role): void
  {
    Setting::where('key', "{$role}_download_expired_enabled")->update(['value' => 'false']);
  }

  public function test_an_admin_can_download_an_expired_share_without_it_counting(): void
  {
    $share = $this->makeShare($this->makeUser(), ['expires_at' => now()->subDay(), 'download_limit' => 1]);
    $admin = $this->makeUser(['admin' => true]);

    // the public link refuses an expired share
    $this->get("/api/shares/{$share->long_id}/download")->assertRedirect("/shares/{$share->long_id}");

    $url = $this->linkFor($admin, $share)->assertOk()->json('data.url');
    $this->assertStringStartsWith("/api/shares/{$share->id}/signed-download?", $url);

    $this->get($url)->assertOk()->assertDownload('Holiday photos.zip');
    $this->assertSame(0, $share->fresh()->download_count);
  }

  public function test_an_owner_can_download_their_own_expired_share_but_not_someone_elses(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner, ['expires_at' => now()->subDay()]);
    $other = $this->makeShare($this->makeUser(), ['expires_at' => now()->subDay()]);

    $url = $this->linkFor($owner, $share)->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertDownload('Holiday photos.zip');

    $this->linkFor($owner, $other)->assertStatus(403);
  }

  public function test_links_cannot_be_forged_or_switched_to_another_role(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner, ['expires_at' => now()->subDay()]);
    $url = $this->linkFor($owner, $share)->json('data.url');

    $this->get("/api/shares/{$share->id}/signed-download?as=admin")->assertStatus(403);
    $this->get(str_replace('as=owner', 'as=admin', $url))->assertStatus(403);
  }

  public function test_each_switch_turns_off_its_own_links_including_ones_already_issued(): void
  {
    $owner = $this->makeUser();
    $admin = $this->makeUser(['admin' => true]);
    $share = $this->makeShare($owner, ['expires_at' => now()->subDay()]);
    $ownerUrl = $this->linkFor($owner, $share)->json('data.url');
    $adminUrl = $this->linkFor($admin, $share)->json('data.url');

    $this->turnOff('owner');
    $this->linkFor($owner, $share)->assertStatus(403);
    $this->get($ownerUrl)->assertNotFound();
    $this->get($adminUrl)->assertOk();

    $this->turnOff('admin');
    $this->linkFor($admin, $share)->assertStatus(403);
    $this->get($adminUrl)->assertNotFound();
  }

  public function test_an_admin_with_admin_downloads_off_cannot_use_the_owner_route_for_other_peoples_shares(): void
  {
    $this->turnOff('admin');
    $admin = $this->makeUser(['admin' => true]);
    $share = $this->makeShare($this->makeUser(), ['expires_at' => now()->subDay()]);
    $own = $this->makeShare($admin, ['expires_at' => now()->subDay()]);

    $this->linkFor($admin, $share)->assertStatus(403);
    $this->linkFor($admin, $own)->assertOk();
  }

  public function test_a_deleted_share_has_nothing_to_download(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner);
    $share->cleanFiles(true);

    $this->linkFor($owner, $share)->assertStatus(410);
  }
}
