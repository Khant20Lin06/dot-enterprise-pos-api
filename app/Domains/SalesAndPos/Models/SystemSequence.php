<?php

namespace App\Domains\SalesAndPos\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSequence extends Model
{
    protected $fillable = [
        'prefix',
        'date_key',
        'current_value',
    ];

    protected $casts = [
        'current_value' => 'integer',
    ];
}
