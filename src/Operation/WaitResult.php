<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

enum WaitResult: string
{
    case Released = 'released';
    case Timeout = 'timeout';
}
