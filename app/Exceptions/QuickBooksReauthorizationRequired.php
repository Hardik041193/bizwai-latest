<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * QuickBooks will not accept this connection until the user reconnects.
 *
 * Raised for an expired refresh token or a 401 from the API. Distinct from other
 * API errors because no retry can succeed: sync jobs fail fast on it instead of
 * backing off and trying again, which would only delay telling the user.
 *
 * Extends RuntimeException so existing catch blocks keep handling it.
 */
class QuickBooksReauthorizationRequired extends RuntimeException
{
}
