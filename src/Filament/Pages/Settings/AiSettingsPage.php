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
use Dashed\DashedAi\Jobs\GenerateBrandContextJob;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Filament\Schemas\Concerns\InteractsWithSchemas;
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
            'ai_brand_story' => Customsetting::get('ai_brand_story'),
            'ai_writing_style' => Customsetting::get('ai_writing_style'),
            'create_alt_text_for_new_uploaded_images' => Customsetting::get('create_alt_text_for_new_uploaded_images'),
            'fal_api_key' => Customsetting::get('fal_api_key'),
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
            Section::make('Algemeen')
                ->description('Kies de standaard AI provider en definieer het merkverhaal en de schrijfstijl die als context worden meegegeven bij elke AI-aanroep.')
                ->schema([
                    Select::make('ai_default_provider')
                        ->label('Standaard AI provider')
                        ->options($providerOptions)
                        ->placeholder('Automatisch (eerste beschikbare)')
                        ->helperText('Als deze niet beschikbaar is, wordt automatisch een andere verbonden provider gebruikt.'),
                    Textarea::make('ai_brand_story')
                        ->label('Merkverhaal')
                        ->helperText('Beschrijf wat je merk doet, welke producten of diensten je aanbiedt, voor wie, en wat je onderscheidt. Dit wordt bij elke AI-aanroep meegegeven als context.')
                        ->rows(6)
                        ->placeholder('Bijv: Dashed is een Nederlands bureau dat maatwerk Laravel-websites en webshops bouwt voor het MKB. We combineren techniek en design om bedrijven online te laten groeien.'),
                    Textarea::make('ai_writing_style')
                        ->label('Schrijfstijl')
                        ->helperText('Beschrijf hoe er geschreven moet worden: toon, formaliteit, zinslengte, humor, vaktaal, woorden die wel/niet passen. Dit wordt bij elke AI-aanroep meegegeven.')
                        ->rows(6)
                        ->placeholder('Bijv: Informeel Nederlands, enthousiast en persoonlijk. Korte zinnen, directe aanspreekvorm (je/jij). Geen EM-dashes, geen stijve corporate taal, geen overdreven superlatieven.'),
                    Toggle::make('create_alt_text_for_new_uploaded_images')
                        ->label('Automatisch alt-teksten genereren voor nieuwe uploads')
                        ->helperText('Gebruikt de vision-capability van de actieve AI provider. Werkt alleen voor Nederlands.'),
                ]),

            Section::make('Afbeelding generatie (Fal.ai)')
                ->description('Zodra hier een Fal.ai API sleutel staat, verschijnt er bij elk afbeelding-veld in de CMS een "Genereer met AI" knop. Met een referentieafbeelding wordt nano-banana/edit gebruikt, zonder referentie flux/dev.')
                ->schema([
                    TextInput::make('fal_api_key')
                        ->label('Fal.ai API sleutel')
                        ->password()
                        ->revealable()
                        ->helperText('Je vindt je API sleutel op fal.ai → API Keys.')
                        ->placeholder('fal_...'),
                ]),
        ];

        foreach ($manager->providers() as $provider) {
            $connected = $provider->isConnected();
            $capabilities = collect($provider->supportedCapabilities())
                ->map(fn (AiCapability $c) => $c->value)
                ->join(', ');

            $providerSchema = array_merge([
                TextEntry::make('connection_status_' . $provider->name())
                    ->label('Status')
                    ->state($provider->label() . ' is ' . ($connected ? 'verbonden' : 'niet verbonden'))
                    ->badge()
                    ->color($connected ? 'success' : 'danger'),
                TextEntry::make('capabilities_' . $provider->name())
                    ->label('Ondersteunde capabilities')
                    ->state($capabilities ?: '-'),
            ], $provider->settingsSchema());

            $sections[] = Section::make($provider->label())
                ->schema($providerSchema)
                ->columns(1);
        }

        return $schema->schema($sections)->statePath('data');
    }

    public function submit(): void
    {
        $formData = $this->form->getState();
        $manager = app(AiManager::class);

        foreach (Sites::getSites() as $site) {
            Customsetting::set('ai_default_provider', $formData['ai_default_provider'] ?? null, $site['id']);
            Customsetting::set('ai_brand_story', $formData['ai_brand_story'] ?? null, $site['id']);
            Customsetting::set('ai_writing_style', $formData['ai_writing_style'] ?? null, $site['id']);
            Customsetting::set('create_alt_text_for_new_uploaded_images', $formData['create_alt_text_for_new_uploaded_images'] ?? false, $site['id']);
            Customsetting::set('fal_api_key', $formData['fal_api_key'] ?? null, $site['id']);

            foreach ($manager->providers() as $provider) {
                foreach ($provider->settingsSchema() as $component) {
                    if (method_exists($component, 'getName')) {
                        $name = $component->getName();
                        Customsetting::set($name, $formData[$name] ?? null, $site['id']);
                    }
                }

                cache()->forget('ai_connected_' . $provider->name());
            }
        }

        Notification::make()
            ->title('AI instellingen opgeslagen')
            ->success()
            ->send();

        redirect(AiSettingsPage::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateBrandContext')
                ->label('Genereer merkverhaal & schrijfstijl')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->visible(fn () => Ai::hasProvider())
                ->requiresConfirmation()
                ->modalHeading('Merkverhaal & schrijfstijl automatisch genereren')
                ->modalDescription('AI analyseert de huidige website-inhoud en genereert een merkverhaal en schrijfstijl. Dit gebeurt op de achtergrond - je krijgt een notificatie als het klaar is. Bestaande waarden worden overschreven.')
                ->modalSubmitActionLabel('Start genereren')
                ->action(function (): void {
                    GenerateBrandContextJob::dispatch(auth()->id());

                    Notification::make()
                        ->title('Generatie gestart')
                        ->body('De AI analyseert je website op de achtergrond. Je krijgt een notificatie zodra het klaar is.')
                        ->success()
                        ->send();
                }),

            Action::make('generateAltTextForAllImages')
                ->label('Genereer alt teksten voor alle afbeeldingen')
                ->icon('heroicon-o-photo')
                ->color('primary')
                ->visible(fn () => Ai::default(AiCapability::Vision) !== null)
                ->schema([
                    TextEntry::make('info')
                        ->label('')
                        ->state(function (): string {
                            $total = MediaLibraryItem::whereHas('media', fn ($q) => $q->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']))->count();
                            $missing = MediaLibraryItem::whereNull('alt_text')->whereHas('media', fn ($q) => $q->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']))->count();

                            return "Er zijn {$total} afbeeldingen waarvan {$missing} nog geen alt tekst hebben.";
                        }),
                    Toggle::make('overwriteExisting')
                        ->label('Overschrijf bestaande alt teksten')
                        ->default(false),
                ])
                ->action(function ($data): void {
                    if (! Ai::default(AiCapability::Vision)) {
                        Notification::make()
                            ->title('Geen AI provider met vision capability beschikbaar')
                            ->danger()
                            ->send();

                        return;
                    }

                    CreateAltTextsForAllMediaItems::dispatch($data['overwriteExisting'] ?? false);

                    Notification::make()
                        ->title('Alt teksten worden gegenereerd')
                        ->success()
                        ->send();
                }),
        ];
    }
}
