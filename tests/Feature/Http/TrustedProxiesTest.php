<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
  protected function tearDown(): void
  {
    putenv('TRUSTED_PROXIES');
    parent::tearDown();
  }

  private function seenThroughProxy(): array
  {
    Route::get('/_proxy_check', fn() => ['secure' => request()->isSecure(), 'ip' => request()->ip()]);

    return $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
      ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
      ->getJson('/_proxy_check')
      ->json();
  }

  public function test_forwarded_headers_are_ignored_by_default(): void
  {
    $this->assertSame(['secure' => false, 'ip' => '172.18.0.2'], $this->seenThroughProxy());
  }

  public function test_a_trusted_proxy_passes_on_https_and_the_visitors_address(): void
  {
    putenv('TRUSTED_PROXIES=172.16.0.0/12');
    $this->refreshApplication();

    $this->assertSame(['secure' => true, 'ip' => '203.0.113.7'], $this->seenThroughProxy());
  }

  public function test_an_untrusted_address_cannot_claim_https(): void
  {
    putenv('TRUSTED_PROXIES=10.0.0.1');
    $this->refreshApplication();

    $this->assertSame(['secure' => false, 'ip' => '172.18.0.2'], $this->seenThroughProxy());
  }
}
