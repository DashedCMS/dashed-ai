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
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Dashed\DashedCore\Models\Customsetting;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Dashed\DashedCore\Classes\WebsiteContentCollector;
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
            'ai_brand_context' => Customsetting::get('ai_brand_context'),
            'create_alt_text_for_new_uploaded_images' => Customsetting::get('create_alt_text_for_new_uploaded_images'),
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
                ->description('Kies de standaard AI provider en definieer het merkverhaal dat als context wordt meegegeven bij elke AI-aanroep.')
                ->schema([
                    Select::make('ai_default_provider')
                        ->label('Standaard AI provider')
                        ->options($providerOptions)
                        ->placeholder('Automatisch (eerste beschikbare)')
                        ->helperText('Als deze niet beschikbaar is, wordt automatisch een andere verbonden provider gebruikt.'),
                    Textarea::make('ai_brand_context')
                        ->label('Merkverhaal en schrijfstijl')
                        ->helperText('Beschrijf je merk, producten/diensten, doelgroep en schrijfstijl. Dit wordt als system prompt meegegeven bij elke AI-aanroep.')
                        ->rows(8)
                        ->placeholder("Bijv: Dashed is een Nederlands bureau dat maatwerk Laravel-websites en webshops bouwt voor het MKB. Schrijf in informeel Nederlands, enthousiast en persoonlijk. Gebruik geen EM-dashes of stijve bijvoeglijke naamwoorden."),
                    Toggle::make('create_alt_text_for_new_uploaded_images')
                        ->label('Automatisch alt-teksten genereren voor nieuwe uploads')
                        ->helperText('Gebruikt de vision-capability van de actieve AI provider. Werkt alleen voor Nederlands.'),
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
                    ->state($capabilities ?: '—'),
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
            Customsetting::set('ai_brand_context', $formData['ai_brand_context'] ?? null, $site['id']);
            Customsetting::set('create_alt_text_for_new_uploaded_images', $formData['create_alt_text_for_new_uploaded_images'] ?? false, $site['id']);

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
                ->label('Genereer merkverhaal automatisch')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->visible(fn () => Ai::hasProvider())
                ->requiresConfirmation()
                ->modalHeading('Merkverhaal automatisch genereren')
                ->modalDescription('AI analyseert de huidige website-inhoud en genereert automatisch een merkverhaal en schrijfstijl. Bestaande waarde wordt overschreven.')
                ->modalSubmitActionLabel('Genereer')
                ->action(function (): void {
                    $siteName = Customsetting::get('site_name') ?: config('app.name');
                    $samples = WebsiteContentCollector::collect();

                    if (! $samples) {
                        Notification::make()
                            ->title('Geen website-inhoud gevonden')
                            ->body('Er zijn nog geen pagina\'s met metadata om van te analyseren.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $prompt = <<<PROMPT
                    Analyseer de onderstaande paginatitels en beschrijvingen van de website "{$siteName}" en schrijf één samenhangend merkverhaal van 5-8 zinnen. Beschrijf wat het bedrijf doet, welke producten/diensten ze aanbieden, voor wie, en op welke toon en schrijfstijl er geschreven moet worden (gebaseerd op de toon die al gebruikt wordt in de teksten).

                    HUIDIGE PAGINA-INHOUD:
                    {$samples}

                    Retourneer UITSLUITEND geldig JSON in dit formaat (geen markdown):
                    {
                      "brand_context": "..."
                    }
                    PROMPT;

                    $result = Ai::json($prompt);

                    if (! $result || empty($result['brand_context'])) {
                        Notification::make()
                            ->title('Genereren mislukt')
                            ->body('De AI provider gaf geen bruikbaar antwoord.')
                            ->danger()
                            ->send();

                        return;
                    }

                    foreach (Sites::getSites() as $site) {
                        Customsetting::set('ai_brand_context', $result['brand_context'], $site['id']);
                    }

                    Notification::make()
                        ->title('Merkverhaal gegenereerd')
                        ->success()
                        ->send();

                    redirect(AiSettingsPage::getUrl());
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
