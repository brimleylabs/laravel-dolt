<?php

namespace BrimleyLabs\Dolt\Tests\Fixtures;

use BrimleyLabs\Dolt\Eloquent\Versioned;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use Versioned;

    protected $guarded = [];
}
