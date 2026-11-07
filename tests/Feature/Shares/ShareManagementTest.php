<?php

namespace Tests\Feature\Shares;

use App\Jobs\sendEmail;
use App\Mail\shareAccessChangedMail;
use App\Mail\shareCreatedMail;
use App\Models\Share;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;

class ShareManagementTest extends ShareTestCase
{
  public function test_the_owner_can_rename_a_share_choose_what_it_hides_and_remove_its_password(): void
  {
    $owner = $this->makeUser();
    $share = $this->lockedShare($owner);

    $this->actingAs($owner)
      ->putJson("/api/shares/{$share->id}", ['name' => 'Renamed', 'locked_hidden' => ['files', 'sender']])
      ->assertOk()
      ->assertJsonPath('data.share.name', 'Renamed')
      ->assertJsonPath('data.share.locked_hidden', ['files', 'sender'])
      ->assertJsonMissingPath('data.share.password');

    $this->actingAs($owner)
      ->putJson("/api/shares/{$share->id}", ['locked_hidden' => ['files', 'everything']])
      ->assertStatus(422);

    $this->actingAs($owner)
      ->putJson("/api/shares/{$share->id}", ['remove_password' => true])
      ->assertJsonPath('data.share.password_protected', false);
  }

  public function test_only_the_owner_or_an_admin_can_manage_a_share(): void
  {
    $share = $this->makeShare($this->makeUser());
    $stranger = $this->makeUser();
    $admin = $this->makeUser(['admin' => true]);

    $this->actingAs($stranger)->putJson("/api/shares/{$share->id}", ['name' => 'Mine now'])->assertStatus(401);
    $this->actingAs($stranger)->postJson("/api/shares/{$share->id}/change-access", ['new_link' => true])->assertStatus(401);
    $this->actingAs($stranger)->postJson("/api/shares/{$share->id}/recipients", ['name' => 'X', 'email' => 'x@example.com'])->assertStatus(401);

    $this->actingAs($admin)->putJson("/api/shares/{$share->id}", ['name' => 'Fixed by admin'])->assertOk();
  }

  public function test_a_new_link_moves_the_files_and_retires_the_old_url(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner);
    $oldLink = $share->long_id;

    $newLink = $this->actingAs($owner)
      ->postJson("/api/shares/{$share->id}/change-access", ['new_link' => true])
      ->assertOk()
      ->json('data.share.long_id');

    $this->assertNotSame($oldLink, $newLink);
    $this->assertFileDoesNotExist(storage_path("app/shares/{$owner->id}/{$oldLink}.zip"));
    $this->assertFileExists(storage_path("app/shares/{$owner->id}/{$newLink}.zip"));

