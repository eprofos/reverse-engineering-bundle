<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Enum;

/**
 * Enum for test_table.status values
 * Generated automatically by ReverseEngineeringBundle.
 */
enum TestTableStatusEnum: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
    case PENDING  = 'pending';
}
