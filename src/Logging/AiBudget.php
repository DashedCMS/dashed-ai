<?php

namespace Dashed\DashedAi\Logging;

use Dashed\DashedAi\Models\AiLog;
use Dashed\DashedCore\Models\User;
use Illuminate\Support\Facades\Mail;
use Dashed\DashedAi\Mail\AiBudgetAlertMail;
use Dashed\DashedCore\Models\Customsetting;

/**
 * Maandbudget voor AI-kosten. Na elke gelogde aanroep met kosten wordt het
 * maandtotaal vergeleken met het budget uit Instellingen, AI; bij elke
 * drempel (standaard 80% en 100%) gaat er één keer per maand een mail naar
 * de beheerders. Aanroepen gaan gewoon door: het budget waarschuwt, het
 * blokkeert niet.
 */
class AiBudget
{
    public static function monthToDateUsd(?\DateTimeInterface $moment = null): float
    {
        return (float) AiLog::query()->inMonth($moment)->sum('cost_usd');
    }

    public static function check(): void
    {
        $budget = AiLogSettings::monthlyBudgetUsd();
        if ($budget <= 0) {
            return;
        }

        $spent = self::monthToDateUsd();
        $percentage = ($spent / $budget) * 100;

        foreach (array_reverse(AiLogSettings::budgetAlertPercentages()) as $threshold) {
            if ($percentage < $threshold) {
                continue;
            }

            $flag = self::flagKey($threshold);
            if (Customsetting::get($flag)) {
                break;
            }

            Customsetting::set($flag, now()->toDateTimeString());
            self::notify($threshold, $spent, $budget);

            break;
        }
    }

    public static function flagKey(int $threshold, ?\DateTimeInterface $moment = null): string
    {
        $moment = $moment ? \Carbon\Carbon::instance($moment) : now();

        return 'ai_budget_alert_' . $moment->format('Y_m') . '_' . $threshold;
    }

    protected static function notify(int $threshold, float $spent, float $budget): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        rescue(fn () => Mail::to($recipients)->send(new AiBudgetAlertMail($threshold, $spent, $budget)), null, false);
    }

    /**
     * Dezelfde regel als de factuurmeldingen: de ingestelde adressen van de
     * actieve site, anders alle superadmins.
     *
     * @return array<int, string>
     */
    public static function recipients(): array
    {
        $emails = [];
        foreach ((array) (Customsetting::get('notification_invoice_emails', null, []) ?: []) as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && ! in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
        if ($emails !== []) {
            return $emails;
        }

        return User::query()
            ->where('role', 'superadmin')
            ->whereNotNull('email')
            ->orderBy('id')
            ->pluck('email')
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
