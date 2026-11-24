<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Second line of defence behind the tenant scope: a user may only act on their own company's customers.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer);
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer);
    }

    private function sameTenant(User $user, Customer $customer): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $customer->tenant_id;
    }
}
