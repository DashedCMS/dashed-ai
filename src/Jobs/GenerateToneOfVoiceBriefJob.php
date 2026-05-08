<?php

namespace Dashed\DashedAi\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedAi\Services\ToneOfVoiceBriefGenerator;

/**
 * Genereert async een Tone of Voice Brief voor 1 site. Wordt gedispatched
 * vanuit de Filament-pagina (knop "Vernieuw Brief nu") en vanuit de daily
 * scheduler-command.
 */
class GenerateToneOfVoiceBriefJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public ?string $siteId = null,
    ) {
    }

    /**
     * Exponential backoff: 1 min, 5 min, 15 min.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        try {
            app(ToneOfVoiceBriefGenerator::class)->run($this->siteId);
        } catch (Throwable $e) {
            Log::warning('GenerateToneOfVoiceBriefJob faalde', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            report($e);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('GenerateToneOfVoiceBriefJob definitief mislukt', [
            'site_id' => $this->siteId,
            'error' => $e->getMessage(),
        ]);
    }
}
