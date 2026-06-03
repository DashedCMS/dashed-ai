<?php

namespace Dashed\DashedAi\Facades;

use Dashed\DashedAi\AiManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null text(string $prompt, array $options = [])
 * @method static array|null json(string $prompt, array $options = [])
 * @method static string|null vision(string $prompt, string $imageData, string $mimeType, array $options = [])
 * @method static string|null image(string $prompt, array $options = [])
 * @method static array embed(string $text, array $options = [])
 * @method static array|null messages(array $messages, array $options = [])
 * @method static array|null streamMessages(array $messages, array $options, callable $onText)
 * @method static \Dashed\DashedAi\AiProvider|null default(?\Dashed\DashedAi\Enums\AiCapability $capability = null)
 * @method static array providers()
 * @method static array connectedProviders()
 * @method static \Dashed\DashedAi\AiProvider|null provider(string $name)
 * @method static bool hasProvider()
 * @method static void register(\Dashed\DashedAi\AiProvider $provider)
 */
class Ai extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AiManager::class;
    }
}
