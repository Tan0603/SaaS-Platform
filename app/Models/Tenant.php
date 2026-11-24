<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    /** Tenant ids are readable string slugs (acme, globex) we provide ourselves. */
    public $incrementing = false;

    protected $keyType = 'string';

    public function getIncrementing()
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }

    public function shouldGenerateId(): bool
    {
        return false;
    }

    /**
     * Real columns on the tenants table (everything else goes into the `data` JSON column).
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name'];
    }
}
