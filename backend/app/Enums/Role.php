<?php

namespace App\Enums;

enum Role: string
{
    case Administrator = 'administrator';
    case ComplianceStaff = 'compliance_staff';
    case GeneralEmployee = 'general_employee';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
