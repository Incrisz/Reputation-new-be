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
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->enum('billing_interval', ['monthly', 'annual'])
                ->default('monthly')
                ->after('payment_method');
            $table->string('stripe_customer_id', 255)->nullable()->after('billing_interval');
            $table->string('stripe_subscription_id', 255)->nullable()->after('stripe_customer_id');
            $table->string('stripe_checkout_session_id', 255)->nullable()->after('stripe_subscription_id');
            $table->timestamp('last_payment_at')->nullable()->after('stripe_checkout_session_id');

            $table->index('stripe_customer_id');
            $table->index('stripe_subscription_id');
            $table->index('stripe_checkout_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropIndex(['stripe_checkout_session_id']);

            $table->dropColumn([
                'billing_interval',
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_checkout_session_id',
                'last_payment_at',
            ]);
        });
    }
};
