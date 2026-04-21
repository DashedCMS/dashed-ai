<?php

namespace Dashed\DashedAi\Jobs;

use Dashed\DashedAi\Enums\AiCapability;
use Dashed\DashedAi\Facades\Ai;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CreateAltTextForMediaItem implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 1200;

    public $uniqueFor = 1200;

    public function __construct(public $mediaItem) {}

    public function uniqueId(): string
    {
        return 'create-alt-text-for-media-item-'.$this->mediaItem->id;
    }

    public function handle(): void
    {
        if (! Ai::default(AiCapability::Vision)) {
            return;
        }

        $media = $this->mediaItem->media->first();
        if (! $media) {
            return;
        }

        if (! in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp'])) {
            return;
        }

        $imagePath = $media->getPath();
        if (! Storage::disk('dashed')->exists($imagePath)) {
            return;
        }

        $image = Storage::disk('dashed')->get($imagePath);
        $imageData = base64_encode($image);

        $prompt = 'Geef een korte, duidelijke alt-tekst voor deze afbeelding. Maximaal 200 karakters. Gebruik geen HTML-tags of speciale tekens. De alt-tekst moet beschrijvend zijn en de inhoud van de afbeelding samenvatten.';

        $altText = Ai::vision($prompt, $imageData, $media->mime_type);

        if ($altText) {
            $this->mediaItem->alt_text = str($altText)->trim()->limit(200, '')->toString();
            $this->mediaItem->save();
        } else {
            static::dispatch($this->mediaItem)->delay(now()->addMinutes(5));
        }
    }
}
