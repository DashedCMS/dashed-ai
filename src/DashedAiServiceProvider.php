<?php

namespace Dashed\DashedAi;

use Spatie\LaravelPackageTools\Package;
use Dashed\DashedAi\Commands\CreateAltTextsCommand;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedAiServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ai';

    public function configurePackage(Package $package): void
    {
        $package
            ->hasConfigFile(['dashed-ai'])
            ->hasCommands([
                CreateAltTextsCommand::class,
            ])
            ->name(self::$name);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(AiManager::class, fn () => new AiManager());
    }

    public function bootingPackage(): void
    {
        cms()->builder('plugins', [
            new DashedAiPlugin(),
        ]);

        cms()->registerSettingsPage(
            \Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage::class,
            'AI',
            'sparkles',
            'AI providers, merkverhaal en schrijfstijl'
        );
    }
}
