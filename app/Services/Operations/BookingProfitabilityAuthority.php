<?php

namespace App\Services\Operations;

class BookingProfitabilityAuthority
{
    public const PERMISSION = 'booking_profitability.view';

    public function canView(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        foreach ([
            self::PERMISSION,
            'booking-profitability.view',
            'reports.booking-profitability',
            'reports.profitability',
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
            'is_admin',
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
                    if ($this->isPrivilegedRole((string) $role)) {
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
                'Administrator',
                'Admin',
                'Owner',
                'Finance Manager',
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
                $value=$user->{$attribute} ?? null;

                if (
                    is_string($value)
                    && $this->isPrivilegedRole($value)
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
                            $this->isPrivilegedRole(
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
            $this->canView($user),
            403,
            'You are not authorized to view booking profitability.'
        );
    }

    private function isPrivilegedRole(string $value): bool
    {
        $value=strtolower(
            trim(
                str_replace(
                    ['-','_'],
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
                'administrator',
                'admin',
                'owner',
                'finance manager',
            ],
            true
        );
    }
}
