<?php

namespace Dashed\DashedAi\Commands;

use Dashed\DashedAi\Jobs\CreateAltTextsForAllMediaItems;
use Illuminate\Console\Command;

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
