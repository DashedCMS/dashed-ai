<?php

namespace Dashed\DashedAi\Commands;

use Illuminate\Console\Command;
use Dashed\DashedAi\Jobs\CreateAltTextsForAllMediaItems;

class CreateAltTextsCommand extends Command
{
    protected $signature = 'dashed:create-alt-texts';

    protected $description = 'Generate alt texts for all media items using AI';

    public function handle(): int
    {
        CreateAltTextsForAllMediaItems::dispatch();
        $this->info('Alt text generation jobs dispatched.');

        return self::SUCCESS;
    }
}
