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
        Schema::create('audit_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->string('business_name', 255)->nullable()->index();
            $table->string('website')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('place_id', 255)->nullable();
            $table->boolean('skip_places')->default(false);
            $table->unsignedSmallInteger('reputation_score')->nullable();
            $table->timestamp('scan_date')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_runs');
    }
};

