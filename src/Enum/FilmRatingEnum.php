<?php

declare(strict_types=1);

namespace Eprofos\ReverseEngineeringBundle\Enum;

/**
 * Enum for film.rating values
 * Generated automatically by ReverseEngineeringBundle.
 */
enum FilmRatingEnum: string
{
    case G     = 'G';
    case PG    = 'PG';
    case PG_13 = 'PG-13';
    case R     = 'R';
    case NC_17 = 'NC-17';
}
