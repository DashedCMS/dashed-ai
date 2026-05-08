<?php

namespace Dashed\DashedAi\Jobs;

use Illuminate\Bus\Queueable;
use Dashed\DashedAi\Facades\Ai;
use Illuminate\Queue\SerializesModels;
use Dashed\DashedAi\Enums\AiCapability;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

class CreateAltTextsForAllMediaItems implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 1200;

    public $uniqueFor = 1200;

    public function __construct(public bool $overwriteExisting = false)
    {
    }

    public function uniqueId(): string
    {
        return 'create-alt-texts-for-all-media-items';
    }

    public function handle(): void
    {
        if (! Ai::default(AiCapability::Vision)) {
            return;
        }

        $mimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

        if ($this->overwriteExisting) {
            MediaLibraryItem::query()
                ->whereHas('media', fn ($q) => $q->whereIn('mime_type', $mimeTypes))
                ->update([
                    'alt_text' => null,
                    'automatic_alt_tries' => 0,
                ]);
        }

        $delayInSeconds = 0;

        MediaLibraryItem::whereNull('alt_text')
            ->whereHas('media', fn ($q) => $q->whereIn('mime_type', $mimeTypes))
            ->where('automatic_alt_tries', '<', 3)
            ->get()
            ->each(function ($mediaItem) use (&$delayInSeconds) {
                CreateAltTextForMediaItem::dispatch($mediaItem)
                    ->delay(now()->addSeconds($delayInSeconds));
                $mediaItem->automatic_alt_tries++;
                $mediaItem->save();
                $delayInSeconds += 3;
            });
    }
}
