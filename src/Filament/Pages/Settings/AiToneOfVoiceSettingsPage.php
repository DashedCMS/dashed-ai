<?php

namespace Dashed\DashedAi\Filament\Pages\Settings;

use UnitEnum;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Dashed\DashedCore\Models\Customsetting;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Dashed\DashedAi\Jobs\GenerateToneOfVoiceBriefJob;

class AiToneOfVoiceSettingsPage extends Page implements HasSchemas
{
    use HasSettingsPermission;
    use InteractsWithSchemas;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-sparkles';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'AI tone of voice';

    protected static string | UnitEnum | null $navigationGroup = 'AI';

    protected static ?string $title = 'AI tone of voice';

    protected string $view = 'dashed-core::settings.pages.default-settings';

    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'ai_tone_of_voice_brief' => (string) Customsetting::get('ai_tone_of_voice_brief'),
            'ai_tone_of_voice_brief_manual_override' => (string) Customsetting::get('ai_tone_of_voice_brief_manual_override'),
            'ai_tone_of_voice_max_age_days' => (int) (Customsetting::get('ai_tone_of_voice_max_age_days') ?: 30),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $generatedAtRaw = (string) Customsetting::get('ai_tone_of_voice_generated_at');
        $sources = Customsetting::get('ai_tone_of_voice_sources');

        $generatedAtLabel = $generatedAtRaw !== ''
            ? (function () use ($generatedAtRaw) {
                try {
                    return \Carbon\Carbon::parse($generatedAtRaw)->isoFormat('D MMMM YYYY, HH:mm');
                } catch (\Throwable $e) {
                    return $generatedAtRaw;
                }
            })()
            : 'Nog niet gegenereerd';

        $sourcesText = '-';
        if (is_array($sources) && ! empty($sources)) {
            $sourcesText = collect($sources)
                ->map(fn ($s) => '- ' . ($s['type'] ?? 'item') . ': ' . ($s['title'] ?? '#' . ($s['id'] ?? '?')))
                ->implode("\n");
        }

        return $schema
            ->schema([
                Section::make('Gegenereerde Brief')
                    ->description('De automatisch gegenereerde Tone of Voice Brief. Deze wordt door alle AI-tekstgeneratie als context meegegeven.')
                    ->schema([
                        TextEntry::make('generated_at_label')
                            ->label('Laatst gegenereerd op')
                            ->state($generatedAtLabel),
                        TextEntry::make('sources_label')
                            ->label('Bronnen gebruikt voor laatste Brief')
                            ->state($sourcesText),
                        Textarea::make('ai_tone_of_voice_brief')
                            ->label('Brief (gegenereerd)')
                            ->helperText('Read-only weergave van de laatst gegenereerde Brief. Gebruik de knop "Vernieuw Brief nu" rechtsboven om te regenereren.')
                            ->rows(20)
                            ->disabled()
                            ->dehydrated(false),
                    ]),

                Section::make('Override')
                    ->description('Vul deze override in om de gegenereerde Brief te negeren en zelf de toon te bepalen of bij te schaven.')
                    ->schema([
                        Textarea::make('ai_tone_of_voice_brief_manual_override')
                            ->label('Brief override')
                            ->helperText('Laat leeg om de gegenereerde Brief te gebruiken. Vul in om handmatig de toon te bepalen of bij te schaven.')
                            ->rows(20),
                    ]),

                Section::make('Vernieuwing')
                    ->description('Bepaalt hoe vaak de Brief automatisch opnieuw wordt gegenereerd op basis van het actuele website-materiaal.')
                    ->schema([
                        TextInput::make('ai_tone_of_voice_max_age_days')
                            ->label('Maximale ouderdom (dagen)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(30)
                            ->helperText('De daily scheduler regenereert de Brief zodra deze ouder is dan dit aantal dagen.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $formData = $this->form->getState();

        $override = $formData['ai_tone_of_voice_brief_manual_override'] ?? null;
        $maxAge = $formData['ai_tone_of_voice_max_age_days'] ?? 30;

        foreach (Sites::getSites() ?: [['id' => null]] as $site) {
            $siteId = $site['id'] ?? null;
            $siteId = $siteId !== null ? (string) $siteId : null;

            Customsetting::set('ai_tone_of_voice_brief_manual_override', $override !== null ? (string) $override : null, $siteId);
            Customsetting::set('ai_tone_of_voice_max_age_days', (string) ((int) $maxAge ?: 30), $siteId);
        }

        Notification::make()
            ->title('Tone of voice instellingen opgeslagen')
            ->success()
            ->send();

        redirect(self::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerate')
                ->label('Vernieuw Brief nu')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function (): void {
                    GenerateToneOfVoiceBriefJob::dispatch();

                    Notification::make()
                        ->title('Brief wordt op de achtergrond gegenereerd')
                        ->body('Ververs de pagina over een minuut om het resultaat te zien.')
                        ->success()
                        ->send();
                }),

            Action::make('reset')
                ->label('Reset Brief')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalHeading('Brief resetten?')
                ->modalDescription('Hiermee verwijder je de gegenereerde Brief, override en bronnen. Volgende AI-call gebruikt geen tone-of-voice meer totdat je opnieuw genereert.')
                ->modalSubmitActionLabel('Ja, reset')
                ->action(function (): void {
                    foreach (Sites::getSites() ?: [['id' => null]] as $site) {
                        $siteId = $site['id'] ?? null;
                        $siteId = $siteId !== null ? (string) $siteId : null;

                        Customsetting::set('ai_tone_of_voice_brief', null, $siteId);
                        Customsetting::set('ai_tone_of_voice_brief_manual_override', null, $siteId);
                        Customsetting::set('ai_tone_of_voice_sources', null, $siteId);
                        Customsetting::set('ai_tone_of_voice_generated_at', null, $siteId);
                    }

                    Notification::make()
                        ->title('Brief gereset')
                        ->success()
                        ->send();

                    redirect(self::getUrl());
                }),
        ];
    }
}
