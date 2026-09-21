<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdminReportDelivery extends Model
{
    protected $fillable = ['period_key', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];
}
