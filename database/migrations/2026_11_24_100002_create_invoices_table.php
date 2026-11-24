<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // A customer is archived (soft deleted), never hard deleted, so its invoices are protected.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('number')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('state')->default('draft');
            $table->string('status')->default('unpaid');
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('amount_paid_cents')->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'state', 'status', 'due_date']);
            $table->index(['tenant_id', 'issue_date']);
            $table->index(['tenant_id', 'customer_id']);
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_state_check CHECK (state IN ('draft','issued','void'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('unpaid','partial','paid'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_number_required_check CHECK (state = 'draft' OR number IS NOT NULL)");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amount_paid_check CHECK (amount_paid_cents BETWEEN 0 AND total_cents)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_due_date_check CHECK (due_date >= issue_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
