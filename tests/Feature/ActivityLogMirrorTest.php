<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature {
    use Asignua\FilamentGdprRequests\Enums\RequestType;
    use Asignua\FilamentGdprRequests\Services\RequestService;
    use Asignua\FilamentGdprRequests\Tests\Support\Shop;
    use Asignua\FilamentGdprRequests\Tests\TestCase;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;

    /**
     * asignua/filament-activity-log-plus is optional, and it needs PHP 8.4, so it is not installed here. The mirror
     * only needs the global `activity()` helper and the plugin's service provider class; both are faked in a
     * separate process so they cannot leak into the other tests.
     */
    class ActivityLogMirrorTest extends TestCase
    {
        #[RunInSeparateProcess]
        public function test_actions_are_mirrored_with_structure_only_when_the_package_is_installed(): void
        {
            $this->installFakes();
            $dpo = $this->user();

            $request = Shop::request(Shop::customer('Ada Lovelace', 'ada@customer.example'), RequestType::Access, $dpo);
            app(RequestService::class)->extend($request, 1, 'Secret reason for ada@customer.example', $dpo);

            $recorded = \FakeActivity::$entries;
            $this->assertNotEmpty($recorded);
            $this->assertSame('gdpr', $recorded[0]['log']);
            $this->assertSame('gdpr.received', $recorded[0]['description']);
            $this->assertContains('gdpr.identity_verified', array_column($recorded, 'description'));

            $dump = json_encode($recorded, JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString($request->ulid, $dump);
            $this->assertStringNotContainsString('Ada Lovelace', $dump);
            $this->assertStringNotContainsString('customer.example', $dump);
            $this->assertStringNotContainsString('Secret reason', $dump);
        }

        #[RunInSeparateProcess]
        public function test_a_failing_audit_package_never_breaks_the_process(): void
        {
            $this->installFakes();
            \FakeActivity::$throw = true;
            $dpo = $this->user();

            $request = Shop::request(Shop::customer('Ada', 'ada@customer.example'), RequestType::Access, $dpo);

            $this->assertTrue($request->isVerified());
            $this->assertNotEmpty($request->events()->get());
        }

        private function installFakes(): void
        {
            require_once __DIR__.'/../Support/fake-activity.php';
        }
    }
}
