<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Exceptions;

use RuntimeException;

/**
 * Base for every exception this package throws.
 *
 * Extends RuntimeException so existing catch blocks keep working.
 */
class WebpBoltException extends RuntimeException
{
}
