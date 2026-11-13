<?php

namespace Dashed\DashedAi\Media;

use Throwable;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedCore\Classes\Locales;

/**
 * Wat de AI-taken van de mediabibliotheek delen: het beeld aanleveren, het
 * antwoord lezen en de namen van de talen voor in de prompt.
 */
final class MediaVision
{
    /** Alleen wat elke vision-provider aanneemt. */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Groter gaat niet naar de provider: het kost per beeldpunt en de meeste weigeren het. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Wat een antwoord tot een weigering maakt in plaats van een beschrijving. */
    private const REFUSALS = ['ik kan', 'ik kon', 'sorry', 'helaas', 'het spijt', "i can't", 'i can’t', 'i cannot', "i'm unable", 'i’m unable', 'i am unable', 'i am not able', "i'm not able", 'as an ai', 'unable to', 'unfortunately'];

    /**
     * Of de nieuwe dashed-files er is. De AI-taken van de mediabibliotheek
     * leunen op de teksten per taal; met een oudere dashed-files doen ze niets.
     */
    public static function available(): bool
    {
        return class_exists(\Dashed\DashedFiles\Classes\MediaTexts::class)
            && class_exists(\Dashed\DashedFiles\Classes\MediaPrivacy::class)
            && method_exists(\Dashed\DashedFiles\Classes\MediaLibrary::class, 'setTexts');
    }

    /**
     * De ene toets voor "mag dit bestand naar de AI-dienst". Bovenaan elke
     * taak, wat de aanroeper ook al filterde: een bijlage die een bezoeker
     * in de chat stuurde, of iets uit de mappen `chat` en `orders`, gaat
     * nooit naar een externe dienst. Ook niet wat in de prullenbak ligt.
     */
    public static function maySend(?MediaItem $item): bool
    {
        if (! $item || ! self::available()) {
            return false;
        }

        if (method_exists($item, 'trashed') && $item->trashed()) {
            return false;
        }

        return ! \Dashed\DashedFiles\Classes\MediaPrivacy::isProtected($item);
    }

    /**
     * Een antwoord dat geen JSON is, als alt-tekst. Alleen als er precies
     * één taal gevraagd is en het antwoord er als een kale beschrijving
     * uitziet: geen (afgebroken) JSON, geen codeblok, één regel, kort, en
     * geen weigering. Al het andere is een mislukte poging.
     *
     * @param  array<int, string>  $targets
     */
    public static function plainAnswer(?string $answer, array $targets, int $max = 200): ?string
    {
        $answer = trim((string) $answer);

        if (count($targets) !== 1 || $answer === '' || mb_strlen($answer) >= $max) {
            return null;
        }

        if (preg_match('/[{}\[\]\r\n]|```/', $answer)) {
            return null;
        }

        $start = mb_strtolower(ltrim($answer, " \t\"'“‘"));

        foreach (self::REFUSALS as $refusal) {
            if (str_starts_with($start, $refusal)) {
                return null;
            }
        }

        $clean = trim(trim(strip_tags($answer)), "\"'“”‘’");

        return $clean === '' ? null : $clean;
    }

    /**
     * Het beeld van een item als base64, of null als er niets te sturen is.
     * Het formaat `medium` als dat er is: kleiner en dus goedkoper, en voor
     * een beschrijving ruim genoeg. Anders het origineel.
     *
     * @return array{data: string, mime: string}|null
     */
    public static function imageFor(MediaItem $item): ?array
    {
        $media = $item->file();

        if (! $media || ! in_array((string) $media->mime_type, self::MIME_TYPES, true)) {
            return null;
        }

        $candidates = [];

        try {
            if ($media->hasGeneratedConversion('medium')) {
                $path = $media->getPathRelativeToRoot('medium');
                $mime = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

                if ($mime !== null) {
                    $candidates[] = [(string) ($media->conversions_disk ?: $media->disk), $path, $mime];
                }
            }

            // De schijf van de mediarij zelf, niet een vaste naam.
            $candidates[] = [(string) $media->disk, $media->getPathRelativeToRoot(), (string) $media->mime_type];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        foreach ($candidates as [$disk, $path, $mime]) {
            try {
                $storage = Storage::disk($disk);

                if (! $storage->exists($path) || $storage->size($path) > self::MAX_BYTES) {
                    continue;
                }

                $bytes = $storage->get($path);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if (is_string($bytes) && $bytes !== '') {
                return ['data' => base64_encode($bytes), 'mime' => $mime];
            }
        }

        return null;
    }

    /**
     * Het JSON-object of de JSON-lijst uit een antwoord, ook als het model er
     * een codeblok of een zin omheen zette. Null als er geen JSON in zit.
     */
    public static function decode(?string $answer): ?array
    {
        $answer = trim((string) $answer);

        if ($answer === '') {
            return null;
        }

        $answer = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $answer);

        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $start = strpos($answer, $open);
            $end = strrpos($answer, $close);

            if ($start === false || $end === false || $end <= $start) {
                continue;
            }

            $decoded = json_decode(substr($answer, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * "nl (Nederlands), en (English)" voor in een prompt.
     *
     * @param  array<int, string>  $locales
     */
    public static function describeLocales(array $locales): string
    {
        $names = [];

        try {
            foreach (Locales::getLocales() as $locale) {
                $names[(string) $locale['id']] = (string) ($locale['native'] ?? $locale['name'] ?? $locale['id']);
            }
        } catch (Throwable) {
            // Dan alleen de codes.
        }

        return implode(', ', array_map(fn (string $id) => isset($names[$id]) && $names[$id] !== $id ? "{$id} ({$names[$id]})" : $id, $locales));
    }

    /**
     * Hooguit acht korte tags: kleine letters, geen leestekens, geen dubbele.
     *
     * @return array<int, string>
     */
    public static function cleanTags(mixed $tags, int $max = 8): array
    {
        $clean = [];

        foreach (is_array($tags) ? $tags : [] as $tag) {
            if (! is_scalar($tag)) {
                continue;
            }

            $tag = mb_strtolower(strip_tags((string) $tag));
            // Letters, cijfers, spaties en een koppelteken; de rest is leesteken.
            $tag = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $tag)), ' -');

            if ($tag === '' || mb_strlen($tag) > 40 || in_array($tag, $clean, true)) {
                continue;
            }

            $clean[] = $tag;

            if (count($clean) >= $max) {
                break;
            }
        }

        return $clean;
    }

    /** De regel voor de prompt met de tags die er al zijn, zodat het model ze hergebruikt. */
    public static function tagInstruction(int $max = 8): string
    {
        $existing = array_column(rescue(fn () => app(\Dashed\DashedFiles\Classes\MediaLibrary::class)->allTags(null, 50), [], false), 'name');

        return "Geef hooguit {$max} korte tags (een of twee woorden) die beschrijven wat er te zien is, in het " . self::describeLocales([\Dashed\DashedFiles\Classes\MediaTexts::firstLocale()]) . ', in kleine letters en zonder leestekens.'
            . ($existing === [] ? '' : ' Gebruik waar het past een van deze bestaande tags, letterlijk overgenomen, in plaats van een nieuwe variant: ' . implode(', ', $existing) . '.');
    }
}
