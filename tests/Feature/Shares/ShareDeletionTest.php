<?php

namespace Tests\Feature\Shares;

use App\Models\Setting;

class ShareDeletionTest extends ShareTestCase
{
  public function test_the_owner_can_delete_their_share_now(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner);

    $this->actingAs($owner)
      ->deleteJson("/api/shares/{$share->id}")
      ->assertOk()
      ->assertJsonPath('data.share.status', 'deleted');

    $share->refresh();
    $this->assertSame('deleted', $share->status);
    $this->assertFalse($share->expires_at->isFuture());
    $this->assertFileDoesNotExist(storage_path("app/shares/{$share->path}.zip"));

    // the link no longer works
    $this->getJson("/api/shares/{$share->long_id}")->assertStatus(410);
  }

  public function test_an_admin_can_delete_anyones_share_but_a_stranger_cannot(): void
  {
    $share = $this->makeShare($this->makeUser());
    $stranger = $this->makeUser();
    $admin = $this->makeUser(['admin' => true]);

    $this->actingAs($stranger)->deleteJson("/api/shares/{$share->id}")->assertStatus(401);
    $this->assertSame('ready', $share->fresh()->status);

    $this->actingAs($admin)->deleteJson("/api/shares/{$share->id}")->assertOk();
    $this->assertSame('deleted', $share->fresh()->status);
  }

  public function test_turning_off_owner_deletion_leaves_it_to_admins(): void
  {
    Setting::where('key', 'owner_share_delete_enabled')->update(['value' => 'false']);
    $owner = $this->makeUser();
    $share = $this->makeShare($owner);
    $adminsOwn = $this->makeShare($admin = $this->makeUser(['admin' => true]));

    $this->actingAs($owner)->deleteJson("/api/shares/{$share->id}")->assertStatus(401);
    $this->assertSame('ready', $share->fresh()->status);

    $this->actingAs($admin)->deleteJson("/api/shares/{$share->id}")->assertOk();
    $this->actingAs($admin)->deleteJson("/api/shares/{$adminsOwn->id}")->assertOk();
  }

  public function test_a_deleted_share_cannot_be_deleted_again(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner);

    $this->actingAs($owner)->deleteJson("/api/shares/{$share->id}")->assertOk();
    $this->actingAs($owner)->deleteJson("/api/shares/{$share->id}")->assertStatus(409);
  }
}
