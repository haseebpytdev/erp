<?php

namespace App\Services\Administration;

class ErpUserManagementAuthority
{
    public function canManage(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        foreach ([
            'administration.users.manage',
            'users.manage',
            'user-management.manage',
            'administration.manage',
        ] as $ability) {
            try {
                if (method_exists($user, 'can') && $user->can($ability)) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        foreach (['is_super_admin', 'super_admin', 'is_administrator'] as $attribute) {
            try {
                if ((bool) ($user->{$attribute} ?? false)) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        if (method_exists($user, 'hasRole')) {
            foreach (['Super Admin', 'SuperAdmin', 'Administrator'] as $role) {
                try {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                } catch (\Throwable) {
                }
            }
        }

        try {
            if (method_exists($user, 'getRoleNames')) {
                foreach ((array) $user->getRoleNames()->all() as $role) {
                    if ($this->isManagementRole((string) $role)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
        }

        foreach (['role_name', 'role', 'user_type', 'type'] as $attribute) {
            try {
                $value = $user->{$attribute} ?? null;

                if (is_string($value) && $this->isManagementRole($value)) {
                    return true;
                }

                if (is_object($value)) {
                    foreach (['name', 'slug', 'code', 'title'] as $key) {
                        if ($this->isManagementRole((string) ($value->{$key} ?? ''))) {
                            return true;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }

    public function authorize(mixed $user): void
    {
        abort_unless(
            $this->canManage($user),
            403,
            'You do not have permission to manage ERP user accounts.'
        );
    }

    private function isManagementRole(string $role): bool
    {
        $role = strtolower(trim(str_replace(['-', '_'], ' ', $role)));

        return in_array($role, ['super admin', 'superadmin', 'administrator'], true);
    }
}
