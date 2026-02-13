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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_name', 100);
            $table->integer('limit_value')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_name']);
        });

        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->enum('status', ['active', 'cancelled', 'suspended'])->default('active')->index();
            $table->timestamp('started_at');
            $table->timestamp('renews_at')->nullable();
            $table->string('payment_method', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('usage_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('feature_name', 100);
            $table->unsignedInteger('usage_count')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();

            $table->unique(['user_id', 'feature_name', 'period_start', 'period_end'], 'usage_tracking_period_unique');
        });

        Schema::create('audit_queue_limits', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('concurrent_audits_allowed')->default(1);
            $table->unsignedInteger('current_running_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_queue_limits');
        Schema::dropIfExists('usage_tracking');
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
