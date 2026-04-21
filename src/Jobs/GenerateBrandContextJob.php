<?php

namespace Dashed\DashedAi\Jobs;

use Dashed\DashedAi\Facades\Ai;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Classes\WebsiteContentCollector;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedCore\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateBrandContextJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 600;

    public function __construct(
        public ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $siteName = Customsetting::get('site_name') ?: config('app.name');
        $samples = WebsiteContentCollector::collect();

        if (! $samples) {
            $this->notifyUser(
                title: 'Geen website-inhoud gevonden',
                body: 'Er zijn nog geen pagina\'s met metadata om van te analyseren.',
                type: 'warning',
            );

            return;
        }

        $prompt = <<<PROMPT
        Analyseer de onderstaande paginatitels en beschrijvingen van de website "{$siteName}" en genereer twee samenhangende stukken tekst:

        1. **Merkverhaal** (5-8 zinnen): Beschrijf wat het bedrijf doet, welke producten of diensten ze aanbieden, voor wie ze dat doen en wat ze onderscheidt. Dit is het "wat en waarom" van het merk.
        2. **Schrijfstijl** (3-6 zinnen): Beschrijf concreet hoe er geschreven moet worden - toon, formaliteit, zinslengte, gebruik van humor, vaktaal, woorden die wel/niet passen bij het merk. Baseer dit op de toon die al gebruikt wordt in de bestaande teksten. Dit is de "hoe" van het schrijven.

        Beide stukken zijn bedoeld als context voor AI die namens dit merk content genereert, dus schrijf praktisch en direct bruikbaar.

        HUIDIGE PAGINA-INHOUD:
        {$samples}

        Retourneer UITSLUITEND geldig JSON in dit formaat, zonder markdown fences, zonder uitleg:
        {
            "brand_story": "...",
            "writing_style": "..."
        }
        PROMPT;

        $result = Ai::json($prompt);

        if (! $result || empty($result['brand_story']) || empty($result['writing_style'])) {
            Log::warning('GenerateBrandContextJob received empty AI response', [
                'result' => $result,
            ]);

            $this->notifyUser(
                title: 'Genereren mislukt',
                body: 'De AI provider gaf geen bruikbaar antwoord. Probeer het opnieuw of check de AI instellingen.',
                type: 'danger',
            );

            return;
        }

        foreach (Sites::getSites() as $site) {
            Customsetting::set('ai_brand_story', $result['brand_story'], $site['id']);
            Customsetting::set('ai_writing_style', $result['writing_style'], $site['id']);
        }

        $this->notifyUser(
            title: 'Merkverhaal en schrijfstijl gegenereerd',
            body: 'De AI instellingen zijn bijgewerkt. Herlaad de instellingenpagina om ze te zien.',
            type: 'success',
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('GenerateBrandContextJob failed', [
            'error' => $e->getMessage(),
        ]);

        $this->notifyUser(
            title: 'Merkverhaal genereren mislukt',
            body: 'De job kon niet worden voltooid. Check de logs voor meer informatie.',
            type: 'danger',
        );
    }

    private function notifyUser(string $title, string $body, string $type): void
    {
        if ($this->userId === null) {
            return;
        }

        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-sparkles');

        match ($type) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => null,
        };

        $notification->sendToDatabase($user);
    }
}
