<?php

namespace Dashed\DashedAi\Jobs;

use Illuminate\Bus\Queueable;
use Dashed\DashedAi\Facades\Ai;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateAiContent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 5;

    public $timeout = 1200;

    public function __construct(
        public $model,
        public string $column,
        public string $content,
        public string $locale,
    ) {
    }

    public function handle(): void
    {
        $description = Ai::text($this->content);

        if ($description) {
            $this->model->setTranslation($this->column, $this->locale, $description);
            $this->model->saveQuietly();
        }
    }
}
