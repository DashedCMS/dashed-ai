<?php

namespace Dashed\DashedAi;

use Dashed\DashedAi\Enums\AiCapability;
use Dashed\DashedCore\Models\Customsetting;

abstract class AiProvider
{
    abstract public function name(): string;

    abstract public function label(): string;

    /**
     * @return AiCapability[]
     */
    abstract public function supportedCapabilities(): array;

    abstract public function text(string $prompt, array $options = []): ?string;

    abstract public function json(string $prompt, array $options = []): ?array;

    abstract public function vision(string $prompt, string $imageData, string $mimeType, array $options = []): ?string;

    abstract public function image(string $prompt, array $options = []): ?string;

    /**
     * Generate an embedding vector for the given text.
     *
     * @param  string  $text
     * @param  array  $options
     * @return array<int, float>
     *
     * @throws \Dashed\DashedAi\Exceptions\EmbeddingNotSupportedException
     */
    abstract public function embed(string $text, array $options = []): array;

    /**
     * Filament form components for this provider's settings, rendered on the
     * central AiSettingsPage.
     *
     * @return array
     */
    abstract public function settingsSchema(): array;

    abstract public function isConnected(): bool;

    public function supports(AiCapability $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    protected function buildSystemPrompt(array $options = []): string
    {
        $brandStory = Customsetting::get('ai_brand_story', null, '');
        $writingStyle = Customsetting::get('ai_writing_style', null, '');
        $system = $options['system'] ?? '';

        $parts = [];
        if ($brandStory) {
            $parts[] = "## Merkverhaal\n" . $brandStory;
        }
        if ($writingStyle) {
            $parts[] = "## Schrijfstijl\n" . $writingStyle;
        }
        if ($system) {
            $parts[] = $system;
        }

        return trim(implode("\n\n", $parts));
    }

    protected function parseJsonResponse(?string $text): ?array
    {
        if (! $text) {
            return null;
        }

        $clean = preg_replace('/^\s*```(?:json)?\s*/i', '', trim($text));
        $clean = preg_replace('/\s*```\s*$/', '', $clean);

        $decoded = json_decode(trim($clean), true);
        if ($decoded === null && preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/u', $clean, $matches)) {
            $decoded = json_decode($matches[1], true);
        }

        return $decoded;
    }
}
