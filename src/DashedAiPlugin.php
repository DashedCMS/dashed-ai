<?php

namespace Dashed\DashedAi;

use Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage;
use Filament\Contracts\Plugin;
use Filament\Panel;

class DashedAiPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-ai';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            AiSettingsPage::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
