<?php

namespace Medalink\AppVersion\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Medalink\AppVersion\Concerns\HasReleaseNoteReadState;

class User extends Authenticatable
{
    use HasReleaseNoteReadState;

    protected $table = 'users';

    protected $guarded = [];

    public static function make(string $email = 'user@example.test'): self
    {
        return self::query()->create(['name' => 'Fixture User', 'email' => $email]);
    }
}
