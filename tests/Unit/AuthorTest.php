<?php

namespace BrimleyLabs\Dolt\Tests\Unit;

use BrimleyLabs\Dolt\Data\Author;
use Illuminate\Auth\GenericUser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AuthorTest extends TestCase
{
    public function test_it_parses_the_git_form(): void
    {
        $author = Author::parse('Ada Lovelace <ada@example.test>');

        $this->assertSame('Ada Lovelace', $author->name);
        $this->assertSame('ada@example.test', $author->email);
        $this->assertSame('Ada Lovelace <ada@example.test>', (string) $author);
    }

    public function test_it_refuses_a_string_without_an_email(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Author::parse('Ada Lovelace');
    }

    public function test_it_accepts_arrays(): void
    {
        $this->assertTrue(Author::from(['name' => 'Ada', 'email' => 'ada@example.test'])->is(Author::from(['Ada', 'ADA@example.test'])));
    }

    public function test_it_credits_an_application_user(): void
    {
        $author = Author::from(new GenericUser(['id' => 7, 'name' => 'Grace Hopper', 'email' => 'grace@example.test']));

        $this->assertSame('Grace Hopper <grace@example.test>', (string) $author);
    }

    public function test_a_user_is_named_by_whatever_the_application_keeps(): void
    {
        $email = 'grace@example.test';

        $this->assertSame('Grace Hopper', Author::from(new GenericUser(['id' => 7, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'username' => 'ghopper', 'email' => $email]))->name);
        $this->assertSame('Grace', Author::from(new GenericUser(['id' => 7, 'first_name' => 'Grace', 'last_name' => null, 'email' => $email]))->name);
        $this->assertSame('ghopper', Author::from(new GenericUser(['id' => 7, 'username' => 'ghopper', 'email' => $email]))->name);
        $this->assertSame('grace', Author::from(new GenericUser(['id' => 7, 'email' => $email]))->name);
    }

    public function test_a_user_without_a_name_or_email_still_gets_an_identity(): void
    {
        $author = Author::from(new GenericUser(['id' => 7]));

        $this->assertSame('User 7', $author->name);
        $this->assertSame('user-7@users.invalid', $author->email);
    }
}
