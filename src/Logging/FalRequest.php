<?php

namespace Dashed\DashedAi\Logging;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Eén plek voor elke aanroep van fal.run, zodat beeldgeneratie en -bewerking
 * (dashed-files, dashed-marketing) in het AI-logboek komen. Het model is het
 * pad achter fal.run; de eenheid volgt de prijstabel: megapixels van de
 * uitvoer waar fal per megapixel rekent, anders het aantal afbeeldingen.
 *
 * Gedraagt zich als de kale Http-aanroep die hij vervangt: geeft de response
 * terug, ook bij een foutstatus, en gooit een verbindingsfout door nadat die
 * gelogd is.
 */
class FalRequest
{
    public static function post(string $endpoint, array $payload, string $apiKey, array $context = [], int $timeout = 180): Response
    {
        $model = self::modelFromEndpoint($endpoint);
        $kind = isset($payload['image_urls']) || isset($payload['image_url']) ? 'image_edit' : 'image';

        $log = AiLogger::begin('fal', $kind, [
            'model' => $model,
            'prompt' => $payload['prompt'] ?? null,
            'options' => array_diff_key($payload, ['prompt' => 1]) + ['endpoint' => $endpoint],
            'site_id' => $context['site_id'] ?? null,
            'purpose' => $context['purpose'] ?? null,
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Key {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout($timeout)->post($endpoint, $payload);
        } catch (\Throwable $e) {
            $log->fail($e);

            throw $e;
        }

        if (! $response->successful()) {
            $log->fail('HTTP ' . $response->status() . ' ' . mb_substr((string) $response->body(), 0, 500));

            return $response;
        }

        [$units, $unitType] = self::unitsFor($model, $response->json());
        $log->units($units, $unitType)
            ->response($response->json('images.0.url') ?: $response->json('image.url'))
            ->succeed();

        return $response;
    }

    public static function modelFromEndpoint(string $endpoint): string
    {
        $path = parse_url($endpoint, PHP_URL_PATH) ?: $endpoint;

        return trim((string) $path, '/');
    }

    /** @return array{0: float, 1: string} */
    public static function unitsFor(string $model, ?array $body): array
    {
        $images = [];
        if (isset($body['images']) && is_array($body['images'])) {
            $images = $body['images'];
        } elseif (isset($body['image']) && is_array($body['image'])) {
            $images = [$body['image']];
        }

        $price = AiPricing::priceFor('fal', $model);
        if (($price['unit'] ?? null) === 'megapixel') {
            $megapixels = 0.0;
            foreach ($images as $image) {
                if (! empty($image['width']) && ! empty($image['height'])) {
                    $megapixels += ((int) $image['width'] * (int) $image['height']) / 1_000_000;
                }
            }

            return [$megapixels > 0 ? round($megapixels, 4) : 1.0, 'megapixel'];
        }

        return [max(1, count($images)), 'image'];
    }
}
