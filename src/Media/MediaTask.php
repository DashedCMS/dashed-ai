<?php

namespace Dashed\DashedAi\Media;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Dashed\DashedCore\Models\BackgroundTask;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Eén taak in het takenicoon van dashed-core voor een hele ronde AI-werk op
 * afbeeldingen (alt-teksten, tags). De ronde waaiert uit in een job per
 * afbeelding, mogelijk op meerdere workers tegelijk, dus de teller loopt met
 * een atomaire ophoging in de database en de laatste job claimt het afronden.
 *
 * Alles achter class_exists (een oudere dashed-core kent het model niet) en
 * binnen rescue(): de administratie mag het genereren nooit tegenhouden.
 *
 * Een job doet mee als hij een publieke eigenschap `taskId` heeft
 * (GenerateMediaAltText, GenerateMediaTags) en aan het eind van handle() en
 * in failed() advance() aanroept.
 */
class MediaTask
{
    public const TYPE = 'ai-media';

    public static function available(): bool
    {
        return class_exists(BackgroundTask::class);
    }

    /**
     * Zet een hele selectie klaar onder één taak. Geeft terug hoeveel jobs er
     * echt in de wachtrij kwamen: een afbeelding die al in de wachtrij stond
     * (het unieke slot van de job) telt niet mee, anders wacht de taak eeuwig
     * op een job die nooit komt.
     *
     * @param  array<int, object>  $jobs
     */
    public static function dispatchGrouped(string $label, array $jobs, Authenticatable|int|null $user = null): int
    {
        $taskId = self::begin($label, count($jobs), $user);
        $queued = 0;

        foreach ($jobs as $job) {
            if (self::dispatch($job, $taskId)) {
                $queued++;
            }
        }

        self::settle($taskId, $queued);

        return $queued;
    }

    /**
     * De taak openen met een voorlopig totaal (hooguit wat er klaargezet gaat
     * worden). settle() zet daarna het echte aantal.
     */
    public static function begin(string $label, int $estimate, Authenticatable|int|null $user = null): ?int
    {
        if ($estimate < 1 || ! self::available()) {
            return null;
        }

        // De jobs per afbeelding sturen zelf geen melding, dus een beheerder
        // die de ronde startte krijgt er aan het eind één in de bel.
        return rescue(fn () => BackgroundTask::begin(
            self::TYPE,
            $label,
            $estimate,
            $user,
            notify: $user !== null,
        )->getKey(), null, report: false);
    }

    /**
     * Eén job in de wachtrij zetten, met het unieke slot zoals dispatch() dat
     * doet, maar met de uitkomst erbij.
     */
    public static function dispatch(object $job, ?int $taskId, ?\DateTimeInterface $delay = null): bool
    {
        if ($taskId !== null && property_exists($job, 'taskId')) {
            $job->taskId = $taskId;
        }

        if ($delay !== null && method_exists($job, 'delay')) {
            $job->delay($delay);
        }

        if ($job instanceof ShouldBeUnique && ! (new UniqueLock(app(Cache::class)))->acquire($job)) {
            return false;
        }

        app(Dispatcher::class)->dispatch($job);

        return true;
    }

    /** Het echte aantal na het klaarzetten; staat alles al klaar, dan meteen af. */
    public static function settle(?int $taskId, int $total): void
    {
        if (! $taskId || ! self::available()) {
            return;
        }

        rescue(function () use ($taskId, $total): void {
            BackgroundTask::query()->whereKey($taskId)->active()->update(['total' => $total]);

            self::claimFinish($taskId);
        }, report: false);
    }

    /** Een afbeelding is afgehandeld, gelukt of niet. */
    public static function advance(?int $taskId): void
    {
        if (! $taskId || ! self::available()) {
            return;
        }

        rescue(function () use ($taskId): void {
            BackgroundTask::query()->whereKey($taskId)->active()->increment('done');

            self::claimFinish($taskId);
        }, report: false);
    }

    /**
     * De claim op de status voorkomt dat twee workers die tegelijk de laatste
     * zijn allebei afronden (en de beheerder twee meldingen krijgt).
     */
    private static function claimFinish(int $taskId): void
    {
        $claimed = BackgroundTask::query()
            ->whereKey($taskId)
            ->active()
            ->whereNotNull('total')
            ->whereColumn('done', '>=', 'total')
            ->update(['status' => BackgroundTask::STATUS_COMPLETED]);

        if ($claimed !== 1) {
            return;
        }

        $task = BackgroundTask::find($taskId);

        $task?->finish(__(':aantal afbeeldingen verwerkt.', ['aantal' => $task->total]));
    }
}
