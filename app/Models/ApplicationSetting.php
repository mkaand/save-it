<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ApplicationSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'encrypted'];

    protected $hidden = ['value'];

    protected $casts = ['encrypted' => 'boolean'];
}
