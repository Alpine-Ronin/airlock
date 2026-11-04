<?php

declare(strict_types=1);

namespace App\Connect;

enum ConnectionState
{
    /** Everything connecting writes is there. */
    case Connected;
    /** The client starts an Airlock other than this one, such as an earlier install. */
    case OtherAirlock;
    /** The client starts this Airlock, but something else connecting writes is missing, such as its shell tool off. */
    case Incomplete;
    /** The client has no Airlock entry, or a file of it cannot be read. */
    case NotConnected;
}
