<?php

namespace Afaya\EdgeTTS\Exception;

use RuntimeException;

/**
 * Base class of the errors of the service. Catching RuntimeException
 * still catches every one of them.
 */
class EdgeTTSException extends RuntimeException
{
}
