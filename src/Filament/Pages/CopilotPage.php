<?php

declare(strict_types=1);

namespace Dashed\DashedAi\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Dashed\DashedAi\Facades\Ai;
use Dashed\DashedCore\Classes\Sites;
use Filament\Notifications\Notification;

/**
 * AI Ops-Copilot in het CMS: chat-pagina die natuurlijke-taalvragen over de
 * webshop beantwoordt op basis van een actuele context-snapshot (omzet,
 * voorraad, bestellingen). Read-only — dezelfde logica als de mobiele app.
 */
class CopilotPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'AI-assistent';

    protected static ?string $title = 'AI-assistent';

    protected static ?int $navigationSort = 2;

    protected string $view = 'dashed-ai::filament.pages.copilot';

    /** @var array<int, array{role: string, content: string}> */
    public array $messages = [];

    public string $question = '';

    public static function shouldRegisterNavigation(): bool
    {
        return class_exists(Ai::class) && Ai::hasProvider();
    }

    public function send(): void
    {
        $question = trim($this->question);
        if ($question === '') {
            return;
        }

        if (! Ai::hasProvider()) {
            Notification::make()->title('Er is geen AI-provider gekoppeld')->danger()->send();

            return;
        }

        $history = $this->messages;
        $this->messages[] = ['role' => 'user', 'content' => $question];
        $this->question = '';

        $payload = [];
        foreach ($history as $turn) {
            $payload[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $payload[] = ['role' => 'user', 'content' => $question];

        $answer = rescue(function () use ($payload): string {
            $response = Ai::messages($payload, [
                'system' => $this->systemPrompt(),
                'temperature' => 0.3,
                'max_tokens' => 1024,
            ]);

            return (string) collect($response['content'] ?? [])
                ->where('type', 'text')
                ->pluck('text')
                ->implode("\n");
        }, '', false);

        $answer = trim((string) $answer);
        $this->messages[] = [
            'role' => 'assistant',
            'content' => $answer !== '' ? $answer : '⚠️ De assistent gaf geen antwoord. Probeer het opnieuw.',
        ];
    }

    private function systemPrompt(): string
    {
        $siteId = (string) Sites::getActive();

        $context = '';
        $registryClass = '\Dashed\DashedMobileApi\MobileApiRegistry';
        if (class_exists($registryClass)) {
            $context = (string) rescue(fn () => app($registryClass)->copilotContext($siteId), '', false);
        }

        $siteName = (string) config('app.name', 'de webshop');

        return "Je bent de AI Ops-Copilot in het beheerpaneel van webshop \"{$siteName}\"."
            . ' Je helpt de eigenaar en medewerkers met korte, concrete antwoorden over omzet, bestellingen en voorraad.'
            . ' Vandaag is ' . now()->translatedFormat('l j F Y H:i') . '.'
            . "\n\nGebruik UITSLUITEND onderstaande actuele gegevens. Verzin niets en noem geen cijfers die er niet staan;"
            . ' als iets niet in de gegevens staat, zeg dat eerlijk. Antwoord in het Nederlands, kort en bruikbaar. Bedragen in euro.'
            . "\n\n=== ACTUELE GEGEVENS ===\n" . ($context !== '' ? $context : '(geen gegevens beschikbaar)');
    }
}
