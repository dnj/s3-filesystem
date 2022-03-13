<?php

namespace dnj\Filesystem\S3\Exceptions;

use dnj\Filesystem\Exceptions\IOException;
use dnj\Filesystem\S3\Contracts\IException;

/**
 * @template T
 * @extends IOException<T>
 */
class InvalidAccessKeyException extends IOException implements IException
{
}
