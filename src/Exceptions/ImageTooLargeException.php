<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Exceptions;

/** The image exceeds the pixel ceiling and would risk an uncatchable fatal. */
class ImageTooLargeException extends WebpBoltException
{
}
