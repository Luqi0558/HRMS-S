<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

/**
 * A tenant is one company using Orbit HRMS. Each gets its own database, so
 * employee, attendance and payroll rows can never leak between companies —
 * isolation is enforced by the connection rather than by a where clause every
 * query has to remember.
 *
 * Columns listed in getCustomColumns() are real columns on the central
 * `tenants` table. Everything else is stashed in the JSON `data` column by
 * stancl, so company-level preferences can be added without a migration.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'status',
        ];
    }
}
