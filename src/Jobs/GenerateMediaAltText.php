<?php

namespace Dashed\DashedAi\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Dashed\DashedAi\AiManager;
use Dashed\DashedAi\Facades\Ai;
use Dashed\DashedAi\Media\MediaVision;
use Dashed\DashedAi\Enums\AiCapability;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Queue\InteractsWithQueue;
use Dashed\DashedFiles\Classes\MediaTexts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * De alt-tekst van één afbeelding, voor alle gevraagde talen in één
 * AI-aanroep. Heeft de spiegeltaal al tekst en ontbreken alleen andere
 * talen, dan is het een tekstaanroep die vertaalt (zonder beeld, goedkoop);
 * anders een vision-aanroep.
 *
 * `automatic_alt_tries` telt de vision-aanroepen die niets bruikbaars
 * opleverden: een afbeelding waar de provider niets van maakt blijft dat
 * doen. Na drie keer doet de taak niets meer, tot iemand "Opnieuw proberen"
 * kiest of bewust overschrijft. Een storing bij de provider (een exception:
 * verlopen sleutel, te veel aanvragen) telt niet, een geslaagde aanroep zet
 * de teller terug, en vertalen of alleen tags tellen nooit. De taak plant
 * zichzelf nooit opnieuw in.
 *
 * Met `overwrite` wordt een taal pas overschreven als er voor die taal een
 * bruikbaar antwoord is; er wordt vooraf niets gewist.
 */
