<?php

namespace App\Domain\Company\Enums;

enum CompanyStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
