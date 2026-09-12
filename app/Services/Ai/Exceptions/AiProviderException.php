<?php

namespace App\Services\Ai\Exceptions;

use Exception;

/**
 * Thrown by AI providers on any failure. The message is always safe to show
 * to the end user — never leak API keys, stack traces, or raw provider
 * response bodies into it. Real error detail goes to Log::error() instead.
 */
class AiProviderException extends Exception {}
