<?php

namespace Dashed\DashedAi\Jobs;

use Illuminate\Bus\Queueable;
use Dashed\DashedAi\AiManager;
use Dashed\DashedAi\Facades\Ai;
use Dashed\DashedAi\Media\MediaVision;
use Dashed\DashedAi\Enums\AiCapability;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Stelt voor één afbeelding hooguit acht tags voor, in de eerste taal, en
 * voegt ze toe aan wat er al staat. Eén vision-aanroep. De meest gebruikte
 * bestaande tags gaan mee in de prompt, zodat het model die hergebruikt in
 * plaats van bij elk bestand een nieuwe variant te verzinnen.
 */
class GenerateMediaTags implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PURPOSE = 'media_tags';

    public const MAX_TAGS = 8;

    // Binnen de standaard retry_after van 90 seconden: één vision-aanroep van
    // hooguit GenerateMediaAltText::HTTP_TIMEOUT, plus lezen en wegschrijven.
    public $timeout = 85;

    public $uniqueFor = 900;

    public $tries = 1;

    public function __construct(public int $itemId)
    {
    }

    public function uniqueId(): string
    {
        return 'generate-media-tags-' . $this->itemId;
    }

    public function handle(): void
    {
        if (! MediaVision::available() || ! Ai::default(AiCapability::Vision)) {
            return;
        }

        $library = app(MediaLibrary::class);
        $item = $library->find($this->itemId);

        // Bovenaan, wat de aanroeper ook al filterde: geen chatbijlage, niets uit `chat` of `orders`.
        if (! $item || ! MediaVision::maySend($item)) {
            return;
        }

        $image = MediaVision::imageFor($item);

        if ($image === null) {
            return;
        }

        $prompt = MediaVision::tagInstruction(self::MAX_TAGS)
            . ' Antwoord met alleen een geldige JSON-lijst van tekst, zonder uitleg en zonder codeblok, bijvoorbeeld: ["stoel", "hout", "woonkamer"]';

        try {
            $answer = AiManager::withHttpTimeout(GenerateMediaAltText::HTTP_TIMEOUT, fn () => Ai::vision($prompt, $image['data'], $image['mime'], ['purpose' => self::PURPOSE, 'skip_tone_of_voice' => true]));
        } catch (\Throwable $exception) {
            report($exception);

            return;
        }

        $decoded = MediaVision::decode($answer);

        // Ook de vorm {"tags": [...]} telt.
        $tags = MediaVision::cleanTags(is_array($decoded) && ! array_is_list($decoded) ? ($decoded['tags'] ?? null) : $decoded, self::MAX_TAGS);

        if ($tags !== []) {
            $library->setTags($item, $tags, 'add');
        }
    }
}
