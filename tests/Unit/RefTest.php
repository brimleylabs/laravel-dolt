<?php

namespace BrimleyLabs\Dolt\Tests\Unit;

use BrimleyLabs\Dolt\Database\DoltConnector;
use BrimleyLabs\Dolt\Exceptions\InvalidRefException;
use BrimleyLabs\Dolt\Support\Ref;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RefTest extends TestCase
{
    #[DataProvider('goodRefs')]
    public function test_it_accepts_real_refs(string $ref): void
    {
        $this->assertSame($ref, Ref::validate($ref));
        $this->assertSame("'{$ref}'", Ref::literal($ref));
    }

    public static function goodRefs(): array
    {
        return [
            ['main'], ['HEAD'], ['HEAD~2'], ['main^'], ['workspace/spring-sale'], ['v1.4.0'],
            ['main..feature'], ['main...feature'], ['0123456789abcdefghijklmnopqrstuv'],
        ];
    }

    #[DataProvider('badRefs')]
    public function test_it_refuses_anything_that_could_break_out_of_a_literal(string $ref): void
    {
        $this->expectException(InvalidRefException::class);

        Ref::validate($ref);
    }

    public static function badRefs(): array
    {
        return [
            [''], ["main'"], ["main' or '1'='1"], ['main; drop table users'], ['main branch'],
            ['-D'], ['--force'], ['main"'], ['main`'], ["main\n"], [str_repeat('a', 256)],
        ];
    }

    public function test_it_checks_table_names(): void
    {
        $this->assertSame('posts', Ref::table('posts'));
        $this->assertSame("'order_items'", Ref::tableLiteral('order_items'));

        $this->expectException(InvalidRefException::class);

        Ref::table("posts'; drop table users; --");
    }

    public function test_it_checks_table_patterns(): void
    {
        $this->assertSame('telescope_%', Ref::pattern('telescope_%'));
        $this->assertSame('cache*', Ref::pattern('cache*'));

        $this->expectException(InvalidRefException::class);

        Ref::pattern("jobs' or 1=1");
    }

    public function test_it_matches_tables_against_patterns(): void
    {
        $this->assertTrue(Ref::matches('jobs', 'jobs'));
        $this->assertFalse(Ref::matches('jobs', 'job_batches'));
        $this->assertFalse(Ref::matches('jobs', 'failed_jobs'));
        $this->assertTrue(Ref::matches('telescope_%', 'telescope_entries'));
        $this->assertFalse(Ref::matches('telescope_%', 'telescopes'));
        $this->assertTrue(Ref::matches('cache*', 'cache_locks'));
        $this->assertTrue(Ref::matches('pulse_?', 'pulse_a'));
        $this->assertFalse(Ref::matches('pulse_?', 'pulse_ab'));
    }

    public function test_it_recognises_commit_hashes(): void
    {
        $this->assertTrue(Ref::isHash('0123456789abcdefghijklmnopqrstuv'));
        $this->assertFalse(Ref::isHash('main'));
        $this->assertFalse(Ref::isHash('0123456789abcdefghijklmnopqrstuw'));
        $this->assertFalse(Ref::isHash('0123456789abcdef'));
    }

    public function test_it_quotes_identifiers(): void
    {
        $this->assertSame('`posts`', Ref::identifier('posts'));
        $this->assertSame('`we``ird`', Ref::identifier('we`ird'));
    }

    public function test_a_database_name_can_carry_a_branch(): void
    {
        $this->assertSame(['shop', null], DoltConnector::splitDatabase('shop'));
        $this->assertSame(['shop', 'staging'], DoltConnector::splitDatabase('shop/staging'));
        $this->assertSame(['shop', 'workspace/sale'], DoltConnector::splitDatabase('shop/workspace/sale'));
        $this->assertSame(['shop', null], DoltConnector::splitDatabase('shop/'));
    }
}
