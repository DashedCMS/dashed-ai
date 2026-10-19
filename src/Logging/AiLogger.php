<?php

namespace Dashed\DashedAi\Logging;

use Dashed\DashedCore\Classes\Sites;

/**
 * Ingang van het AI-logboek. Een provider roept begin() aan vlak vóór de
 * HTTP-aanroep en sluit af met succeed() of fail() op het teruggegeven
 * AiCallLog; die schrijft dan één rij in dashed__ai_logs met verbruik,
 * geschatte kosten en, als dat aanstaat, prompt en antwoord.
 *
 * Loggen mag een aanroep nooit tegenhouden: alles wat hier misgaat wordt
 * weggeslikt (rescue) en de AI-aanroep gaat gewoon door.
 */
class AiLogger
{
    /** Namespaces die bij het zoeken naar de aanroeper worden overgeslagen. */
    protected const SKIPPED_NAMESPACES = [
        'Dashed\\DashedAi\\',
        'Dashed\\DashedClaude\\',
        'Dashed\\DashedOpenai\\',
        'Illuminate\\',
        'Filament\\',
        'Livewire\\',
        'Laravel\\',
        'Spatie\\',
        'Pest\\',
        'PHPUnit\\',
    ];

    /**
     * @param  string  $provider  claude, openai, fal, deepl
     * @param  string  $kind  text, json, vision, image, image_edit, embedding, messages, stream, translation
     * @param  array{model?: ?string, prompt?: mixed, options?: array, site_id?: ?string, purpose?: ?string}  $context
     */
    public static function begin(string $provider, string $kind, array $context = []): AiCallLog
    {
        $options = (array) ($context['options'] ?? []);

        return new AiCallLog(
            provider: $provider,
            kind: $kind,
            model: $context['model'] ?? ($options['model'] ?? null),
            prompt: $context['prompt'] ?? null,
            options: $options,
            siteId: $context['site_id'] ?? self::currentSiteId(),
            purpose: $context['purpose'] ?? ($options['purpose'] ?? null),
            caller: self::detectCaller(),
            userId: rescue(fn () => auth()->id(), null, false),
        );
    }

    public static function currentSiteId(): ?string
    {
        $siteId = rescue(fn () => Sites::getActive(), null, false);

        return $siteId === '' || $siteId === null ? null : (string) $siteId;
    }

    /**
     * De eerste klasse in de aanroepstapel die niet bij de AI-laag of het
     * framework hoort: dat is de functie die de AI gebruikte (BolTitleGenerator,
     * CampaignPlanner, ChatAgentRunner). Alleen als terugval voor een
     * ontbrekende 'purpose'.
     */
    public static function detectCaller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $class = $frame['class'] ?? null;
            if (! $class || str_contains($class, '@anonymous') || str_ends_with($class, 'Facade')) {
                continue;
            }
            foreach (self::SKIPPED_NAMESPACES as $namespace) {
                if (str_starts_with($class, $namespace)) {
                    continue 2;
                }
            }

            return $class;
        }

        return null;
    }
}
