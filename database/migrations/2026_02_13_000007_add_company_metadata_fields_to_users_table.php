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
            $table->string('industry', 100)->nullable()->after('company');
            $table->string('company_size', 100)->nullable()->after('industry');
            $table->string('website', 255)->nullable()->after('company_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'industry',
                'company_size',
                'website',
            ]);
        });
    }
};
