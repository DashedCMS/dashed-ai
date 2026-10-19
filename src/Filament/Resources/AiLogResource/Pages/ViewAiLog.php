<?php

namespace Dashed\DashedAi\Filament\Resources\AiLogResource\Pages;

use Filament\Schemas\Schema;
use Dashed\DashedAi\Models\AiLog;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedAi\Filament\Resources\AiLogResource;

class ViewAiLog extends ViewRecord
{
    protected static string $resource = AiLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Aanroep'))
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label(__('Tijd'))->dateTime('d-m-Y H:i:s'),
                    TextEntry::make('provider')->label(__('Provider'))->badge()
                        ->formatStateUsing(fn (string $state): string => AiLog::providerLabels()[$state] ?? $state),
                    TextEntry::make('model')->label(__('Model'))->placeholder('-'),
                    TextEntry::make('kind')->label(__('Soort'))
                        ->formatStateUsing(fn (string $state): string => AiLog::kindLabels()[$state] ?? $state),
                    TextEntry::make('purpose')->label(__('Onderdeel'))
                        ->getStateUsing(fn (AiLog $record): string => $record->featureLabel()),
                    TextEntry::make('caller')->label(__('Aanroepende klasse'))->placeholder('-')->copyable(),
                    TextEntry::make('user.name')->label(__('Gebruiker'))->placeholder(__('Systeem')),
                    TextEntry::make('site_id')->label(__('Site'))->placeholder('-'),
                    TextEntry::make('status')->label(__('Status'))->badge()
                        ->formatStateUsing(fn (string $state): string => $state === AiLog::STATUS_SUCCESS ? __('Gelukt') : __('Mislukt'))
                        ->color(fn (string $state): string => $state === AiLog::STATUS_SUCCESS ? 'success' : 'danger'),
                    TextEntry::make('duration_ms')->label(__('Duur'))
                        ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : ($state >= 1000 ? number_format($state / 1000, 1, ',', '.') . ' s' : $state . ' ms')),
                ]),
            Section::make(__('Verbruik en kosten'))
                ->columns(3)
                ->schema([
                    TextEntry::make('input_tokens')->label(__('Tokens in'))->numeric(),
                    TextEntry::make('output_tokens')->label(__('Tokens uit'))->numeric(),
                    TextEntry::make('cache_read_tokens')->label(__('Uit cache gelezen'))->numeric(),
                    TextEntry::make('cache_write_tokens')->label(__('Naar cache geschreven'))->numeric(),
                    TextEntry::make('units')->label(__('Eenheden'))->placeholder('-')
                        ->formatStateUsing(fn (?float $state, AiLog $record): string => $state === null ? '-' : rtrim(rtrim(number_format($state, 4, ',', '.'), '0'), ',') . ' ' . ($record->unit_type ?? '')),
                    TextEntry::make('cost_usd')->label(__('Kosten (USD)'))
                        ->getStateUsing(fn (AiLog $record): string => AiLogResource::formatUsd($record->cost_usd))
                        ->helperText(fn (AiLog $record): ?string => $record->cost_usd === null && $record->status === AiLog::STATUS_SUCCESS
                            ? __('Geen prijs bekend voor dit model; voeg hem toe aan de prijstabel in config/dashed-ai.php.')
                            : null),
                    TextEntry::make('cost_eur')->label(__('Kosten (EUR)'))
                        ->getStateUsing(fn (AiLog $record): string => AiLogResource::formatEur($record->cost_usd)),
                    TextEntry::make('options')->label(__('Instellingen van de aanroep'))->placeholder('-')
                        ->getStateUsing(fn (AiLog $record): ?string => $record->options ? json_encode($record->options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null)
                        ->columnSpan(2),
                ]),
            Section::make(__('Fout'))
                ->visible(fn (AiLog $record): bool => $record->error !== null)
                ->schema([
                    TextEntry::make('error')->label('')->color('danger'),
                ]),
            Section::make(__('Prompt'))
                ->collapsible()
                ->schema([
                    TextEntry::make('prompt')->label('')->placeholder(__('Niet bewaard'))->copyable()
                        ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: ui-monospace, monospace; font-size: 0.8rem;']),
                ]),
            Section::make(__('Antwoord'))
                ->collapsible()
                ->schema([
                    TextEntry::make('response')->label('')->placeholder(__('Niet bewaard'))->copyable()
                        ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: ui-monospace, monospace; font-size: 0.8rem;']),
                ]),
        ]);
    }
}
