<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Exception;

/**
 * Base class for every exception the plugin throws internally.
 *
 * None of these escape to WordPress or to an importer: the public entry
 * points catch them and fail open.
 */
class EchoSkipSeenException extends \RuntimeException
{
}
