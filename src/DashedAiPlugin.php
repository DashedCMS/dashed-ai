<?php

namespace Dashed\DashedAi;

use Filament\Panel;
use Filament\Contracts\Plugin;
use Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage;
use Dashed\DashedAi\Filament\Pages\Settings\AiToneOfVoiceSettingsPage;

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
            AiToneOfVoiceSettingsPage::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
    }
}
