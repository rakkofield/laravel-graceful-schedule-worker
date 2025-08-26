<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\LineFormatter;

class Formatter extends LineFormatter
{
    public const SIMPLE_FORMAT = "%message%\n";
}
