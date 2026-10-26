<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileEmailChange extends Model
{
    protected $fillable = ['user_id', 'new_email', 'code_hash', 'expires_at', 'attempts'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'attempts' => 'integer'];
    }
}
