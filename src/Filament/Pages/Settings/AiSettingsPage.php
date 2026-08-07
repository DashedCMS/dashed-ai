<?php

namespace Dashed\DashedAi\Filament\Pages\Settings;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Dashed\DashedAi\AiManager;
use Dashed\DashedAi\Facades\Ai;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Dashed\DashedAi\Enums\AiCapability;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Dashed\DashedCore\Models\Customsetting;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Dashed\DashedMarketing\Jobs\BulkGenerateMetaJob;
use Dashed\DashedAi\Jobs\GenerateToneOfVoiceBriefJob;
use Dashed\DashedAi\Jobs\CreateAltTextsForAllMediaItems;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

class AiSettingsPage extends Page implements HasSchemas
{
    use HasSettingsPermission;
    use InteractsWithSchemas;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'AI Instellingen';

    protected string $view = 'dashed-core::settings.pages.default-settings';

    public array $data = [];

    public function mount(): void
    {
        $formData = [
            'ai_default_provider' => Customsetting::get('ai_default_provider'),
            'create_alt_text_for_new_uploaded_images' => Customsetting::get('create_alt_text_for_new_uploaded_images'),
            'fal_api_key' => Customsetting::get('fal_api_key'),
            'ai_tone_of_voice_brief' => Customsetting::get('ai_tone_of_voice_brief'),
            'ai_tone_of_voice_brief_manual_override' => Customsetting::get('ai_tone_of_voice_brief_manual_override'),
            'ai_tone_of_voice_max_age_days' => (int) Customsetting::get('ai_tone_of_voice_max_age_days', null, 30),
        ];

        $manager = app(AiManager::class);
        foreach ($manager->providers() as $provider) {
            foreach ($provider->settingsSchema() as $component) {
                if (method_exists($component, 'getName')) {
                    $name = $component->getName();
                    $formData[$name] = Customsetting::get($name);
                }
            }
        }

        $this->form->fill($formData);
    }

