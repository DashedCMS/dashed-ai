<?php

namespace Dashed\DashedAi\Logging;

use Throwable;
use Dashed\DashedAi\Models\AiLog;

/**
 * Eén lopende aanroep. Vul verbruik en antwoord aan en sluit af met
 * succeed() of fail(); daarna wordt er precies één rij geschreven.
 */
class AiCallLog
{
    protected float $startedAt;

    protected int $inputTokens = 0;

    protected int $outputTokens = 0;

    protected int $cacheReadTokens = 0;

    protected int $cacheWriteTokens = 0;

    protected ?float $units = null;

    protected ?string $unitType = null;

    protected mixed $response = null;

    protected bool $closed = false;

    public function __construct(
        protected string $provider,
        protected string $kind,
        protected ?string $model,
        protected mixed $prompt,
        protected array $options,
        protected ?string $siteId,
        protected ?string $purpose,
        protected ?string $caller,
        protected int|string|null $userId,
    ) {
        $this->startedAt = microtime(true);
    }

    public function model(?string $model): static
    {
        if ($model) {
            $this->model = $model;
        }

        return $this;
    }

    public function tokens(int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): static
    {
        $this->inputTokens = max(0, $input);
        $this->outputTokens = max(0, $output);
        $this->cacheReadTokens = max(0, $cacheRead);
        $this->cacheWriteTokens = max(0, $cacheWrite);

        return $this;
    }

    /** Leest usage.* uit een Anthropic- of OpenAI-antwoord. */
    public function usage(?array $usage): static
    {
        $usage = $usage ?? [];

        return $this->tokens(
            (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? $usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }

    /** Verbruik in eenheden die geen tokens zijn: image, megapixel, characters. */
    public function units(float $units, string $unitType): static
    {
        $this->units = $units;
        $this->unitType = $unitType;

        return $this;
    }

    public function response(mixed $response): static
    {
        $this->response = $response;

        return $this;
    }

    public function succeed(): ?AiLog
    {
        return $this->close(AiLog::STATUS_SUCCESS, null);
    }

    public function fail(Throwable|string|null $error): ?AiLog
    {
        $message = $error instanceof Throwable ? $error::class . ': ' . $error->getMessage() : $error;

        return $this->close(AiLog::STATUS_ERROR, $message);
    }

    protected function close(string $status, ?string $error): ?AiLog
    {
        if ($this->closed) {
            return null;
        }
        $this->closed = true;

        return rescue(function () use ($status, $error): AiLog {
            $storeContent = AiLogSettings::storeContent();
            $units = $this->units;
            if ($units === null && $this->unitType === null && $this->kind === 'translation') {
                $units = 0.0;
            }

            $log = AiLog::create([
                'site_id' => $this->siteId,
                'user_id' => is_numeric($this->userId) ? (int) $this->userId : null,
                'provider' => $this->provider,
                'model' => $this->model,
                'kind' => $this->kind,
                'purpose' => $this->purpose ? mb_substr($this->purpose, 0, 255) : null,
                'caller' => $this->caller ? mb_substr($this->caller, 0, 255) : null,
                'status' => $status,
                'input_tokens' => $this->inputTokens,
                'output_tokens' => $this->outputTokens,
                'cache_read_tokens' => $this->cacheReadTokens,
                'cache_write_tokens' => $this->cacheWriteTokens,
                'units' => $units,
                'unit_type' => $this->unitType,
                'cost_usd' => $status === AiLog::STATUS_SUCCESS ? AiPricing::cost(
                    $this->provider,
                    $this->model,
                    $this->inputTokens,
                    $this->outputTokens,
                    $this->cacheReadTokens,
                    $this->cacheWriteTokens,
                    $units,
                ) : null,
                'duration_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
                'prompt' => $storeContent ? $this->truncate(self::stringify($this->prompt)) : null,
                'response' => $storeContent ? $this->truncate(self::stringify($this->response)) : null,
                'options' => self::summarizeOptions($this->options),
                'error' => $error ? mb_substr($error, 0, 5000) : null,
                'created_at' => now(),
            ]);

            if ($log->cost_usd !== null && $log->cost_usd > 0) {
                AiBudget::check();
            }

            return $log;
        }, null, false);
    }

    protected function truncate(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $max = AiLogSettings::maxContentChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . "\n\n[" . __('ingekort, :aantal tekens weggelaten', ['aantal' => mb_strlen($text) - $max]) . ']';
    }

    /**
     * Prompt of antwoord als tekst. Een berichtenlijst wordt als JSON
     * bewaard, maar base64-afbeeldingen worden vervangen door een korte
     * aanduiding: die horen niet in het logboek.
     */
    public static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        $value = self::stripBinary($value);

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    protected static function stripBinary(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (($value['type'] ?? null) === 'base64' && isset($value['data']) && is_string($value['data'])) {
            $value['data'] = '[base64, ' . number_format(strlen($value['data']) / 1024, 0) . ' kB]';
        }
        if (isset($value['image_url']['url']) && is_string($value['image_url']['url']) && str_starts_with($value['image_url']['url'], 'data:')) {
            $value['image_url']['url'] = '[data-url, ' . number_format(strlen($value['image_url']['url']) / 1024, 0) . ' kB]';
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::stripBinary($item);
        }

        return $value;
    }

    /**
     * Alleen de instellingen van de aanroep, geen inhoud: scalaire opties,
     * de lengte van de system-prompt en de namen van de tools.
     */
    public static function summarizeOptions(array $options): ?array
    {
        $summary = [];
        foreach ($options as $key => $value) {
            if ($key === 'log_kind') {
                continue;
            }
            if (in_array($key, ['system', 'prompt', 'messages', 'image_urls', 'image_url'], true)) {
                if (is_string($value)) {
                    $summary[$key . '_chars'] = mb_strlen($value);
                }

                continue;
            }
            if ($key === 'tools' && is_array($value)) {
                $summary['tools'] = array_values(array_filter(array_map(fn ($t) => is_array($t) ? ($t['name'] ?? null) : null, $value)));

                continue;
            }
            if (is_scalar($value) || $value === null) {
                $summary[$key] = $value;
            }
        }

        return $summary === [] ? null : $summary;
    }
}
