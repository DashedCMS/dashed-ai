<?php

namespace Dashed\DashedAi\Filament\Resources\AiLogResource\Widgets;

use Dashed\DashedAi\Models\AiLog;
use Dashed\DashedAi\Logging\AiBudget;
use Filament\Widgets\StatsOverviewWidget;
use Dashed\DashedAi\Logging\AiLogSettings;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Dashed\DashedAi\Filament\Resources\AiLogResource;
use Dashed\DashedCore\Filament\Support\ResourceFilterUrl;

/**
 * Kosten vandaag, deze maand en vorige maand, het aantal aanroepen en de
 * stand van het budget. Alles is een som of telling in de database.
 */
class AiCostStats extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getCards(): array
    {
        $today = (float) AiLog::query()->whereDate('created_at', today())->sum('cost_usd');
        $month = AiBudget::monthToDateUsd();
        $lastMonth = AiBudget::monthToDateUsd(now()->subMonthNoOverflow());
        $calls = AiLog::query()->inMonth()->count();
        $failed = AiLog::query()->inMonth()->where('status', AiLog::STATUS_ERROR)->count();
        $unknown = AiLog::query()->inMonth()->where('status', AiLog::STATUS_SUCCESS)->whereNull('cost_usd')->count();

        $cards = [
            Stat::make(__('Vandaag'), AiLogResource::formatUsd($today))
                ->description(AiLogResource::formatEur($today))
                ->url(ResourceFilterUrl::for(AiLogResource::class, [
                    'periode' => ['vanaf' => today()->toDateString(), 'tot' => today()->toDateString()],
                ])),
            Stat::make(__('Deze maand'), AiLogResource::formatUsd($month))
                ->description(AiLogResource::formatEur($month) . ($unknown > 0 ? ' · ' . __(':aantal aanroepen zonder bekende prijs', ['aantal' => $unknown]) : ''))
                ->color($unknown > 0 ? 'warning' : 'primary')
                ->url(ResourceFilterUrl::for(AiLogResource::class, [
                    'periode' => ['vanaf' => now()->startOfMonth()->toDateString(), 'tot' => now()->endOfMonth()->toDateString()],
                ])),
            Stat::make(__('Vorige maand'), AiLogResource::formatUsd($lastMonth))
                ->description(AiLogResource::formatEur($lastMonth)),
            Stat::make(__('Aanroepen deze maand'), number_format($calls, 0, ',', '.'))
                ->description($failed > 0 ? __(':aantal mislukt', ['aantal' => $failed]) : __('Geen mislukte aanroepen'))
                ->color($failed > 0 ? 'danger' : 'success'),
        ];

        $budget = AiLogSettings::monthlyBudgetUsd();
        if ($budget > 0) {
            $percentage = (int) round(($month / $budget) * 100);
            $cards[] = Stat::make(__('Maandbudget'), $percentage . '%')
                ->description(__(':gebruikt van :budget', ['gebruikt' => AiLogResource::formatUsd($month), 'budget' => AiLogResource::formatUsd($budget)]))
                ->color($percentage >= 100 ? 'danger' : ($percentage >= 80 ? 'warning' : 'success'));
        }

        return $cards;
    }
}
