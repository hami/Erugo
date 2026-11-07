<?php

namespace Tests\Feature\Shares;

class ShareDownloadTest extends ShareTestCase
{
  public function test_a_ready_share_downloads_as_a_zip_and_is_counted(): void
  {
    $share = $this->makeShare($this->makeUser());

    $this->get("/api/shares/{$share->long_id}/download")->assertOk()->assertDownload('Holiday photos.zip');
    $this->assertSame(1, $share->fresh()->download_count);
  }

  public function test_an_expired_share_sends_people_back_to_its_page(): void
  {
    $share = $this->makeShare($this->makeUser(), ['expires_at' => now()->subDay()]);

    $this->get("/api/shares/{$share->long_id}/download")->assertRedirect("/shares/{$share->long_id}");
  }
}
