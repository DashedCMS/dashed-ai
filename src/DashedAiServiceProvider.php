<?php

namespace Dashed\DashedAi;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedAi\Commands\CreateAltTextsCommand;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage;
use Dashed\DashedAi\Commands\RefreshToneOfVoiceBriefCommand;

class DashedAiServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ai';

    public function configurePackage(Package $package): void
    {
        $package
            ->hasConfigFile(['dashed-ai'])
            ->hasCommands([
                CreateAltTextsCommand::class,
                RefreshToneOfVoiceBriefCommand::class,
            ])
            ->name(self::$name);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(AiManager::class, fn () => new AiManager());
    }

    public function bootingPackage(): void
    {
        if (method_exists(cms(), 'registerIntegration')) {
            cms()->registerIntegration([
                'slug' => 'ai_fal',
                'label' => 'Fal.ai',
                'icon' => 'heroicon-o-sparkles',
                'category' => 'ai',
                'settings_page' => \Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage::class,
                'health_check' => fn (?string $siteId = null) => \Dashed\DashedCore\Integrations\IntegrationHealth::fromSettings(['fal_api_key'], $siteId, 'API key ontbreekt'),
                'package' => 'dashed-ai',
            ]);
        }

        cms()->builder('plugins', [
            new DashedAiPlugin(),
        ]);

        if (method_exists(cms(), 'registerSetting')) {
            cms()->registerSetting(
                key: 'ai_default_provider',
                type: 'string',
                default: null,
                package: 'dashed-ai',
                label: 'Standaard AI provider',
                description: 'Identifier van de AI-provider die AiManager standaard gebruikt.',
            );
        }

        cms()->registerSettingsPage(
            AiSettingsPage::class,
            'AI',
            'sparkles',
            'AI providers en tone-of-voice Brief'
        );

        cms()->registerSettingsDocs(
            page: AiSettingsPage::class,
            title: 'AI instellingen',
            intro: 'Op deze pagina configureer je hoe AI in jouw omgeving werkt. Je kiest een standaard AI provider, beheert de tone-of-voice Brief (de enige merk-context die meegaat in elke AI-aanroep) en koppelt FAL.ai voor beelden. Ook zet je in dat alt-teksten voor nieuwe afbeeldingen automatisch worden gegenereerd. Per geactiveerde AI provider verschijnen hieronder bovendien de bijbehorende API credentials.',
            sections: [
                [
                    'heading' => 'Wat kun je hier instellen?',
                    'body' => 'Je kiest welke AI provider standaard wordt gebruikt, beheert de tone-of-voice Brief, activeert automatische alt-teksten en vult per provider de API sleutels in.',
                ],
                [
                    'heading' => 'Hoe werkt de tone-of-voice Brief?',
                    'body' => <<<'MARKDOWN'
1. Klik op **Vernieuw tone-of-voice Brief** om de Brief automatisch te laten genereren op basis van pages, artikelen en producten van deze site.
2. Lees de gegenereerde Brief en gebruik **Handmatige override** als je de tekst zelf wil bijschaven of vervangen. Een ingevulde override wordt door de scheduler nooit overschreven.
3. De scheduler ververst de Brief elke 30 dagen (of jouw eigen maximum-aantal-dagen). Zo blijft de toon meegroeien met je site zonder dat je er handmatig naar hoeft te kijken.
MARKDOWN,
                ],
                [
                    'heading' => 'Hoe koppel je een AI provider?',
                    'body' => <<<'MARKDOWN'
1. Maak een account aan bij de provider van je keuze (bijvoorbeeld OpenAI of Anthropic).
2. Ga in het dashboard van die provider naar de sectie voor API keys.
3. Maak een nieuwe sleutel aan en kopieer hem direct, vaak kun je hem later niet meer inzien.
4. Plak de sleutel in het bijbehorende veld onderaan deze pagina.
5. Selecteer de provider in **Standaard AI provider** als je wil dat deze automatisch wordt gebruikt voor nieuwe AI taken.
MARKDOWN,
                ],
            ],
            fields: [
                'Standaard AI provider' => 'De AI provider die wordt gekozen als bij een AI taak niet expliciet een andere wordt gevraagd. Zorg dat je voor deze provider ook geldige credentials hebt ingevuld.',
                'Huidige Brief' => 'De door AI gegenereerde tone-of-voice Brief op basis van je site-inhoud. Read-only. Klik bovenaan op "Vernieuw tone-of-voice Brief" om hem opnieuw te laten genereren.',
                'Handmatige override' => 'Vul deze in om de gegenereerde Brief volledig te overrulen. Wanneer ingevuld wordt deze tekst gebruikt in elke AI-aanroep en negeert de scheduler deze site bij de automatische refresh.',
                'Maximale ouderdom van de Brief' => 'Aantal dagen voordat de daily-scheduler de Brief opnieuw genereert. Standaard 30. Heeft geen effect als er een handmatige override is ingevuld.',
                'Automatische alt-teksten' => 'Aan zorgt dat de AI automatisch een alt-tekst genereert voor elke nieuw geuploade afbeelding. Werkt op dit moment alleen in het Nederlands en alleen met een AI provider die afbeeldingen kan herkennen.',
                'FAL.ai API sleutel' => 'API sleutel van FAL.ai voor het genereren van beelden via AI. Maak een account op fal.ai en kopieer de sleutel uit het API Keys gedeelte. Zonder deze sleutel kun je geen beelden laten genereren.',
            ],
            tips: [
                'Een actuele tone-of-voice Brief is het verschil tussen generieke AI tekst en content die echt van jou voelt. Vernieuw hem zodra je site-inhoud noemenswaardig wijzigt.',
                'Activeer automatische alt-teksten alleen als de meeste van je afbeeldingen Nederlandstalig gebruikt worden. Voor andere talen werkt het nog niet betrouwbaar.',
                'Houd je API sleutels geheim en deel ze nooit per e-mail of chat.',
            ],
        );

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command('dashed:refresh-tone-of-voice-brief')
                ->daily()
                ->withoutOverlapping();
        });
    }
}