    public function form(Schema $schema): Schema
    {
        $manager = app(AiManager::class);
        $providerOptions = [];
        foreach ($manager->providers() as $provider) {
            $providerOptions[$provider->name()] = $provider->label();
        }

        $sections = [
            Section::make(__('Algemeen'))
                ->description(__('Kies de standaard AI provider. De tone-of-voice Brief hieronder is de enige merk-context die bij elke AI-aanroep wordt meegegeven.'))
                ->schema([
                    Select::make('ai_default_provider')
                        ->label(__('Standaard AI provider'))
                        ->options($providerOptions)
                        ->placeholder(__('Automatisch (eerste beschikbare)'))
                        ->helperText(__('Als deze niet beschikbaar is, wordt automatisch een andere verbonden provider gebruikt.')),
                    Toggle::make('create_alt_text_for_new_uploaded_images')
                        ->label(__('Automatisch alt-teksten genereren voor nieuwe uploads'))
                        ->helperText(__('Gebruikt de vision-capability van de actieve AI provider. Werkt alleen voor Nederlands.')),
                ]),

            Section::make(__('Tone-of-voice Brief'))
                ->description(__('De Brief is de enige merk-context die bij elke AI-aanroep wordt meegegeven. AI genereert hem op basis van pages, artikelen en producten van deze site. Vernieuwt automatisch elke 30 dagen via de scheduler tenzij je een handmatige override hebt ingevuld.'))
                ->schema([
                    Textarea::make('ai_tone_of_voice_brief')
                        ->label(__('Huidige Brief (gegenereerd door AI)'))
                        ->disabled()
                        ->dehydrated(false)
                        ->rows(20)
                        ->placeholder(__('Nog geen Brief gegenereerd. Klik bovenaan op "Vernieuw tone-of-voice Brief" om er een te genereren.'))
                        ->helperText(fn (): string => $this->resolveLastGeneratedLabel()),
                    Textarea::make('ai_tone_of_voice_brief_manual_override')
                        ->label(__('Handmatige override (optioneel)'))
                        ->rows(20)
                        ->placeholder(__('Laat leeg om de gegenereerde Brief te gebruiken. Vul in om handmatig de toon vast te leggen of de gegenereerde versie bij te schaven.'))
                        ->helperText(__('Wanneer dit veld is ingevuld, wordt het gebruikt in plaats van de gegenereerde Brief. De scheduler ververst geen sites met een actieve override.')),
                    TextInput::make('ai_tone_of_voice_max_age_days')
                        ->label(__('Maximale ouderdom van de Brief (dagen)'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365)
                        ->default(30)
                        ->helperText(__('Daily-scheduler ververst de Brief wanneer hij ouder is dan dit aantal dagen.')),
                ]),

            Section::make(__('Afbeelding generatie (Fal.ai)'))
                ->description(__('Zodra hier een Fal.ai API sleutel staat, verschijnt er bij elk afbeelding-veld in de CMS een "Genereer met AI" knop. Met een referentieafbeelding wordt nano-banana/edit gebruikt, zonder referentie flux/dev.'))
                ->schema([
                    TextInput::make('fal_api_key')
                        ->label(__('Fal.ai API sleutel'))
                        ->password()
                        ->revealable()
                        ->helperText(__('Je vindt je API sleutel op fal.ai → API Keys.'))
                        ->placeholder(__('fal_...')),
                ]),
        ];

        foreach ($manager->providers() as $provider) {
            $connected = $provider->isConnected();
            $capabilities = collect($provider->supportedCapabilities())
                ->map(fn (AiCapability $c) => $c->value)
                ->join(', ');

            $providerSchema = array_merge([
                TextEntry::make('connection_status_'.$provider->name())
                    ->label(__('Status'))
                    ->state($provider->label().' is '.($connected ? 'verbonden' : 'niet verbonden'))
                    ->badge()
                    ->color($connected ? 'success' : 'danger'),
                TextEntry::make('capabilities_'.$provider->name())
                    ->label(__('Ondersteunde capabilities'))
                    ->state($capabilities ?: '-'),
            ], $provider->settingsSchema());

            $sections[] = Section::make($provider->label())
                ->schema($providerSchema)
                ->columns(1);
        }

        return $schema->schema($sections)->statePath('data');
    }

    /**
     * Helper voor de helper-text op het Brief-veld: toont laatste-gegenereerd-op
     * + waarschuwing als de Brief verouderd is.
     */
    protected function resolveLastGeneratedLabel(): string
    {
        $generatedAt = Customsetting::get('ai_tone_of_voice_generated_at');
        if (! $generatedAt) {
            return 'Nog niet gegenereerd.';
        }

        try {
            $when = \Carbon\Carbon::parse($generatedAt);
        } catch (\Throwable) {
            return 'Laatst gegenereerd op: ' . $generatedAt;
        }

        $maxAge = (int) (Customsetting::get('ai_tone_of_voice_max_age_days', null, 30) ?: 30);
        $age = (int) $when->diffInDays(now());
        $stale = $age > $maxAge ? ' (verouderd, scheduler ververst zodra mogelijk)' : '';

        return 'Laatst gegenereerd op: ' . $when->format('d-m-Y H:i') . ' (' . $age . ' dagen geleden)' . $stale;
    }

    public function submit(): void
    {
        $formData = $this->form->getState();
        $manager = app(AiManager::class);

        foreach (Sites::getSites() as $site) {
            Customsetting::set('ai_default_provider', $formData['ai_default_provider'] ?? null, $site['id']);
            Customsetting::set('create_alt_text_for_new_uploaded_images', $formData['create_alt_text_for_new_uploaded_images'] ?? false, $site['id']);
            Customsetting::set('fal_api_key', $formData['fal_api_key'] ?? null, $site['id']);
            Customsetting::set('ai_tone_of_voice_brief_manual_override', $formData['ai_tone_of_voice_brief_manual_override'] ?? null, $site['id']);
            Customsetting::set('ai_tone_of_voice_max_age_days', (int) ($formData['ai_tone_of_voice_max_age_days'] ?? 30), $site['id']);

            foreach ($manager->providers() as $provider) {
                foreach ($provider->settingsSchema() as $component) {
                    if (method_exists($component, 'getName')) {
                        $name = $component->getName();
                        Customsetting::set($name, $formData[$name] ?? null, $site['id']);
                    }
                }

                cache()->forget('ai_connected_'.$provider->name());
            }
        }

        Notification::make()
            ->title(__('AI instellingen opgeslagen'))
            ->success()
            ->send();

        redirect(AiSettingsPage::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshToneOfVoiceBrief')
                ->label(__('Vernieuw tone-of-voice Brief'))
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn () => Ai::hasProvider())
                ->requiresConfirmation()
                ->modalHeading(__('Tone-of-voice Brief opnieuw genereren'))
                ->modalDescription(__('AI analyseert pages, artikelen en producten van deze site en bouwt een complete tone-of-voice Brief volgens de 9-onderdelen-briefing. Bestaande gegenereerde Brief wordt overschreven; een handmatige override blijft staan.'))
                ->modalSubmitActionLabel(__('Vernieuw nu'))
                ->action(function (): void {
                    GenerateToneOfVoiceBriefJob::dispatch();

                    Notification::make()
                        ->title(__('Brief wordt op de achtergrond gegenereerd'))
                        ->body(__('Ververs deze pagina over een minuut om het resultaat te zien.'))
                        ->success()
                        ->send();
                }),

            Action::make('resetToneOfVoiceBrief')
                ->label(__('Reset Brief'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Tone-of-voice Brief resetten'))
                ->modalDescription(__('Verwijdert de gegenereerde Brief, de eventuele override en de bronnen-historie. AI-aanroepen lopen daarna zonder merk-context totdat je opnieuw genereert.'))
                ->modalSubmitActionLabel(__('Reset'))
                ->action(function (): void {
                    foreach (Sites::getSites() as $site) {
                        Customsetting::set('ai_tone_of_voice_brief', null, $site['id']);
                        Customsetting::set('ai_tone_of_voice_brief_manual_override', null, $site['id']);
                        Customsetting::set('ai_tone_of_voice_sources', null, $site['id']);
                        Customsetting::set('ai_tone_of_voice_generated_at', null, $site['id']);
                    }

                    Notification::make()
                        ->title(__('Brief gereset'))
                        ->success()
                        ->send();

                    redirect(AiSettingsPage::getUrl());
                }),

            Action::make('generateAltTextForAllImages')
                ->label(__('Genereer alt teksten voor alle afbeeldingen'))
                ->icon('heroicon-o-photo')
                ->color('primary')
                ->visible(fn () => Ai::default(AiCapability::Vision) !== null)
                ->schema([
                    TextEntry::make('info')
                        ->label(__(''))
                        ->state(function (): string {
                            $total = MediaLibraryItem::whereHas('media', fn ($q) => $q->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']))->count();
                            $missing = MediaLibraryItem::whereNull('alt_text')->whereHas('media', fn ($q) => $q->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']))->count();

                            return "Er zijn {$total} afbeeldingen waarvan {$missing} nog geen alt tekst hebben.";
                        }),
                    Toggle::make('overwriteExisting')
                        ->label(__('Overschrijf bestaande alt teksten'))
                        ->default(false),
                ])
                ->action(function ($data): void {
                    if (! Ai::default(AiCapability::Vision)) {
                        Notification::make()
                            ->title(__('Geen AI provider met vision capability beschikbaar'))
                            ->danger()
                            ->send();

                        return;
                    }

                    CreateAltTextsForAllMediaItems::dispatch($data['overwriteExisting'] ?? false);

                    Notification::make()
                        ->title(__('Alt teksten worden gegenereerd'))
                        ->success()
                        ->send();
                }),

            Action::make('bulk_generate_meta')
                ->label(__('Genereer meta voor alle modellen'))
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->visible(fn () => Ai::hasProvider() && class_exists(BulkGenerateMetaJob::class))
                ->modalHeading(__('Meta genereren voor alle modellen'))
                ->modalDescription(__('AI genereert voor elk geselecteerd model een meta-titel en meta-omschrijving in elke taal. De feitelijke generatie loopt op de achtergrond, per record.'))
                ->modalSubmitActionLabel(__('Start genereren'))
                ->schema([
                    Toggle::make('overwrite')
                        ->label(__('Overschrijf bestaande meta titels/beschrijvingen'))
                        ->default(false),
                    Textarea::make('user_instruction')
                        ->label(__('Optionele instructie'))
                        ->placeholder(__('Bijv. nadruk op lokale SEO of een specifiek keyword.'))
                        ->rows(3),
                    Select::make('models')
                        ->label(__('Welke modellen'))
                        ->multiple()
                        ->options(function (): array {
                            try {
                                $registry = (array) cms()->builder('routeModels');
                            } catch (\Throwable) {
                                return [];
                            }

                            $options = [];
                            foreach ($registry as $key => $config) {
                                $label = $config['pluralName'] ?? $config['name'] ?? $key;
                                $options[$key] = (string) $label;
                            }

                            return $options;
                        })
                        ->default(function (): array {
                            try {
                                return array_keys((array) cms()->builder('routeModels'));
                            } catch (\Throwable) {
                                return [];
                            }
                        })
                        ->required(),
                ])
                ->action(function (array $data): void {
                    if (! class_exists(BulkGenerateMetaJob::class)) {
                        Notification::make()
                            ->title(__('dashed-marketing is niet geïnstalleerd'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $models = array_values(array_filter((array) ($data['models'] ?? []), 'is_string'));
                    if ($models === []) {
                        Notification::make()
                            ->title(__('Kies minimaal één model'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $instruction = ! empty($data['user_instruction']) ? (string) $data['user_instruction'] : null;
                    $overwrite = (bool) ($data['overwrite'] ?? false);

                    BulkGenerateMetaJob::dispatch($models, $instruction, $overwrite);

                    Notification::make()
                        ->title(__('Bulk meta-generatie gestart'))
                        ->body(__('De jobs worden op de achtergrond afgevuurd, per record. Houd de queue-monitor in de gaten.'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
