<?php

namespace Dashed\DashedAi\Filament\Resources;

use UnitEnum;
use BackedEnum;
use Filament\Tables\Table;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Dashed\DashedAi\Models\AiLog;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedAi\Logging\AiLogSettings;
use Filament\Tables\Columns\Summarizers\Sum;
use Dashed\DashedAi\Filament\Resources\AiLogResource\Pages\ViewAiLog;
use Dashed\DashedAi\Filament\Resources\AiLogResource\Pages\ListAiLogs;
use Dashed\DashedAi\Filament\Resources\AiLogResource\Widgets\AiCostStats;

/**
 * Het AI-logboek: elke aanroep van een externe AI-dienst met verbruik en
 * geschatte kosten. Alleen lezen; opruimen gaat via de bewaartermijn
 * `ai_logs`.
 */
class AiLogResource extends Resource
{
    use \Dashed\DashedCore\Filament\Concerns\TranslatesResourceLabels;

    protected static ?string $model = AiLog::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string | UnitEnum | null $navigationGroup = 'Systeem';

    protected static ?int $navigationSort = 130;

    protected static ?string $navigationLabel = 'AI-logboek';

    protected static ?string $label = 'AI-aanroep';

    protected static ?string $pluralLabel = 'AI-logboek';

    protected static ?string $slug = 'ai-logs';

    protected static bool $isGloballySearchable = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function formatUsd(?float $amount): string
    {
        if ($amount === null) {
            return '-';
        }

        return '$' . number_format($amount, $amount < 0.01 && $amount > 0 ? 4 : 2, ',', '.');
    }

    public static function formatEur(?float $amountUsd): string
    {
        if ($amountUsd === null) {
            return '-';
        }

        $eur = $amountUsd * AiLogSettings::usdEurRate();

        return '€' . number_format($eur, $eur < 0.01 && $eur > 0 ? 4 : 2, ',', '.');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Tijd'))
                    ->dateTime('d-m-Y H:i:s')
                    ->sortable(),
                TextColumn::make('provider')
                    ->label(__('Provider'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AiLog::providerLabels()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('model')
                    ->label(__('Model'))
                    ->placeholder('-')
                    ->limit(30)
                    ->tooltip(fn (AiLog $record): ?string => $record->model)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('kind')
                    ->label(__('Soort'))
                    ->formatStateUsing(fn (string $state): string => AiLog::kindLabels()[$state] ?? $state)
                    ->toggleable(),
                TextColumn::make('purpose')
                    ->label(__('Onderdeel'))
                    ->getStateUsing(fn (AiLog $record): string => $record->featureLabel())
                    ->tooltip(fn (AiLog $record): ?string => $record->caller)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('purpose', 'like', "%{$search}%")
                        ->orWhere('caller', 'like', "%{$search}%"))),
                TextColumn::make('user.name')
                    ->label(__('Gebruiker'))
                    ->placeholder(__('Systeem'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === AiLog::STATUS_SUCCESS ? __('Gelukt') : __('Mislukt'))
                    ->color(fn (string $state): string => $state === AiLog::STATUS_SUCCESS ? 'success' : 'danger'),
                TextColumn::make('input_tokens')
                    ->label(__('Tokens in'))
                    ->numeric()
                    ->getStateUsing(fn (AiLog $record): int => $record->input_tokens + $record->cache_read_tokens + $record->cache_write_tokens)
                    ->tooltip(fn (AiLog $record): ?string => $record->cache_read_tokens || $record->cache_write_tokens
                        ? __(':gelezen uit cache, :geschreven naar cache', ['gelezen' => $record->cache_read_tokens, 'geschreven' => $record->cache_write_tokens])
                        : null)
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('output_tokens')
                    ->label(__('Tokens uit'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('units')
                    ->label(__('Eenheden'))
                    ->placeholder('-')
                    ->formatStateUsing(fn (?float $state, AiLog $record): string => $state === null ? '-' : rtrim(rtrim(number_format($state, 2, ',', '.'), '0'), ',') . ' ' . ($record->unit_type ?? ''))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_usd')
                    ->label(__('Kosten (USD)'))
                    ->formatStateUsing(fn (?float $state): string => self::formatUsd($state))
                    ->placeholder(__('onbekend'))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label(__('Totaal'))->formatStateUsing(fn ($state): string => self::formatUsd($state === null ? null : (float) $state))),
                TextColumn::make('cost_eur')
                    ->label(__('Kosten (EUR)'))
                    ->getStateUsing(fn (AiLog $record): string => self::formatEur($record->cost_usd))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('duration_ms')
                    ->label(__('Duur'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : ($state >= 1000 ? number_format($state / 1000, 1, ',', '.') . ' s' : $state . ' ms'))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->label(__('Provider'))
                    ->multiple()
                    ->options(AiLog::providerLabels()),
                SelectFilter::make('kind')
                    ->label(__('Soort'))
                    ->multiple()
                    ->options(AiLog::kindLabels()),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([AiLog::STATUS_SUCCESS => __('Gelukt'), AiLog::STATUS_ERROR => __('Mislukt')]),
                SelectFilter::make('purpose')
                    ->label(__('Onderdeel'))
                    ->multiple()
                    ->options(fn (): array => self::featureOptions())
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values'])
                        ? $query
                        : $query->where(fn (Builder $q) => $q->whereIn('purpose', $data['values'])->orWhereIn('caller', $data['values']))),
                SelectFilter::make('user_id')
                    ->label(__('Gebruiker'))
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload(),
                Filter::make('periode')
                    ->schema([
                        DatePicker::make('vanaf')->label(__('Vanaf')),
                        DatePicker::make('tot')->label(__('Tot en met')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['vanaf'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['tot'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * De functies die in het logboek voorkomen: expliciete doelen en
     * aanroepende klassen samen, zodat het filter dezelfde namen toont als de
     * kolom.
     *
     * @return array<string, string>
     */
    public static function featureOptions(): array
    {
        $options = [];
        foreach (AiLog::query()->whereNotNull('purpose')->distinct()->orderBy('purpose')->pluck('purpose') as $purpose) {
            $options[$purpose] = $purpose;
        }
        foreach (AiLog::query()->whereNull('purpose')->whereNotNull('caller')->distinct()->orderBy('caller')->pluck('caller') as $caller) {
            $options[$caller] = class_basename($caller);
        }

        return $options;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiLogs::route('/'),
            'view' => ViewAiLog::route('/{record}'),
        ];
    }

    /**
     * Alleen hier registreert Filament een widget als Livewire-component.
     * Zonder deze registratie rendert de lijst nog, maar laat het lui laden
     * van de widget de pagina eindeloos herladen (zie
     * ResourceWidgetRegistrationGuardTest in de root).
     */
    public static function getWidgets(): array
    {
        return [
            AiCostStats::class,
        ];
    }
}
