<?php

namespace Dashed\DashedAi\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedAi\Jobs\GenerateToneOfVoiceBriefJob;

/**
 * Daily-runner: dispatcht voor elke site een GenerateToneOfVoiceBriefJob als
 * de Brief ouder is dan `ai_tone_of_voice_max_age_days` en er geen handmatige
 * override is ingesteld. Met `--force` worden beide checks genegeerd.
 */
class RefreshToneOfVoiceBriefCommand extends Command
{
    protected $signature = 'dashed:refresh-tone-of-voice-brief {--force : Forceer regeneratie, negeer max_age_days en eventuele handmatige override}';

    protected $description = 'Verfris (waar nodig) de Tone of Voice Brief per site';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $sites = [];
        if (class_exists(Sites::class) && method_exists(Sites::class, 'getSites')) {
            $sites = Sites::getSites();
        }

        if (empty($sites)) {
            $this->refreshForSite(null, $force);

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $siteId = isset($site['id']) ? (string) $site['id'] : null;
            $this->refreshForSite($siteId, $force);
        }

        return self::SUCCESS;
    }

    private function refreshForSite(?string $siteId, bool $force): void
    {
        $label = $siteId ?: 'default';

        $override = trim((string) Customsetting::get('ai_tone_of_voice_brief_manual_override', $siteId));
        if (! $force && $override !== '') {
            $this->line("[$label] Handmatige override staat aan, sla over.");

            return;
        }

        $maxAgeDays = (int) (Customsetting::get('ai_tone_of_voice_max_age_days', $siteId) ?: 30);
        if ($maxAgeDays < 1) {
            $maxAgeDays = 30;
        }

        $generatedAtRaw = (string) Customsetting::get('ai_tone_of_voice_generated_at', $siteId);

        if (! $force && $generatedAtRaw !== '') {
            try {
                $generatedAt = Carbon::parse($generatedAtRaw);
                if ($generatedAt->copy()->addDays($maxAgeDays)->isFuture()) {
                    $this->line("[$label] Brief is nog vers (jonger dan {$maxAgeDays} dagen), sla over.");

                    return;
                }
            } catch (\Throwable $e) {
                // Kan timestamp niet parsen, dispatch alsnog.
            }
        }

        GenerateToneOfVoiceBriefJob::dispatch($siteId);
        $this->info("[$label] Job voor het verfrissen van de Brief gedispatched.");
    }
}
