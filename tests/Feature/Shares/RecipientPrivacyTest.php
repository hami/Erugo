<?php

namespace Tests\Feature\Shares;

use App\Jobs\sendEmail;
use App\Models\Setting;
use App\Models\ShareRecipient;
use Illuminate\Support\Facades\Bus;

class RecipientPrivacyTest extends ShareTestCase
{
  public function test_recipients_go_when_a_shares_files_are_cleaned_up(): void
  {
    $share = $this->makeShare($this->makeUser());
    $share->recipients()->create(['name' => 'Alice', 'email' => 'alice@example.com']);

    $this->assertTrue($share->cleanFiles(true));

    $this->assertSame('deleted', $share->fresh()->status);
    $this->assertSame(0, ShareRecipient::count());
  }

  public function test_an_admin_can_forget_someone_across_every_share(): void
  {
    $owner = $this->makeUser();
    $this->makeShare($owner)->recipients()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->makeShare($this->makeUser())->recipients()->create(['name' => 'Alice', 'email' => 'Alice@Example.com']);
    $this->makeShare($owner)->recipients()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $this->actingAs($owner)->postJson('/api/recipients/forget', ['email' => 'alice@example.com'])->assertForbidden();

    $this->actingAs($this->makeUser(['admin' => true]))
      ->postJson('/api/recipients/forget', ['email' => 'alice@example.com'])
      ->assertOk()
      ->assertJsonPath('data.removed', 2);

    $this->assertSame(['bob@example.com'], ShareRecipient::pluck('email')->all());
  }

  public function test_suggestions_can_be_turned_off(): void
  {
    $owner = $this->makeUser();
    $this->makeShare($owner)->recipients()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    Setting::where('key', 'recipient_suggestions_enabled')->update(['value' => 'false']);

    $this->actingAs($owner)->getJson('/api/recipients/suggestions?q=ali')->assertJsonCount(0, 'data.recipients');
  }

  public function test_passwords_stay_out_of_emails_when_an_admin_says_so(): void
  {
    Setting::where('key', 'password_in_email_allowed')->update(['value' => 'false']);
    $owner = $this->makeUser();
    $share = $this->lockedShare($owner);
    $alice = $share->recipients()->create(['name' => 'Alice', 'email' => 'alice@example.com']);

    $this->actingAs($owner)->postJson("/api/shares/{$share->id}/change-access", [
      'password' => 'brand-new',
      'password_confirmation' => 'brand-new',
      'notify' => [$alice->id],
      'include_password' => true,
    ])->assertOk();

    Bus::assertDispatched(sendEmail::class, fn(sendEmail $job) => $job->mailableData['newPassword'] === null
      && $job->mailableData['passwordChanged'] === true);
  }
}
