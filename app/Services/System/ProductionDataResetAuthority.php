<?php

namespace App\Services\System;

/**
 * ERP-10.31.72
 *
 * Destructive production transaction reset authority.
 *
 * This is intentionally stricter than ordinary Admin access. Only a positively
 * identified Super Admin / Owner or an explicit production-reset permission
 * may use the tool.
 */
class ProductionDataResetAuthority
{
    public function canExecute(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        foreach ([
            'system.production-reset',
            'system.production_data_reset',
            'production-reset.execute',
            'production_data_reset.execute',
        ] as $ability) {
            try {
                if (
                    method_exists($user, 'can')
                    && $user->can($ability)
                ) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        foreach ([
            'is_super_admin',
            'super_admin',
            'is_owner',
        ] as $attribute) {
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
                    if ($this->isAllowedRole((string) $role)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
        }

        if (method_exists($user, 'hasRole')) {
            foreach ([
                'Super Admin',
                'SuperAdmin',
                'Owner',
            ] as $role) {
                try {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                } catch (\Throwable) {
                }
            }
        }

        foreach ([
            'role_name',
            'role',
            'user_type',
            'type',
        ] as $attribute) {
            try {
                $value = $user->{$attribute} ?? null;

                if (
                    is_string($value)
                    && $this->isAllowedRole($value)
                ) {
                    return true;
                }

                if (is_object($value)) {
                    foreach ([
                        'name',
                        'slug',
                        'code',
                        'title',
                    ] as $key) {
                        if (
                            $this->isAllowedRole(
                                (string) ($value->{$key} ?? '')
                            )
                        ) {
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
            $this->canExecute($user),
            403,
            'Only Super Admin / Owner may use Production Transaction Reset.'
        );
    }

    private function isAllowedRole(string $value): bool
    {
        $value = strtolower(
            trim(
                str_replace(
                    ['-', '_'],
                    ' ',
                    $value
                )
            )
        );

        return in_array(
            $value,
            [
                'super admin',
                'superadmin',
                'owner',
            ],
            true
        );
    }
}
