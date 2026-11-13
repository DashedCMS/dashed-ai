<?php

namespace Dashed\DashedAi;

use Dashed\DashedAi\Enums\AiCapability;
use Dashed\DashedCore\Models\Customsetting;

class AiManager
{
    /** @var array<string, AiProvider> */
    protected array $providers = [];

    /** De hoogste HTTP-time-out binnen withHttpTimeout(), in seconden; null is die van de provider. */
    private static ?int $httpTimeoutCap = null;

    /**
     * Voert $call uit met een plafond op de HTTP-time-out van elke aanroep
     * erin. Voor een wachtrijtaak: de providers zetten zelf 120 s, en een
     * taak moet binnen de standaard retry_after van 90 seconden klaar zijn.
     * Het plafond werkt via httpTimeoutMiddleware(), dus voor elke provider
     * die de HTTP-client van Laravel gebruikt.
     */
    public static function withHttpTimeout(int $seconds, callable $call): mixed
    {
        $previous = self::$httpTimeoutCap;
        self::$httpTimeoutCap = max(1, $seconds);

        try {
            return $call();
        } finally {
            self::$httpTimeoutCap = $previous;
        }
    }

    public static function httpTimeoutCap(): ?int
    {
        return self::$httpTimeoutCap;
    }

    /**
     * Guzzle-middleware die de time-out van een verzoek begrenst zolang er
     * een plafond staat. Daarbuiten raakt hij niets aan.
     */
    public static function httpTimeoutMiddleware(): callable
    {
        return fn (callable $handler) => function ($request, array $options) use ($handler) {
            $cap = self::$httpTimeoutCap;

            if ($cap !== null) {
                foreach (['timeout', 'connect_timeout'] as $key) {
                    $current = (float) ($options[$key] ?? 0);
                    // 0 is in Guzzle "geen grens".
                    $options[$key] = $current > 0 ? min($current, $cap) : $cap;
                }
            }

            return $handler($request, $options);
        };
    }

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
        $options = $this->withToneOfVoice($options);

        return $this->default(AiCapability::Text)?->text($prompt, $options);
    }

    public function json(string $prompt, array $options = []): ?array
    {
        $options = $this->withToneOfVoice($options);

        return $this->default(AiCapability::Json)?->json($prompt, $options);
    }

    public function vision(string $prompt, string $imageData, string $mimeType, array $options = []): ?string
    {
        $options = $this->withToneOfVoice($options);

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

    public function streamMessages(array $messages, array $options, callable $onText): ?array
    {
        return $this->default(AiCapability::Text)?->streamMessages($messages, $options, $onText);
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
     * Tone-of-voice middleware: geeft (indien aanwezig) de gegenereerde of
     * handmatig overschreven Brief mee als `tone_of_voice`-optie. De provider
     * zet hem in de system-prompt (zie AiProvider::systemPromptParts()), niet
     * in het bericht: zo is de brief voor elke aanroep van een site hetzelfde
     * stuk prefix en kan de provider hem cachen. Daarom staat `cache` hier
     * standaard aan; de caller kan het uitzetten met `['cache' => false]`.
     *
     * De caller kan de brief zelf uitschakelen met `['skip_tone_of_voice' => true]`
     * (gebruikt door de generator zelf om recursie te voorkomen).
     */
    protected function withToneOfVoice(array $options): array
    {
        if (($options['skip_tone_of_voice'] ?? false) === true) {
            return $options;
        }

        $brief = $this->resolveToneOfVoiceBrief();
        if ($brief === null) {
            return $options;
        }

        $options['tone_of_voice'] = $brief;
        $options['cache'] ??= true;

        return $options;
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
