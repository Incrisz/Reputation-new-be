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
        Schema::create('user_plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active')->index();
            $table->enum('billing_interval', ['monthly', 'annual'])->default('monthly');
            $table->string('source', 50)->default('stripe');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->index();
            $table->string('stripe_customer_id', 255)->nullable();
            $table->string('stripe_subscription_id', 255)->nullable()->index();
            $table->string('stripe_checkout_session_id', 255)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'plan_id', 'status', 'expires_at'], 'entitlements_lookup_index');
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('user_subscription_id')->nullable()->constrained('user_subscriptions')->nullOnDelete();
            $table->string('provider', 50)->default('stripe');
            $table->enum('transaction_type', ['charge', 'refund', 'credit'])->default('charge');
            $table->string('provider_transaction_id', 255)->nullable()->index();
            $table->string('provider_session_id', 255)->nullable()->index();
            $table->string('provider_subscription_id', 255)->nullable()->index();
            $table->string('provider_customer_id', 255)->nullable();
            $table->enum('billing_interval', ['monthly', 'annual'])->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['paid', 'pending', 'failed', 'refunded'])->default('paid')->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'payment_transactions_user_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('user_plan_entitlements');
    }
};
