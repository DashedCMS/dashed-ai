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
use Dashed\DashedCore\Models\BackgroundTask;
use Dashed\DashedAi\Services\ToneOfVoiceBriefGenerator;
use Dashed\DashedCore\Jobs\Concerns\HandlesQueueFailures;
use Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage;

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

    public const TASK_TYPE = 'ai-tone-of-voice';

    public int $tries = 3;
    public int $timeout = 120;
    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    /**
     * $userId is de beheerder die op de knop drukte, voor het takenicoon van
     * het CMS; null vanuit de scheduler.
     */
    public function __construct(
        public ?string $siteId = null,
        public ?int $userId = null,
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
        $task = $this->task();

        app(ToneOfVoiceBriefGenerator::class)->run($this->siteId);

        $task?->finish(
            __('De Brief is vernieuwd.'),
            rescue(fn () => AiSettingsPage::getUrl(), null, report: false),
            __('Naar de AI-instellingen'),
        );
    }

    public function failed(Throwable $e): void
    {
        // Pas na de laatste poging: tussen de pogingen door blijft de taak
        // op bezig staan en pakt task() hem weer op.
        if (class_exists(BackgroundTask::class)) {
            rescue(fn () => $this->activeTask()?->fail($e), report: false);
        }

        $this->reportFailure($e);
    }

    /**
     * De taak in het takenicoon van dashed-core. Een tweede of derde poging
     * gaat verder op de taak van de eerste in plaats van er een naast te
     * zetten. Het subject is het type met de site, zodat failed() (een verse
     * instantie) hem terugvindt.
     */
    protected function task(): ?BackgroundTask
    {
        if (! class_exists(BackgroundTask::class)) {
            return null;
        }

        return rescue(function (): BackgroundTask {
            if ($task = $this->activeTask()) {
                return $task;
            }

            // De knop stuurt zelf alleen een melding in beeld, dus de
            // beheerder krijgt het resultaat via de taak in de bel.
            $task = BackgroundTask::begin(
                self::TASK_TYPE,
                __('Tone-of-voice Brief genereren'),
                user: $this->userId,
                notify: $this->userId !== null,
            );

            if ($task->exists) {
                $task->update(['subject_type' => self::TASK_TYPE, 'subject_id' => (string) ($this->siteId ?: 'default')]);
            }

            return $task;
        }, null, report: false);
    }

    protected function activeTask(): ?BackgroundTask
    {
        return BackgroundTask::query()
            ->where('type', self::TASK_TYPE)
            ->where('subject_type', self::TASK_TYPE)
            ->where('subject_id', (string) ($this->siteId ?: 'default'))
            ->active()
            ->latest('id')
            ->first();
    }
}
