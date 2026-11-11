<?php

namespace BrimleyLabs\Dolt\Tests\Fixtures;

use BrimleyLabs\Dolt\Eloquent\Versioned;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Versioned;

    protected $guarded = [];

    protected $hidden = ['password'];
}
