<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Unit;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use InvalidArgumentException;

class RuleTest extends TestCase
{
    public function test_hash_is_stable_and_recognises_its_own_output(): void
    {
        $rule = Rule::hash();
        $once = $rule->apply('ada@example.test', 'customers', 'email', 1, []);
        $twice = $rule->apply($once, 'customers', 'email', 1, []);

        $this->assertStringStartsWith(Rule::HASH_PREFIX, $once);
        $this->assertSame($once, $twice, 'A rerun must not hash the hash.');
        $this->assertSame($once, $rule->apply('ada@example.test', 'customers', 'email', 2, []));
        $this->assertNotSame($once, $rule->apply('other@example.test', 'customers', 'email', 1, []));
        $this->assertNull($rule->apply(null, 'customers', 'email', 1, []));
    }

    public function test_a_value_that_only_looks_like_a_hash_is_still_hashed(): void
    {
        $rule = Rule::hash();

        foreach (['h1$Ada Lovelace', 'h1$', 'h1$ABCDEF0123456789ABCDEF0123456789', 'h1$'.str_repeat('a', 31), 'h1$'.str_repeat('a', 33), 'h1$'.str_repeat('a', 32)."\n"] as $value) {
            $result = $rule->apply($value, 'customers', 'name', 1, []);

            $this->assertNotSame($value, $result, $value);
            $this->assertMatchesRegularExpression('/\Ah1\$[0-9a-f]{32}\z/', $result);
        }
    }

    public function test_hash_recognises_its_own_output_at_any_declared_length(): void
    {
        foreach ([4, 8, 16, 32, 64, 100] as $length) {
            $rule = Rule::hash($length);
            $once = $rule->apply('Ada', 'customers', 'name', 1, []);

            $this->assertSame($once, $rule->apply($once, 'customers', 'name', 1, []), (string) $length);
        }
    }

    public function test_the_token_is_unique_per_row_and_the_same_on_every_run(): void
    {
        $rule = Rule::uniqueToken('erased-', '@x.invalid');

        $a = $rule->apply('whatever', 'customers', 'email', 1, []);

        $this->assertSame($a, $rule->apply('something else', 'customers', 'email', 1, []));
        $this->assertNotSame($a, $rule->apply('whatever', 'customers', 'email', 2, []));
        $this->assertStringStartsWith('erased-', $a);
        $this->assertStringEndsWith('@x.invalid', $a);
    }

    public function test_anonymize_needs_rules_and_retain_needs_a_basis_and_a_term(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Erase::anonymize([]);
    }

    public function test_retain_without_a_term_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Erase::retain('Law', 0);
    }
}
