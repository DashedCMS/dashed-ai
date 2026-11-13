<?php

namespace Dashed\DashedAi\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * De oude naam van de alt-teksttaak. Aanroepers in klantprojecten en al
 * ingeplande taken dragen deze klasse; het werk doet GenerateMediaAltText,
 * voor alle talen die nog leeg zijn. Plant zichzelf niet meer opnieuw in;
 * hij zet alleen die ene nieuwe taak klaar.
 */
class CreateAltTextForMediaItem implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    // Zet alleen één taak klaar.
    public $timeout = 30;

    public $uniqueFor = 900;

    public const MAX_TRIES = GenerateMediaAltText::MAX_TRIES;

    // Alleen het id, geen model: met een model komt de klassenaam in de
    // wachtrij te staan. De naam van de eigenschap blijft, omdat al
    // ingeplande taken hem onder die naam dragen.
    public $mediaItem;

    public function __construct(int|object $mediaItem)
    {
        $this->mediaItem = is_object($mediaItem) ? (int) $mediaItem->id : $mediaItem;
    }

    public function uniqueId(): string
    {
        return 'create-alt-text-for-media-item-'.$this->mediaItemId();
    }

    // Een taak van voor deze wijziging draagt hier nog een object met een id.
    public function mediaItemId(): int
    {
        return (int) (is_object($this->mediaItem) ? $this->mediaItem->id : $this->mediaItem);
    }

    public function handle(): void
    {
        // Doorgeven aan de wachtrij en niet zelf uitvoeren: deze taak heeft een
        // eigen uniek slot, en zou anders tegelijk kunnen lopen met een
        // GenerateMediaAltText voor hetzelfde item. Het unieke slot van de
        // nieuwe taak houdt een tweede tegen. Aanroepers (klantprojecten,
        // oudere pakketten, al ingeplande taken) zetten deze taak zelf in de
        // wachtrij en verwachten geen antwoord in hetzelfde verzoek.
        GenerateMediaAltText::dispatch($this->mediaItemId());
    }
}
