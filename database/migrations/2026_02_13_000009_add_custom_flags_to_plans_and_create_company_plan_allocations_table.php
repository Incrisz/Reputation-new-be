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
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('is_active')->index();
            $table->boolean('contact_sales')->default(false)->after('is_custom');
        });

        Schema::create('company_plan_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 255);
            $table->string('company_key', 255)->unique();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->string('allocated_by', 255)->nullable();
            $table->timestamps();

            $table->index('company_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_plan_allocations');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'is_custom',
                'contact_sales',
            ]);
        });
    }
};
