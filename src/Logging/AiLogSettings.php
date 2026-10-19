<?php

namespace Dashed\DashedAi\Logging;

use Dashed\DashedCore\Models\Customsetting;

/**
 * De instellingen van het AI-logboek: eerst Instellingen, AI, anders de
 * config. Gelezen zonder site, want het logboek is installatiebreed.
 */
class AiLogSettings
{
    public static function storeContent(): bool
    {
        $value = self::setting('ai_log_store_content');

        if ($value === null || $value === '') {
            return (bool) config('dashed-ai.log.store_content', true);
        }

        return (bool) $value;
    }

    public static function maxContentChars(): int
    {
        $value = (int) (self::setting('ai_log_max_content_chars') ?: 0);

        return $value > 0 ? $value : (int) config('dashed-ai.log.max_content_chars', 20000);
    }

    public static function usdEurRate(): float
    {
        $value = (float) str_replace(',', '.', (string) (self::setting('ai_usd_eur_rate') ?: ''));

        return $value > 0 ? $value : (float) config('dashed-ai.log.usd_eur_rate', 0.92);
    }

    /** 0 betekent: geen budgetbewaking. */
    public static function monthlyBudgetUsd(): float
    {
        $value = self::setting('ai_monthly_budget_usd');

        if ($value === null || $value === '') {
            return (float) config('dashed-ai.log.monthly_budget_usd', 0);
        }

        return max(0, (float) str_replace(',', '.', (string) $value));
    }

    /** @return array<int, int> */
    public static function budgetAlertPercentages(): array
    {
        $percentages = array_map('intval', (array) config('dashed-ai.log.budget_alert_percentages', [80, 100]));
        sort($percentages);

        return array_values(array_filter($percentages, fn (int $p) => $p > 0));
    }

    protected static function setting(string $key): mixed
    {
        return rescue(fn () => Customsetting::get($key), null, false);
    }
}
