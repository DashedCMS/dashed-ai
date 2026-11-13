<?php

namespace Dashed\DashedAi\Jobs;

use Illuminate\Bus\Queueable;
use Dashed\DashedAi\Facades\Ai;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Log;
use Dashed\DashedAi\Media\MediaVision;
use Illuminate\Queue\SerializesModels;
use Dashed\DashedAi\Enums\AiCapability;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\InteractsWithQueue;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * De bulkronde. Elke taak is een betaalde AI-aanroep, daarom een plafond per
 * ronde (config `dashed-ai.media.alt_text_bulk_max`); wat overblijft komt in
 * een volgende ronde.
 *
 * Zonder overschrijven: elke afbeelding die in minstens één taal geen
 * alt-tekst heeft. Wat gevuld is valt vanzelf uit de lijst, wat drie keer
 * niets opleverde ook (dat staat apart op de AI-instellingen).
 *
 * Met overschrijven: elke afbeelding, ook met een alt-tekst. Er wordt vooraf
 * NIETS gewist: elke taak overschrijft pas bij een bruikbaar antwoord, en
 * alleen de talen waarvoor hij dat kreeg. Omdat een overschreven afbeelding
 * niet uit de lijst valt onthoudt de ronde het laatst verwerkte id; de
 * volgende ronde gaat daar verder, en na de laatste begint het opnieuw.
 */
class CreateAltTextsForAllMediaItems implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CURSOR_SETTING = 'media_alt_text_overwrite_cursor';

    // Zet alleen taken klaar (hooguit alt_text_bulk_max, standaard 500, in
    // porties van 200 id's), binnen de standaard retry_after van 90 seconden.
    // Een heel hoog plafond stopt na BUDGET_SECONDS; de rest is voor een volgende ronde.
    public $timeout = 85;

    public const BUDGET_SECONDS = 60;

    public $uniqueFor = 1200;

    /** Wat de laatste ronde in dit proces klaarzette en liet liggen, voor wie de taak synchroon draait. */
    public int $queued = 0;

    public int $remaining = 0;

    public function __construct(public bool $overwriteExisting = false)
    {
    }

    public function uniqueId(): string
    {
        return 'create-alt-texts-for-all-media-items';
    }

    public static function maxPerRun(): int
    {
        return max(1, (int) config('dashed-ai.media.alt_text_bulk_max', 500));
    }

    /**
     * Elke afbeelding waar de AI iets mee kan en mag: jpg, png of webp, niet
     * in de prullenbak (de scope van het model laat die weg), en nooit een
     * bijlage van een bezoeker of een bestelling.
     */
    public static function sendableQuery(): Builder
    {
        $query = MediaItem::query()->ofMimeTypes(MediaVision::MIME_TYPES);

        return \Dashed\DashedFiles\Classes\MediaPrivacy::exclude($query);
    }

    /**
     * Afbeeldingen die in minstens één taal leeg zijn, zolang de AI het niet
     * al drie keer vergeefs probeerde.
     */
    public static function pendingQuery(): Builder
    {
        return self::sendableQuery()
            ->missingAltTextInAnyLocale()
            ->where(fn (Builder $query) => $query->whereNull('automatic_alt_tries')->orWhere('automatic_alt_tries', '<', GenerateMediaAltText::MAX_TRIES));
    }

    /** Afbeeldingen waar de AI het na drie pogingen opgaf en die nog een taal missen. */
    public static function givenUpQuery(): Builder
    {
        return self::sendableQuery()
            ->missingAltTextInAnyLocale()
            ->where('automatic_alt_tries', '>=', GenerateMediaAltText::MAX_TRIES);
    }

    /** Wat een ronde met overschrijven nog voor zich heeft: alles na het laatst verwerkte id. */
    public static function overwriteQuery(): Builder
    {
        return self::sendableQuery()->where('id', '>', self::cursor());
    }

    public static function cursor(): int
    {
        return max(0, (int) rescue(fn () => Customsetting::get(self::CURSOR_SETTING, (string) Sites::getFirstSite()['id'], '0'), 0, false));
    }

    public static function setCursor(int $id): void
    {
        rescue(fn () => Customsetting::set(self::CURSOR_SETTING, (string) max(0, $id), (string) Sites::getFirstSite()['id']));
    }

    /**
     * Hoeveel een ronde nu zou klaarzetten en hoeveel er daarna overblijven,
     * voor de bevestiging vooraf.
     *
     * @return array{now: int, remaining: int, resumed: bool}
     */
    public static function preview(bool $overwrite): array
    {
        if (! MediaVision::available()) {
            return ['now' => 0, 'remaining' => 0, 'resumed' => false];
        }

        $total = ($overwrite ? self::overwriteQuery() : self::pendingQuery())->count();
        $resumed = $overwrite && self::cursor() > 0;

        // De vorige ronde eindigde precies op het laatste item: deze begint opnieuw.
        if ($overwrite && $total === 0 && $resumed) {
            $total = self::sendableQuery()->count();
            $resumed = false;
        }

        $now = min($total, self::maxPerRun());

        return ['now' => $now, 'remaining' => $total - $now, 'resumed' => $resumed];
    }

    public function handle(): void
    {
        // Een oudere dashed-files kent geen teksten per taal: dan niets doen.
        if (! MediaVision::available() || ! Ai::default(AiCapability::Vision)) {
            return;
        }

        $max = self::maxPerRun();
        $overwrite = $this->overwriteExisting;

        if ($overwrite && self::cursor() > 0 && ! self::overwriteQuery()->exists()) {
            self::setCursor(0);
        }

        $total = ($overwrite ? self::overwriteQuery() : self::pendingQuery())->count();
        $queued = 0;
        $last = 0;
        $started = microtime(true);

        // Alleen id's, in porties: nooit de hele tabel in het geheugen.
        ($overwrite ? self::overwriteQuery() : self::pendingQuery())->select('id')->toBase()->chunkById(200, function ($rows) use (&$queued, &$last, $max, $overwrite, $started) {
            foreach ($rows as $row) {
                if ($queued >= $max || microtime(true) - $started > self::BUDGET_SECONDS) {
                    return false;
                }

                // Gespreid, zodat de provider niet in één klap alles krijgt.
                GenerateMediaAltText::dispatch((int) $row->id, null, $overwrite)->delay(now()->addSeconds($queued * 3));
                $queued++;
                $last = (int) $row->id;
            }

            return $queued < $max && microtime(true) - $started <= self::BUDGET_SECONDS;
        });

        $this->queued = $queued;
        $this->remaining = max(0, $total - $queued);

        if ($overwrite) {
            // Klaar met de hele lijst: de volgende keer begint opnieuw bij het begin.
            self::setCursor($this->remaining === 0 ? 0 : $last);
        }

        Log::info('Alt-teksten: bulkronde klaargezet', ['overwrite' => $overwrite, 'queued' => $queued, 'remaining' => $this->remaining, 'max' => $max]);
    }
}
