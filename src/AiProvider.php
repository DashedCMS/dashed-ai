<?php

namespace Dashed\DashedAi;

use Dashed\DashedAi\Enums\AiCapability;
use Dashed\DashedAi\Exceptions\EmbeddingNotSupportedException;

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
     * @return array<int, float>
     *
     * @throws EmbeddingNotSupportedException
     */
    abstract public function embed(string $text, array $options = []): array;

    /**
     * Filament form components for this provider's settings, rendered on the
     * central AiSettingsPage.
     */
    abstract public function settingsSchema(): array;

    abstract public function isConnected(): bool;

    public function messages(array $messages, array $options = []): array
    {
        throw new \Dashed\DashedAi\Exceptions\AiException('Provider ' . $this->name() . ' ondersteunt geen tool-messages.');
    }

    public function streamMessages(array $messages, array $options, callable $onText): array
    {
        throw new \Dashed\DashedAi\Exceptions\AiException('Provider ' . $this->name() . ' ondersteunt geen streaming.');
    }

    public function supports(AiCapability $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    protected function buildSystemPrompt(array $options = []): string
    {
        $disableBrandRules = (bool) ($options['disable_brand_rules'] ?? false);
        $system = $options['system'] ?? '';

        $parts = [];
        if (! $disableBrandRules) {
            $parts[] = "## Globale regels\n".
                "- Gebruik NOOIT em-dashes (-). Gebruik een punt, komma of haakjes.\n".
                "- Gebruik geen AI-clichés (\"duik in\", \"ontdek de geheimen\", \"in een notendop\").\n".
                "- Schrijf actief en in de \"je\"-vorm.\n".
                "- Wanneer een JSON-antwoord wordt gevraagd met HTML erin: gebruik ENKELE quotes voor HTML-attributen (bijv. <a href='/url'>) zodat de JSON valide blijft.\n".
                "- Retourneer bij JSON-verzoeken UITSLUITEND geldig JSON zonder markdown code fences.";
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

        $text = trim($text);

        // First: try decoding as-is (happy path when provider returns clean JSON).
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Strip fenced code blocks anywhere in the text: ```json ... ``` or ``` ... ```.
        // We pull out the contents of the first fenced block if present.
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $fenceMatch)) {
            $candidate = trim($fenceMatch[1]);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Strip leading/trailing fences if the regex above didn't match a paired block.
        $clean = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
        $clean = preg_replace('/\s*```\s*$/', '', $clean);
        $clean = trim($clean);
        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Last resort: find the outermost { ... } or [ ... ] block using balanced search.
        $candidate = $this->extractBalancedJson($clean);
        if ($candidate !== null) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractBalancedJson(string $text): ?string
    {
        $len = strlen($text);
        $start = null;
        $openChar = null;
        $closeChar = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '{' || $ch === '[') {
                $start = $i;
                $openChar = $ch;
                $closeChar = $ch === '{' ? '}' : ']';

                break;
            }
        }
        if ($start === null) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($escape) {
                $escape = false;

                continue;
            }
            if ($inString) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($ch === '"') {
                $inString = true;

                continue;
            }
            if ($ch === $openChar) {
                $depth++;
            } elseif ($ch === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
