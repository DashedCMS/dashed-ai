<?php

namespace Dashed\DashedAi\Logging;

/**
 * Rekent de geschatte kosten van een aanroep uit op basis van de prijstabel
 * in config('dashed-ai.pricing'). Geeft null terug voor een onbekend model,
 * zodat het logboek "onbekend" kan tonen in plaats van een valse nul.
 */
class AiPricing
{
    /** @return array<string, float|string>|null */
    public static function priceFor(string $provider, ?string $model): ?array
    {
        $table = (array) config("dashed-ai.pricing.{$provider}", []);
        if ($table === [] || $model === null || $model === '') {
            return null;
        }

        if (isset($table[$model])) {
            return self::normalize($table[$model]);
        }

        $best = null;
        foreach ($table as $key => $price) {
            if (str_starts_with($model, (string) $key) && ($best === null || strlen((string) $key) > strlen($best))) {
                $best = (string) $key;
            }
        }

        return $best === null ? null : self::normalize($table[$best]);
    }

    /**
     * Kosten in USD. Tokens worden tegen de prijs per miljoen gerekend;
     * eenheden (afbeeldingen, megapixels, tekens) tegen per_unit.
     */
    public static function cost(
        string $provider,
        ?string $model,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
        ?float $units = null,
    ): ?float {
        $price = self::priceFor($provider, $model);
        if ($price === null) {
            return null;
        }

        $cost = 0.0;

        if (isset($price['per_unit'])) {
            if ($units === null) {
                return null;
            }
            $divisor = ($price['unit'] ?? null) === 'million_characters' ? 1_000_000 : 1;
            $cost += (float) $price['per_unit'] * $units / $divisor;
        }

        $cost += ($inputTokens / 1_000_000) * (float) ($price['input'] ?? 0);
        $cost += ($outputTokens / 1_000_000) * (float) ($price['output'] ?? 0);
        $cost += ($cacheReadTokens / 1_000_000) * (float) ($price['cache_read'] ?? 0);
        $cost += ($cacheWriteTokens / 1_000_000) * (float) ($price['cache_write'] ?? 0);

        return round($cost, 6);
    }

    /** @return array<string, float|string> */
    protected static function normalize(mixed $price): array
    {
        if (is_numeric($price)) {
            return ['per_unit' => (float) $price, 'unit' => 'unit'];
        }

        return (array) $price;
    }
}
