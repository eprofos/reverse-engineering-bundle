<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Enum;

/**
 * Enum for users.status values
 * Generated automatically by ReverseEngineeringBundle.
 */
enum UserStatusEnum: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
    case PENDING  = 'pending';
}
