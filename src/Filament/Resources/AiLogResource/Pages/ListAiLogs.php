<?php

namespace Dashed\DashedAi\Filament\Resources\AiLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Dashed\DashedAi\Filament\Resources\AiLogResource;
use Dashed\DashedAi\Filament\Resources\AiLogResource\Widgets\AiCostStats;

class ListAiLogs extends ListRecords
{
    protected static string $resource = AiLogResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            AiCostStats::class,
        ];
    }
}