    $this->getJson("/api/shares/{$oldLink}")->assertNotFound();
    $this->get("/api/shares/{$newLink}/download")->assertOk();
  }

  public function test_a_new_link_waits_while_the_zip_is_being_built(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner, ['status' => 'pending']);

    $this->actingAs($owner)
      ->postJson("/api/shares/{$share->id}/change-access", ['new_link' => true])
      ->assertStatus(409);

    $this->actingAs($owner)
      ->postJson("/api/shares/{$share->id}/change-access", [])
      ->assertStatus(422);
  }

  public function test_changing_the_password_tells_only_the_chosen_recipients(): void
  {
    $owner = $this->makeUser();
    $share = $this->lockedShare($owner);
    $alice = $share->recipients()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = $share->recipients()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $this->actingAs($owner)
      ->postJson("/api/shares/{$share->id}/change-access", [
        'new_link' => true,
        'password' => 'brand-new',
        'password_confirmation' => 'brand-new',
        'notify' => [$alice->id],
        'include_password' => true,
      ])
      ->assertOk()
      ->assertJsonPath('data.notified', [$alice->id]);

    $this->assertTrue(Hash::check('brand-new', $share->fresh()->password));
    Bus::assertDispatchedTimes(sendEmail::class, 1);
    Bus::assertDispatched(sendEmail::class, function (sendEmail $job) {
      return $job->to === 'alice@example.com'
        && $job->mailableClass === shareAccessChangedMail::class
        && $job->mailableData['linkChanged'] === true
        && $job->mailableData['passwordChanged'] === true
        && $job->mailableData['newPassword'] === 'brand-new';
    });
    $this->assertNull($bob->fresh()->notified_at);
  }

  public function test_the_new_password_stays_out_of_the_email_unless_asked_for(): void
  {
    $owner = $this->makeUser();
    $share = $this->lockedShare($owner);
    $alice = $share->recipients()->create(['name' => 'Alice', 'email' => 'alice@example.com']);

    $this->actingAs($owner)->postJson("/api/shares/{$share->id}/change-access", [
      'password' => 'brand-new',
      'password_confirmation' => 'brand-new',
      'notify' => [$alice->id],
    ])->assertOk();

    Bus::assertDispatched(sendEmail::class, fn(sendEmail $job) => $job->mailableData['newPassword'] === null);
  }

  public function test_a_new_password_or_link_forgets_every_remembered_unlock(): void
  {
    $owner = $this->makeUser();
    $share = $this->lockedShare($owner, ['files']);

    $cookie = $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 'open-sesame'])
      ->getCookie('share_unlock_' . $share->id, false);

    $this->actingAs($owner)->postJson("/api/shares/{$share->id}/change-access", [
      'password' => 'brand-new',
      'password_confirmation' => 'brand-new',
    ])->assertOk();

    $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
      ->getJson("/api/shares/{$share->long_id}")
      ->assertJsonPath('data.share.unlocked', false);
  }

  public function test_recipients_can_be_added_edited_sent_the_link_and_removed(): void
  {
    $owner = $this->makeUser();
    $share = $this->makeShare($owner);

    $id = $this->actingAs($owner)
      ->postJson("/api/shares/{$share->id}/recipients", ['name' => 'Carol', 'email' => 'carol@example.com', 'send' => true])
      ->assertOk()
      ->json('data.recipient.id');
    Bus::assertDispatched(sendEmail::class, fn(sendEmail $job) => $job->to === 'carol@example.com' && $job->mailableClass === shareCreatedMail::class);

    $this->actingAs($owner)->putJson("/api/shares/{$share->id}/recipients/{$id}", ['email' => 'not-an-email'])->assertStatus(422);
    $this->actingAs($owner)
      ->putJson("/api/shares/{$share->id}/recipients/{$id}", ['email' => 'carol@example.org'])
      ->assertJsonPath('data.recipient.email', 'carol@example.org');

    $this->actingAs($owner)->postJson("/api/shares/{$share->id}/recipients/{$id}/resend")->assertOk();
    Bus::assertDispatchedTimes(sendEmail::class, 2);

    // a recipient is only reachable through its own share
    $other = $this->makeShare($owner);
    $this->actingAs($owner)->deleteJson("/api/shares/{$other->id}/recipients/{$id}")->assertNotFound();

    $this->actingAs($owner)
      ->deleteJson("/api/shares/{$share->id}/recipients/{$id}")
      ->assertOk()
      ->assertJsonCount(0, 'data.recipients');
  }

  public function test_past_recipients_are_suggested_only_to_whoever_sent_to_them(): void
  {
    $owner = $this->makeUser();
    $first = $this->makeShare($owner);
    $second = $this->makeShare($owner);
    $first->recipients()->create(['name' => 'Alice Old', 'email' => 'alice@example.com']);
    $second->recipients()->create(['name' => 'Alice Archer', 'email' => 'ALICE@example.com']);
    $second->recipients()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $someoneElse = $this->makeUser();
    $this->makeShare($someoneElse)->recipients()->create(['name' => 'Alicia', 'email' => 'alicia@example.com']);

    // one entry per address, using the most recent name
    $this->actingAs($owner)
      ->getJson('/api/recipients/suggestions?q=ali')
      ->assertOk()
      ->assertJsonCount(1, 'data.recipients')
      ->assertJsonPath('data.recipients.0.name', 'Alice Archer');

    $this->actingAs($owner)->getJson('/api/recipients/suggestions?q=example')->assertJsonCount(2, 'data.recipients');
    $this->actingAs($owner)->getJson('/api/recipients/suggestions?q=')->assertJsonCount(0, 'data.recipients');
    $this->actingAs($someoneElse)->getJson('/api/recipients/suggestions?q=bob')->assertJsonCount(0, 'data.recipients');
  }
}
