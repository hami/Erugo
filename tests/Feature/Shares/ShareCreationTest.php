<?php

namespace Tests\Feature\Shares;

use App\Jobs\sendEmail;
use App\Models\File;
use App\Models\Setting;
use App\Models\Share;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

class ShareCreationTest extends ShareTestCase
{
  /**
   * Create a share the way the uploader does, from one finished upload.
   */
  private function createShare(User $user, array $extra = [])
  {
    $uploadId = uniqid();
    @mkdir(storage_path('app/uploads'), 0777, true);
    file_put_contents(storage_path("app/uploads/$uploadId"), "hello\n");
    $file = File::create(['name' => 'hello.txt', 'original_name' => 'hello.txt', 'size' => 6, 'type' => 'text/plain', 'temp_path' => "uploads/$uploadId"]);
    UploadSession::create([
      'upload_id' => $uploadId,
      'user_id' => $user->id,
      'filename' => 'hello.txt',
      'filesize' => 6,
      'filetype' => 'text/plain',
      'total_chunks' => 1,
      'chunks_received' => 1,
      'status' => 'complete',
      'file_id' => $file->id,
    ]);

    return $this->actingAs($user)->postJson('/api/uploads/create-share-from-uploads', array_merge([
      'upload_id' => 'batch-' . $uploadId,
      'name' => 'Hello',
      'uploadIds' => [$uploadId],
      'filePaths' => [$uploadId => 'hello.txt'],
      'expiry_date' => now()->addDays(2)->toIso8601String(),
      'recipients' => [['name' => 'Alice', 'email' => 'alice@example.com']],
    ], $extra))->assertOk();
  }

  public function test_recipients_are_remembered_by_default(): void
  {
    $this->createShare($this->makeUser());

    $share = Share::latest('id')->first();
    $this->assertSame(['alice@example.com'], $share->recipients()->pluck('email')->all());
    Bus::assertDispatched(sendEmail::class, fn(sendEmail $job) => $job->to === 'alice@example.com');
  }

  public function test_recipients_can_be_left_off_a_share_but_are_still_emailed(): void
  {
    $this->createShare($this->makeUser(), ['remember_recipients' => false]);

    $this->assertSame(0, Share::latest('id')->first()->recipients()->count());
    Bus::assertDispatched(sendEmail::class, fn(sendEmail $job) => $job->to === 'alice@example.com');
  }

  public function test_the_system_default_decides_when_the_uploader_does_not_say(): void
  {
    Setting::where('key', 'remember_recipients_default')->update(['value' => 'false']);

    $this->createShare($this->makeUser());

    $this->assertSame(0, Share::latest('id')->first()->recipients()->count());
  }

  public function test_new_shares_hide_what_the_uploader_chose(): void
  {
    $this->createShare($this->makeUser(), ['password' => 'pw', 'password_confirm' => 'pw', 'locked_hidden' => ['files', 'name']]);

    $this->assertSame(['files', 'name'], Share::latest('id')->first()->locked_hidden);
  }
}
