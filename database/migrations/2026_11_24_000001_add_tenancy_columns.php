<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        Schema::table('users', function (Blueprint $table) {
            // Every user belongs to exactly one tenant (company); deleting a tenant deletes its users.
            $table->string('tenant_id')->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // PostgreSQL does not index foreign keys automatically; needed for per-tenant user lookups and cascade deletes.
            $table->index('tenant_id');

            // One site, one login page: an email identifies exactly one user (and so one company).
            // users.email keeps the plain UNIQUE constraint from the create_users_table migration.
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
