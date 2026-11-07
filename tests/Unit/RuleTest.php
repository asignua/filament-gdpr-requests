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
