<?php

namespace App\Enums;

enum IdentificationType: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
    case DrivingLicense = 'driving_license';

    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID',
            self::Passport => 'Passport',
            self::DrivingLicense => 'Driving License',
        };
    }
}
