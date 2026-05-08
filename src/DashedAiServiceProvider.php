<?php

namespace Dashed\DashedAi;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedAi\Commands\CreateAltTextsCommand;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage;
use Dashed\DashedAi\Commands\RefreshToneOfVoiceBriefCommand;
use Dashed\DashedAi\Filament\Pages\Settings\AiToneOfVoiceSettingsPage;

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
        cms()->builder('plugins', [
            new DashedAiPlugin(),
        ]);

        cms()->registerSettingsPage(
            AiSettingsPage::class,
            'AI',
            'sparkles',
            'AI providers, merkverhaal en schrijfstijl'
        );

        cms()->registerSettingsPage(
            AiToneOfVoiceSettingsPage::class,
            'AI tone of voice',
            'sparkles',
            'Stem alle AI-tekstgeneratie af op de toon van jouw merk.'
        );

        cms()->registerSettingsDocs(
            page: AiSettingsPage::class,
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

        cms()->registerSettingsDocs(
            page: AiToneOfVoiceSettingsPage::class,
            title: 'AI tone of voice',
            intro: 'Op deze pagina beheer je de Tone of Voice Brief. De Brief wordt automatisch opgesteld op basis van het echte materiaal op je website (paginas, artikelen, best-selling producten) en wordt vervolgens als context meegestuurd bij elke AI-tekstgeneratie. Zo schrijft elke AI-call in dezelfde stijl.',
            sections: [
                [
                    'heading' => 'Wat doet de Brief?',
                    'body' => 'De Brief beschrijft je doelgroep, merkkarakter, spelling- en stijlregels, ritme, mate van storytelling en humor, en wat juist wel of niet werkt. Deze regels worden automatisch toegevoegd aan elke AI-aanroep, inclusief social posts, content paginas, artikelen, productbeschrijvingen en popup-teksten.',
                ],
                [
                    'heading' => 'Wanneer wordt de Brief vernieuwd?',
                    'body' => 'Een daily scheduler controleert per site of de Brief ouder is dan het ingestelde aantal dagen. Is dat zo, dan wordt de Brief automatisch opnieuw gegenereerd op basis van het meest actuele materiaal. Je kunt de Brief ook handmatig vernieuwen via de knop rechtsboven.',
                ],
                [
                    'heading' => 'Wat is de override?',
                    'body' => 'Wil je zelf precies bepalen hoe de AI schrijft? Vul dan de override in. Zolang er een override staat, gebruikt de AI uitsluitend die tekst en negeert de gegenereerde Brief. Maak het veld leeg om weer terug te schakelen naar de automatische Brief.',
                ],
            ],
            fields: [
                'Brief (gegenereerd)' => 'Read-only weergave van de laatst gegenereerde Brief. Niet bewerkbaar, gebruik de override of regenereer de Brief.',
                'Brief override' => 'Handmatige Brief die de gegenereerde Brief overschrijft. Laat leeg om de gegenereerde Brief te gebruiken.',
                'Maximale ouderdom (dagen)' => 'Hoeveel dagen de Brief mag bestaan voordat de scheduler hem automatisch opnieuw genereert. Default: 30.',
            ],
            tips: [
                'Genereer de Brief opnieuw na een rebrand of grote contentwijziging zodat AI direct in de nieuwe stem schrijft.',
                'Gebruik de override alleen als de gegenereerde Brief consequent niet aansluit. In de meeste gevallen is een nieuwe generatie voldoende.',
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
