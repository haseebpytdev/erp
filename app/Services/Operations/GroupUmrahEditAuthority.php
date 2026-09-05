<?php

namespace App\Services\Operations;

/**
 * ERP-10.31.0
 *
 * Conservative resolver for the explicit "Reopen for Editing" authority.
 * If an installation-specific role/permission cannot be positively identified,
 * access is denied rather than granted.
 */
class GroupUmrahEditAuthority
{
    public function canReopen(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        foreach ([
            'group-umrah.reopen',
            'group_umrah.reopen',
            'bookings.reopen',
            'booking.reopen',
            'bookings.override',
        ] as $ability) {
            try {
                if (method_exists($user, 'can') && $user->can($ability)) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        foreach (['is_super_admin', 'is_admin', 'super_admin'] as $attribute) {
            try {
                if ((bool) ($user->{$attribute} ?? false)) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        try {
            if (method_exists($user, 'getRoleNames')) {
                foreach ((array) $user->getRoleNames()->all() as $role) {
                    if ($this->isAdminRole((string) $role)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
        }

        if (method_exists($user, 'hasRole')) {
            foreach (['Super Admin', 'SuperAdmin', 'Administrator', 'Admin'] as $role) {
                try {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                } catch (\Throwable) {
                }
            }
        }

        foreach (['role_name', 'role', 'user_type', 'type'] as $attribute) {
            try {
                $value = $user->{$attribute} ?? null;

                if (is_string($value) && $this->isAdminRole($value)) {
                    return true;
                }

                if (is_object($value)) {
                    foreach (['name', 'slug', 'code', 'title'] as $key) {
                        if ($this->isAdminRole((string) ($value->{$key} ?? ''))) {
                            return true;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        try {
            $roles = $user->roles ?? null;

            if (is_iterable($roles)) {
                foreach ($roles as $role) {
                    if (is_string($role) && $this->isAdminRole($role)) {
                        return true;
                    }

                    if (is_object($role)) {
                        foreach (['name', 'slug', 'code', 'title'] as $key) {
                            if ($this->isAdminRole((string) ($role->{$key} ?? ''))) {
                                return true;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function isAdminRole(string $value): bool
    {
        $value = strtolower(trim(str_replace(['-', '_'], ' ', $value)));

        return in_array($value, [
            'super admin',
            'superadmin',
            'administrator',
            'admin',
        ], true);
    }
}
