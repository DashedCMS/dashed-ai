<?php

namespace Dashed\DashedAi;

use Dashed\DashedAi\Enums\AiCapability;
use Dashed\DashedCore\Models\Customsetting;

class AiManager
{
    /** @var array<string, AiProvider> */
    protected array $providers = [];

    public function register(AiProvider $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function default(?AiCapability $capability = null): ?AiProvider
    {
        $preferred = Customsetting::get('ai_default_provider');

        if ($preferred && isset($this->providers[$preferred])) {
            $provider = $this->providers[$preferred];
            if ($provider->isConnected() && (! $capability || $provider->supports($capability))) {
                return $provider;
            }
        }

        foreach ($this->providers as $provider) {
            if ($provider->isConnected() && (! $capability || $provider->supports($capability))) {
                return $provider;
            }
        }

        return null;
    }

    public function text(string $prompt, array $options = []): ?string
    {
        $prompt = $this->prependToneOfVoice($prompt, $options);

        return $this->default(AiCapability::Text)?->text($prompt, $options);
    }

    public function json(string $prompt, array $options = []): ?array
    {
        $prompt = $this->prependToneOfVoice($prompt, $options);

        return $this->default(AiCapability::Json)?->json($prompt, $options);
    }

    public function vision(string $prompt, string $imageData, string $mimeType, array $options = []): ?string
    {
        $prompt = $this->prependToneOfVoice($prompt, $options);

        return $this->default(AiCapability::Vision)?->vision($prompt, $imageData, $mimeType, $options);
    }

    public function image(string $prompt, array $options = []): ?string
    {
        return $this->default(AiCapability::Image)?->image($prompt, $options);
    }

    public function messages(array $messages, array $options = []): ?array
    {
        return $this->default(AiCapability::Text)?->messages($messages, $options);
    }

    public function embed(string $text, array $options = []): array
    {
        $provider = $this->default(AiCapability::Embedding);

        if (! $provider) {
            return [];
        }

        return $provider->embed($text, $options);
    }

    /**
     * @return array<string, AiProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, AiProvider>
     */
    public function connectedProviders(): array
    {
        return array_filter($this->providers, fn (AiProvider $p) => $p->isConnected());
    }

    public function provider(string $name): ?AiProvider
    {
        return $this->providers[$name] ?? null;
    }

    public function hasProvider(): bool
    {
        return ! empty($this->connectedProviders());
    }

    /**
     * Tone-of-voice middleware: prepent (indien aanwezig) de gegenereerde of
     * handmatig overschreven Brief als context-prefix aan het prompt. De caller
     * kan dit uitschakelen met `['skip_tone_of_voice' => true]` (gebruikt door
     * de generator zelf om recursie te voorkomen).
     */
    protected function prependToneOfVoice(string $prompt, array $options): string
    {
        if (($options['skip_tone_of_voice'] ?? false) === true) {
            return $prompt;
        }

        $brief = $this->resolveToneOfVoiceBrief();
        if ($brief === null) {
            return $prompt;
        }

        return "## Tone of voice (intern, gebruik dit als briefing voor de schrijftaak hieronder)\n\n"
            . $brief
            . "\n\n---\n\n## Schrijftaak\n\n"
            . $prompt;
    }

    /**
     * Resolve de actieve Brief: handmatige override gaat voor de gegenereerde
     * Brief; geen Brief geconfigureerd retourneert null (geen prefix).
     */
    protected function resolveToneOfVoiceBrief(): ?string
    {
        $override = trim((string) Customsetting::get('ai_tone_of_voice_brief_manual_override'));
        if ($override !== '') {
            return $override;
        }

        $generated = trim((string) Customsetting::get('ai_tone_of_voice_brief'));

        return $generated !== '' ? $generated : null;
    }
}
