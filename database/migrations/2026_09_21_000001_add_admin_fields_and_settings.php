<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique()->after('id');
            $table->boolean('is_admin')->default(false)->index()->after('password');
        });

        Schema::create('application_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_settings');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropIndex(['is_admin']);
            $table->dropColumn(['username', 'is_admin']);
        });
    }
};
