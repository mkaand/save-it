<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UsageMetricBucket extends Model
{
    protected $fillable = ['bucket_start', 'provider', 'operation', 'successful', 'error_code', 'country_code', 'count'];

    protected $casts = ['bucket_start' => 'datetime', 'successful' => 'boolean', 'count' => 'integer'];
}
