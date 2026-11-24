<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_prefix', 10)->default('IV-');
            $table->string('currency', 3)->default('MYR');
            $table->unsignedSmallInteger('default_payment_terms_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['invoice_prefix', 'currency', 'default_payment_terms_days']);
        });
    }
};
