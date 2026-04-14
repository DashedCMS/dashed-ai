<?php

namespace Dashed\DashedAi;

use Spatie\LaravelPackageTools\Package;
use Dashed\DashedAi\Commands\CreateAltTextsCommand;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedAiServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ai';

    public function configurePackage(Package $package): void
    {
        $package
            ->hasConfigFile(['dashed-ai'])
            ->hasCommands([
                CreateAltTextsCommand::class,
            ])
            ->name(self::$name);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(AiManager::class, fn () => new AiManager());
    }

    public function bootingPackage(): void
    {
        cms()->builder('plugins', [
            new DashedAiPlugin(),
        ]);

        cms()->registerSettingsPage(
            \Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage::class,
            'AI',
            'sparkles',
            'AI providers, merkverhaal en schrijfstijl'
        );

        cms()->registerSettingsDocs(
            page: \Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage::class,
            title: 'AI instellingen',
            intro: 'Op deze pagina configureer je hoe AI in jouw omgeving werkt. Je kiest een standaard AI provider, beschrijft je merkverhaal en schrijfstijl en koppelt FAL.ai voor beelden. Ook zet je in dat alt-teksten voor nieuwe afbeeldingen automatisch worden gegenereerd. Per geactiveerde AI provider verschijnen hieronder bovendien de bijbehorende API credentials.',
            sections: [
                [
                    'heading' => 'Wat kun je hier instellen?',
                    'body' => 'Je kiest welke AI provider standaard wordt gebruikt en geeft de AI context mee via je merkverhaal en schrijfstijl. Verder activeer je automatische alt-teksten en vul je per provider de API sleutels in.',
                ],
                [
                    'heading' => 'Hoe vul je merkverhaal en schrijfstijl in?',
                    'body' => <<<MARKDOWN
1. Schrijf in een paar alinea\'s wie je bent, wat je doet en voor wie. Behandel het als een korte introductie aan een nieuwe medewerker.
2. Beschrijf bij schrijfstijl de toon (formeel, informeel, speels, zakelijk) en eventuele woorden die je juist wel of niet wil gebruiken.
3. Geef voorbeelden van zinnen of uitdrukkingen die typisch voor je merk zijn.
4. Sla op en laat de AI een proefpost maken om te zien of de toon klopt. Pas de teksten aan tot je tevreden bent.
MARKDOWN,
                ],
                [
                    'heading' => 'Hoe koppel je een AI provider?',
                    'body' => <<<MARKDOWN
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
                'Merkverhaal' => 'Het verhaal van je merk: wie je bent, wat je doet en waar je voor staat. De AI gebruikt deze tekst als achtergrond bij alles wat hij voor je schrijft, dus hoe completer en eerlijker, hoe beter de output.',
                'Schrijfstijl' => 'Een beschrijving van de toon en woordkeuze die je wil terugzien in AI teksten. Denk aan formeel of informeel, kort en bondig of juist uitgebreid, en welke woorden je juist wel of niet gebruikt.',
                'Automatische alt-teksten' => 'Aan zorgt dat de AI automatisch een alt-tekst genereert voor elke nieuw geuploade afbeelding. Werkt op dit moment alleen in het Nederlands en alleen met een AI provider die afbeeldingen kan herkennen.',
                'FAL.ai API sleutel' => 'API sleutel van FAL.ai voor het genereren van beelden via AI. Maak een account op fal.ai en kopieer de sleutel uit het API Keys gedeelte. Zonder deze sleutel kun je geen beelden laten genereren.',
            ],
            tips: [
                'Een goed merkverhaal en duidelijke schrijfstijl maken het verschil tussen generieke AI tekst en content die echt van jou voelt.',
                'Activeer automatische alt-teksten alleen als de meeste van je afbeeldingen Nederlandstalig gebruikt worden. Voor andere talen werkt het nog niet betrouwbaar.',
                'Houd je API sleutels geheim en deel ze nooit per e-mail of chat.',
            ],
        );
    }
}