class GenerateMediaAltText implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const MAX_TRIES = 3;

    public const PURPOSE = 'media_alt_text';

    // Binnen de standaard retry_after van 90 seconden: één AI-aanroep van
    // hooguit HTTP_TIMEOUT, plus het lezen van het beeld en het wegschrijven.
    public $timeout = 85;

    /** De HTTP-time-out van de AI-aanroep in deze taak (de providers zetten zelf 120 s). */
    public const HTTP_TIMEOUT = 60;

    public $uniqueFor = 900;

    public $tries = 1;

    /**
     * @param  array<int, string>|null  $locales  null: alle talen van de installatie
     * @param  bool  $withTags  in dezelfde vision-aanroep ook tags voorstellen
     */
    public function __construct(
        public int $itemId,
        public ?array $locales = null,
        public bool $overwrite = false,
        public bool $withTags = false,
    ) {
    }

    public function uniqueId(): string
    {
        return 'generate-media-alt-text-' . $this->itemId;
    }

    public function handle(): void
    {
        // Een oudere dashed-files kent geen teksten per taal: dan niets doen.
        if (! MediaVision::available()) {
            return;
        }

        $library = app(MediaLibrary::class);
        // find() kent geen items in de prullenbak en geen items zonder bestand.
        $item = $library->find($this->itemId);

        // Bovenaan, wat de aanroeper ook al filterde: geen chatbijlage, niets uit `chat` of `orders`.
        if (! $item || ! MediaVision::maySend($item) || ! in_array((string) $item->file()?->mime_type, MediaVision::MIME_TYPES, true)) {
            return;
        }

        $all = MediaTexts::locales();
        $wanted = $this->locales === null ? $all : array_values(array_intersect($all, $this->locales));
        $have = $item->translations('alt');
        $targets = $this->overwrite ? $wanted : MediaTexts::missing($have, $wanted);

        if ($targets === [] && ! $this->withTags) {
            return;
        }

        $first = MediaTexts::firstLocale();
        $source = $have[$first] ?? null;
        // Vertalen kan alleen als de spiegeltaal blijft staan en er geen beeld nodig is voor tags.
        $translate = ! $this->withTags && $source !== null && ! in_array($first, $targets, true);

        // Geen tone of voice: een alt-tekst beschrijft wat er te zien is, en de brief kost bij elke aanroep tokens.
        $options = ['purpose' => self::PURPOSE, 'skip_tone_of_voice' => true];

        if ($translate) {
            if (! Ai::default(AiCapability::Json)) {
                return;
            }

            try {
                $answer = AiManager::withHttpTimeout(self::HTTP_TIMEOUT, fn () => Ai::json($this->translatePrompt($source, $first, $targets), $options));
            } catch (Throwable $exception) {
                report($exception);

                return;
            }

            $this->write($library, $item, $this->pick($answer, $targets));

            return;
        }

        if (! Ai::default(AiCapability::Vision)) {
            return;
        }

        // Alleen-tags telt niet mee en wordt ook niet door de teller tegengehouden.
        $counts = $targets !== [];

        if ($counts && ! $this->overwrite && (int) $item->automatic_alt_tries >= self::MAX_TRIES) {
            if (! $this->withTags) {
                return;
            }

            // De tags mogen nog wel; de alt-tekst probeert de AI niet meer uit zichzelf.
            $targets = [];
            $counts = false;
        }

        $image = MediaVision::imageFor($item);

        if ($image === null) {
            return;
        }

        try {
            $answer = AiManager::withHttpTimeout(self::HTTP_TIMEOUT, fn () => Ai::vision($this->visionPrompt($targets), $image['data'], $image['mime'], $options));
        } catch (Throwable $exception) {
            // Een storing bij de provider zegt niets over de afbeelding: niet tellen.
            report($exception);

            return;
        }

        $decoded = MediaVision::decode($answer);
        $tags = [];

        if ($decoded === null) {
            $plain = MediaVision::plainAnswer($answer, $targets, MediaTexts::AI_MAX);
            $texts = $plain === null ? [] : [$targets[0] => MediaTexts::clip($plain)];
        } else {
            $texts = $this->pick($this->withTags ? ($decoded['alt'] ?? null) : $decoded, $targets);
            $tags = $this->withTags ? MediaVision::cleanTags($decoded['tags'] ?? null) : [];
        }

        $written = $this->write($library, $item, $texts);

        if ($tags !== []) {
            $library->setTags($item, $tags, 'add');
        }

        if (! $counts) {
            return;
        }

        $counter = MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey());

        // Als query en niet via het model: de teller mag de cache van het item niet legen.
        if ($texts === []) {
            $counter->increment('automatic_alt_tries');
        } elseif ($written !== [] || $this->overwrite) {
            $counter->where('automatic_alt_tries', '>', 0)->update(['automatic_alt_tries' => 0]);
        }
    }

    /**
     * Schrijft via de ene deur van de bibliotheek, die de rij opnieuw leest
     * en vergrendelt. Zonder overschrijven blijft een taal staan die een
     * redacteur intussen (terwijl de AI aan het antwoorden was) zelf invulde.
     *
     * @param  array<string, string>  $texts
     * @return array<int, string>
     */
    private function write(MediaLibrary $library, MediaItem $item, array $texts): array
    {
        return $texts === [] ? [] : (array) $library->setTexts($item, 'alt', $texts, ! $this->overwrite);
    }

    /**
     * Alleen de gevraagde talen, schoon en op lengte.
     *
     * @param  array<int, string>  $targets
     * @return array<string, string>
     */
    private function pick(mixed $answer, array $targets): array
    {
        $texts = [];

        foreach ($targets as $locale) {
            $value = is_array($answer) ? ($answer[$locale] ?? null) : null;
            $value = is_string($value) ? MediaTexts::clip($value) : '';

            if ($value !== '') {
                $texts[$locale] = $value;
            }
        }

        return $texts;
    }

    /**
     * @param  array<int, string>  $targets
     */
    private function visionPrompt(array $targets): string
    {
        $shape = json_encode(array_fill_keys($targets, '...'), JSON_UNESCAPED_UNICODE);
        $max = MediaTexts::AI_MAX;

        $prompt = $targets === []
            ? ''
            : 'Schrijf een alt-tekst voor deze afbeelding, voor op een website, in elk van deze talen: ' . MediaVision::describeLocales($targets) . '. '
                . "Per taal een korte, beschrijvende tekst van hooguit {$max} tekens die samenvat wat er te zien is. Geen HTML, geen aanhalingstekens eromheen en niet beginnen met \"afbeelding van\". ";

        if (! $this->withTags) {
            return $prompt . 'Antwoord met alleen geldige JSON in precies deze vorm, zonder uitleg en zonder codeblok: ' . $shape;
        }

        return $prompt . MediaVision::tagInstruction() . ' '
            . 'Antwoord met alleen geldige JSON in precies deze vorm, zonder uitleg en zonder codeblok: '
            . json_encode(['alt' => (object) array_fill_keys($targets, '...'), 'tags' => ['...']], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<int, string>  $targets
     */
    private function translatePrompt(string $source, string $from, array $targets): string
    {
        $max = MediaTexts::AI_MAX;

        return 'Vertaal deze alt-tekst van een afbeelding op een website uit het ' . MediaVision::describeLocales([$from]) . ' naar: ' . MediaVision::describeLocales($targets) . '. '
            . "Per taal hooguit {$max} tekens, zonder HTML en zonder aanhalingstekens eromheen. Vertaal alleen; voeg niets toe. "
            . 'Antwoord met alleen geldige JSON in precies deze vorm: ' . json_encode(array_fill_keys($targets, '...'), JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'De alt-tekst: ' . json_encode($source, JSON_UNESCAPED_UNICODE);
    }
}
