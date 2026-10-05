<?php

namespace Dashed\DashedAi\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Dashed\DashedAi\Facades\Ai;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedAi\Services\ToneOfVoiceBriefGenerator;
use Dashed\DashedCore\Jobs\Concerns\HandlesQueueFailures;

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
    use HandlesQueueFailures;

    public int $tries = 3;
    public int $timeout = 120;
    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public ?string $siteId = null,
    ) {
        $this->timeout = 600;
    }

    public function handle(): void
    {
        // Zonder gekoppelde AI-provider valt er niets te genereren. Dat is geen fout:
        // de job gooide dan elke dag drie pogingen lang een exception.
        if (! Ai::hasProvider()) {
            Log::info('GenerateToneOfVoiceBriefJob overgeslagen: geen AI-provider gekoppeld', [
                'site_id' => $this->siteId,
            ]);

            return;
        }

        // Geen eigen report() hier: de queue-worker rapporteert een mislukte poging al,
        // en failed() doet dat na de laatste poging.
        app(ToneOfVoiceBriefGenerator::class)->run($this->siteId);
    }

    public function failed(Throwable $e): void
    {
        $this->reportFailure($e);
    }
}
