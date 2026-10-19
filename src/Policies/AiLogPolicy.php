<?php

namespace Dashed\DashedAi\Policies;

use Dashed\DashedCore\Policies\BaseResourcePolicy;

class AiLogPolicy extends BaseResourcePolicy
{
    protected function resourceName(): string
    {
        return 'AiLog';
    }
}
