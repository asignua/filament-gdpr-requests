<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Support\Arr;

class TranslationsTest extends TestCase
{
    private const array LOCALES = ['de', 'en', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr', 'uk'];

    public function test_every_locale_has_the_same_keys_as_english(): void
    {
        $english = $this->keys('en');

        $this->assertNotEmpty($english);

        foreach (self::LOCALES as $locale) {
            $this->assertSame($english, $this->keys($locale), "Locale [{$locale}] is out of step with [en].");
        }
    }

    /**
     * @return list<string>
     */
    private function keys(string $locale): array
    {
        $path = __DIR__.'/../../resources/lang/'.$locale.'/filament-gdpr-requests.php';

        $this->assertFileExists($path);

        $keys = array_keys(Arr::dot(require $path));
        sort($keys);

        return $keys;
    }

    public function test_every_key_the_code_asks_for_exists(): void
    {
        $english = array_flip($this->keys('en'));
        $missing = [];
        $files = array_merge(
            glob(__DIR__.'/../../src/*.php') ?: [],
            glob(__DIR__.'/../../src/*/*.php') ?: [],
            glob(__DIR__.'/../../src/*/*/*.php') ?: [],
            glob(__DIR__.'/../../src/*/*/*/*.php') ?: [],
            glob(__DIR__.'/../../resources/views/*/*.blade.php') ?: [],
        );

        $patterns = [
            '/Lang::t\(\'([a-z_.]+)\'/' => '',
            '/translated\(\'([a-z_]+)\'/' => 'errors.',
            '/(?:new Blocker|addBlocker)\(\'([a-z_]+)\'/' => 'blocker.',
            '/\$problems\[\] = \'([a-z_]+)\'/' => 'blocker.',
            '/RequestLog::record\([^,]+, \'([a-z_]+)\'/' => 'event.',
            '/RequesterMail::send\([^,]+, \'([a-z_]+)\'/' => 'requester_mail.',
        ];

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);

            foreach ($patterns as $pattern => $prefix) {
                preg_match_all($pattern, $code, $matches);

                foreach ($matches[1] as $key) {
                    // Keys built at run time ('action.'.$x) are covered by the explicit list in the next test.
                    if (str_ends_with($key, '.') || str_ends_with($key, '_')) {
                        continue;
                    }

                    $full = $prefix.$key;
                    $exists = isset($english[$full]) || isset($english[$full.'.subject']) || ($prefix === '' && str_ends_with($full, '.') === false && isset($english[$full]));

                    if (!$exists) {
                        $missing[] = $full.' ('.basename($file).')';
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    public function test_the_keys_built_at_run_time_exist(): void
    {
        $english = $this->flat('en');

        foreach (['action.none', 'action.anonymize', 'action.delete', 'action.retain', 'plan.four_eyes_on', 'plan.four_eyes_off',
            'export_status.pending', 'export_status.ready', 'export_status.failed', 'export_status.removed'] as $key) {
            $this->assertArrayHasKey($key, $english);
        }

        foreach (\Asignua\FilamentGdprRequests\Enums\RequestType::cases() as $case) {
            $this->assertArrayHasKey('type.'.$case->value, $english);
        }

        foreach (\Asignua\FilamentGdprRequests\Enums\RequestStatus::cases() as $case) {
            $this->assertArrayHasKey('status.'.$case->value, $english);
        }

        foreach (\Asignua\FilamentGdprRequests\Enums\RequestChannel::cases() as $case) {
            $this->assertArrayHasKey('channel.'.$case->value, $english);
        }

        foreach (\Asignua\FilamentGdprRequests\Enums\StepStatus::cases() as $case) {
            $this->assertArrayHasKey('step.'.$case->value, $english);
        }
    }

    public function test_placeholders_survive_in_every_locale(): void
    {
        $english = $this->flat('en');

        foreach (self::LOCALES as $locale) {
            foreach ($this->flat($locale) as $key => $text) {
                preg_match_all('/:[a-z_]+/', $english[$key], $expected);
                preg_match_all('/:[a-z_]+/', $text, $actual);
                sort($expected[0]);
                sort($actual[0]);

                $this->assertSame($expected[0], $actual[0], "[{$locale}] {$key} lost or renamed a placeholder.");
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function flat(string $locale): array
    {
        return Arr::dot(require __DIR__.'/../../resources/lang/'.$locale.'/filament-gdpr-requests.php');
    }
}
