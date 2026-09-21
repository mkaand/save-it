<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_metric_buckets', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('bucket_start');
            $table->string('provider', 32)->default('unknown');
            $table->string('operation', 32);
            $table->boolean('successful');
            $table->string('error_code', 80)->default('');
            $table->string('country_code', 2)->default('ZZ');
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();
            $table->unique(['bucket_start', 'provider', 'operation', 'successful', 'error_code', 'country_code'], 'usage_metric_bucket_unique');
            $table->index(['bucket_start', 'operation']);
        });

        Schema::create('admin_report_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('period_key', 80)->unique();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_report_deliveries');
        Schema::dropIfExists('usage_metric_buckets');
    }
};
