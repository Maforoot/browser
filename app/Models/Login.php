<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Login extends Model
{
    use HasFactory;

    protected $fillable = [
        'username',
        'password',
        'user_id'
    ];
    public function users()
    {
        return $this->belongsTo(User::class, 'id', 'user_id');
    }
}
