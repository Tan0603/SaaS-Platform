<?php

namespace Database\Seeders;

use App\Actions\Invoices\VoidInvoiceAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Demo invoices per company, spread evenly over the last 12 months (the big performance seed is a later phase). */
    public const INVOICES_PER_TENANT = 60;

    public const CUSTOMERS_PER_TENANT = 15;

    /**
     * Demo companies. Each gets one admin user; the tenant is found from the user's tenant_id at login.
     */
    public function run(): void
    {
        foreach ([
            'acme' => 'Acme Sdn Bhd',
            'globex' => 'Globex Sdn Bhd',
        ] as $id => $name) {
            $tenant = Tenant::query()->create(['id' => $id, 'name' => $name]);

            // Inside run() tenancy is initialised, so BelongsToTenant fills tenant_id.
            $tenant->run(function () use ($id) {
                User::factory()->create([
                    'name' => 'Admin',
                    'email' => "admin@{$id}.test",
                ]);

                $this->seedDemoData();
            });
        }
    }

    /**
     * Customers plus invoices and payments over the last 12 months. Everything goes through the factories (consistent
     * totals) and the real void action; payment dates are then spread out so that they are on or after the issue date
     * and never in the future.
     */
    private function seedDemoData(): void
    {
        $customers = Customer::factory()->count(self::CUSTOMERS_PER_TENANT)->create();
        $count = self::INVOICES_PER_TENANT;

        for ($i = 0; $i < $count; $i++) {
            // Oldest first, one invoice every ~6 days, so every one of the 12 months has invoices.
            $daysAgo = max(0, intdiv(($count - 1 - $i) * 355, $count - 1) + random_int(0, 3));
            $issueDate = today()->subDays($daysAgo);
            $terms = [14, 30, 30, 45][random_int(0, 3)];

            $factory = Invoice::factory()
                ->withItems(random_int(1, 4))
                ->state([
                    'customer_id' => $customers[$this->skewedIndex(count: $customers->count())]->id,
                    'issue_date' => $issueDate,
                    'due_date' => $issueDate->copy()->addDays($terms),
                ]);

            $scenario = $this->scenario(old: $daysAgo > 90);
            $invoice = $this->applyScenario($factory, $scenario)->create();

            if ($scenario === 'void') {
                app(VoidInvoiceAction::class)->execute($invoice, 'Demo: raised by mistake');
            }

            $this->spreadPayments($invoice);
        }

        // One archived customer keeps their invoices (they still show in the charts and reports).
        $customers->last()->delete();
    }

    /** Older invoices are mostly settled, recent ones mostly open. */
    private function scenario(bool $old): string
    {
        $roll = random_int(1, 100);
        $weights = $old
            ? ['paid' => 60, 'partial' => 12, 'unpaid' => 18, 'draft' => 4, 'void' => 6]
            : ['paid' => 25, 'partial' => 20, 'unpaid' => 45, 'draft' => 6, 'void' => 4];

        foreach ($weights as $scenario => $weight) {
            if (($roll -= $weight) <= 0) {
                return $scenario;
            }
        }

        return 'unpaid';
    }

    /**
     * @param  Factory<Invoice>  $factory
     * @return Factory<Invoice>
     */
    private function applyScenario(Factory $factory, string $scenario): Factory
    {
        return match ($scenario) {
            'paid' => $factory->paid(),
            'partial' => $factory->partial(),
            'draft' => $factory,
            default => $factory->issued(),   // unpaid, and void (voided right after creation)
        };
    }

    /** A few customers get most of the invoices, so "top customers" has a meaningful ranking. */
    private function skewedIndex(int $count): int
    {
        $r = random_int(0, 999) / 1000;

        return (int) floor($count * $r * $r);
    }

    /**
     * The factory dates every payment on the issue date. Move them to issue date + a few days (later payments after
     * earlier ones), but never past today. Totals are unaffected, so amount_paid_cents / status stay consistent.
     */
    private function spreadPayments(Invoice $invoice): void
    {
        $date = $invoice->issue_date->copy();

        foreach ($invoice->payments as $payment) {
            $date = $date->addDays(random_int(0, 25));
            $paidAt = $date->greaterThan(today()) ? today() : $date->copy();

            $payment->forceFill(['paid_at' => $paidAt])->save();
        }
    }
}
