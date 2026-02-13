<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_provider', 32)->default('email')->after('email');
            $table->string('google_id')->nullable()->unique()->after('registration_provider');
            $table->string('avatar_url')->nullable()->after('google_id');
            $table->timestamp('last_login_at')->nullable()->after('avatar_url');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->text('last_login_user_agent')->nullable()->after('last_login_ip');
            $table->string('last_login_provider', 32)->nullable()->after('last_login_user_agent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn([
                'registration_provider',
                'google_id',
                'avatar_url',
                'last_login_at',
                'last_login_ip',
                'last_login_user_agent',
                'last_login_provider',
            ]);
        });
    }
};

