<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Unit;

use Asignua\FilamentGdprRequests\Exceptions\MissingPseudonymKey;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Tests\TestCase;

class PseudonymTest extends TestCase
{
    public function test_without_its_own_key_the_plugin_refuses_and_does_not_borrow_the_app_key(): void
    {
        config(['filament-gdpr-requests.pseudonym.key' => null]);

        $this->assertNotEmpty(config('app.key'));
        $this->assertFalse(Pseudonym::isConfigured());
        $this->expectException(MissingPseudonymKey::class);

        Pseudonym::forSubject('App\\Models\\User', '1');
    }

    public function test_a_short_key_is_refused(): void
    {
        config(['filament-gdpr-requests.pseudonym.key' => 'short']);

        $this->expectException(MissingPseudonymKey::class);

        Pseudonym::forEmail('a@example.test');
    }

    public function test_pseudonyms_are_deterministic_keyed_and_independent_of_the_purpose(): void
    {
        $subject = Pseudonym::forSubject('M', '5');

        $this->assertSame($subject, Pseudonym::forSubject('M', '5'));
        $this->assertNotSame($subject, Pseudonym::forSubject('M', '6'));
        $this->assertNotSame(Pseudonym::forEmail('5'), Pseudonym::forSubject('M', '5'));
        $this->assertSame(64, strlen($subject));

        config(['filament-gdpr-requests.pseudonym.key' => str_repeat('k', 40)]);

        $this->assertNotSame($subject, Pseudonym::forSubject('M', '5'));
    }

    public function test_email_normalisation_is_only_trim_and_lowercase(): void
    {
        $this->assertSame('a.b+c@example.test', Pseudonym::normalizeEmail("  A.B+C@Example.TEST \n"));
        $this->assertSame(Pseudonym::forEmail('ADA@x.test '), Pseudonym::forEmail('ada@x.test'));
        $this->assertNotSame(Pseudonym::forEmail('ada+1@x.test'), Pseudonym::forEmail('ada@x.test'));
        $this->assertNull(Pseudonym::normalizeEmail('   '));
    }

    public function test_an_old_key_version_is_still_usable_for_matching(): void
    {
        $old = Pseudonym::forSubject('M', '1');
        config([
            'filament-gdpr-requests.pseudonym.previous_keys' => [1 => config('filament-gdpr-requests.pseudonym.key')],
            'filament-gdpr-requests.pseudonym.key' => str_repeat('n', 40),
            'filament-gdpr-requests.pseudonym.key_version' => 2,
        ]);

        $this->assertSame($old, Pseudonym::forSubject('M', '1', 1));
        $this->assertNotSame($old, Pseudonym::forSubject('M', '1'));
    }
}
