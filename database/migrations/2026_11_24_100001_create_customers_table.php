<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('registration_no')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
            // Email is unique per company (NULLs are allowed many times in PostgreSQL).
            $table->unique(['tenant_id', 'email']);
        });

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_payment_terms_days_check CHECK (payment_terms_days BETWEEN 0 AND 365)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
