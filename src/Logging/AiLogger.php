<?php

namespace Dashed\DashedAi\Logging;

use Illuminate\Database\Eloquent\Model;
use Dashed\DashedAi\Models\AiLog;
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
     * Gestapelde contextframes van within(): subject, purpose, batch.
     *
     * @var array<int, array<string, mixed>>
     */
    protected static array $frames = [];

    /**
     * Voert $callback uit met een context waar elke AI-aanroep erbinnen
     * automatisch mee wordt gelogd: het model waar het voor is (subject), het
     * doel (purpose) en een batch-sleutel. Een binnenste frame overschrijft de
     * sleutels van het buitenste; het frame verdwijnt na afloop altijd.
     *
     * @param  array{subject?: ?Model, purpose?: ?string, batch?: ?string}  $context
     */
    public static function within(array $context, callable $callback): mixed
    {
        self::$frames[] = array_replace(self::currentFrame(), array_intersect_key($context, array_flip(['subject', 'purpose', 'batch'])));

        try {
            return $callback();
        } finally {
            array_pop(self::$frames);
        }
    }

    /** @return array{subject?: ?Model, purpose?: ?string, batch?: ?string} */
    public static function currentFrame(): array
    {
        return self::$frames === [] ? [] : self::$frames[array_key_last(self::$frames)];
    }

    /**
     * Combineert expliciete context, opties en het huidige frame. Doel:
     * expliciet > options['purpose'] > frame. Subject en batch: expliciet > frame.
     *
     * @return array{subject: ?Model, purpose: ?string, batch: ?string}
     */
    public static function resolveContext(array $context, array $options = []): array
    {
        $frame = self::currentFrame();

        return [
            'subject' => array_key_exists('subject', $context) && $context['subject'] !== null ? $context['subject'] : ($frame['subject'] ?? null),
            'purpose' => $context['purpose'] ?? ($options['purpose'] ?? ($frame['purpose'] ?? null)),
            'batch' => $context['batch'] ?? ($frame['batch'] ?? null),
        ];
    }

    /** @return array{0: ?string, 1: ?int} morph-klasse en sleutel van het subject */
    protected static function subjectParts(mixed $subject): array
    {
        if (! $subject instanceof Model || ! $subject->getKey()) {
            return [null, null];
        }

        return [$subject->getMorphClass(), is_numeric($subject->getKey()) ? (int) $subject->getKey() : null];
    }

    /**
     * Schrijft één afgeronde rij rechtstreeks, voor diensten die niet via een
     * provider lopen (fal-wachtrij, Tripo, Places) of voor een nabeschrijving.
     * Honoreert het huidige frame. Slikt elke fout weg en geeft dan null.
     *
     * @param  array{model?: ?string, purpose?: ?string, subject?: ?Model, batch?: ?string, units?: ?float, unit_type?: ?string, cost_usd?: ?float, status?: string, error?: ?string, options?: array, estimated?: bool, created_at?: mixed, input_tokens?: int, output_tokens?: int, cache_read_tokens?: int, cache_write_tokens?: int, site_id?: ?string, prompt?: mixed, response?: mixed}  $data
     */
    public static function record(string $provider, string $kind, array $data = []): ?AiLog
    {
        return rescue(function () use ($provider, $kind, $data): AiLog {
            $options = (array) ($data['options'] ?? []);
            $resolved = self::resolveContext($data, $options);
            [$subjectType, $subjectId] = self::subjectParts($resolved['subject']);

            $status = (string) ($data['status'] ?? AiLog::STATUS_SUCCESS);
            $model = $data['model'] ?? ($options['model'] ?? null);
            $input = (int) ($data['input_tokens'] ?? 0);
            $output = (int) ($data['output_tokens'] ?? 0);
            $cacheRead = (int) ($data['cache_read_tokens'] ?? 0);
            $cacheWrite = (int) ($data['cache_write_tokens'] ?? 0);
            $units = isset($data['units']) ? (float) $data['units'] : null;

            $cost = $data['cost_usd'] ?? null;
            if ($cost === null && $status === AiLog::STATUS_SUCCESS) {
                $cost = AiPricing::cost($provider, $model, $input, $output, $cacheRead, $cacheWrite, $units);
            }

            $storeContent = AiLogSettings::storeContent();
            $backfill = isset($data['created_at']);

            $log = AiLog::create([
                'site_id' => $data['site_id'] ?? self::currentSiteId(),
                'user_id' => ($id = rescue(fn () => auth()->id(), null, false)) && is_numeric($id) ? (int) $id : null,
                'provider' => $provider,
                'model' => $model,
                'kind' => $kind,
                'purpose' => $resolved['purpose'] ? mb_substr((string) $resolved['purpose'], 0, 255) : null,
                'caller' => null,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'batch' => $resolved['batch'] ? mb_substr((string) $resolved['batch'], 0, 64) : null,
                'status' => $status,
                'input_tokens' => max(0, $input),
                'output_tokens' => max(0, $output),
                'cache_read_tokens' => max(0, $cacheRead),
                'cache_write_tokens' => max(0, $cacheWrite),
                'units' => $units,
                'unit_type' => $data['unit_type'] ?? null,
                'cost_usd' => $cost,
                'estimated' => (bool) ($data['estimated'] ?? false),
                'duration_ms' => $data['duration_ms'] ?? null,
                'prompt' => $storeContent ? AiCallLog::stringify($data['prompt'] ?? null) : null,
                'response' => $storeContent ? AiCallLog::stringify($data['response'] ?? null) : null,
                'options' => AiCallLog::summarizeOptions($options),
                'error' => ! empty($data['error']) ? mb_substr((string) $data['error'], 0, 5000) : null,
                'created_at' => $data['created_at'] ?? now(),
            ]);

            if (! $backfill && $log->cost_usd !== null && $log->cost_usd > 0) {
                AiBudget::check();
            }

            return $log;
        }, null, false);
    }

    /**
     * Koppelt alle nog ongekoppelde rijen met deze batch-sleutel aan het
     * subject: voor aanroepen die gedaan zijn voordat het model bestond.
     */
    public static function linkBatch(string $batch, Model $subject): int
    {
        [$type, $id] = self::subjectParts($subject);
        if ($type === null || $id === null || $batch === '') {
            return 0;
        }

        return (int) rescue(fn () => AiLog::query()
            ->where('batch', $batch)
            ->whereNull('subject_type')
            ->update(['subject_type' => $type, 'subject_id' => $id]), 0, false);
    }

    /**
     * @param  string  $provider  claude, openai, fal, deepl
     * @param  string  $kind  text, json, vision, image, image_edit, embedding, messages, stream, translation
     * @param  array{model?: ?string, prompt?: mixed, options?: array, site_id?: ?string, purpose?: ?string, subject?: ?Model, batch?: ?string}  $context
     */
    public static function begin(string $provider, string $kind, array $context = []): AiCallLog
    {
        $options = (array) ($context['options'] ?? []);
        $resolved = self::resolveContext($context, $options);
        [$subjectType, $subjectId] = self::subjectParts($resolved['subject']);

        return new AiCallLog(
            provider: $provider,
            kind: $kind,
            model: $context['model'] ?? ($options['model'] ?? null),
            prompt: $context['prompt'] ?? null,
            options: $options,
            siteId: $context['site_id'] ?? self::currentSiteId(),
            purpose: $resolved['purpose'],
            caller: self::detectCaller(),
            userId: rescue(fn () => auth()->id(), null, false),
            subjectType: $subjectType,
            subjectId: $subjectId,
            batch: $resolved['batch'],
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
