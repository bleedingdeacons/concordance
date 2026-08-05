<?php

declare(strict_types=1);

namespace Concordance\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Exception thrown when a requested service is not found in the container.
 */
class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
