<?php

declare(strict_types=1);

namespace Concordance\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Exception thrown when an error occurs during service resolution.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
