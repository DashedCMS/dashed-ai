<?php

namespace Dashed\DashedAi\Models;

use Dashed\DashedCore\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén rij per aanroep van een externe AI-dienst (Claude, OpenAI, Fal.ai,
 * DeepL), met verbruik en geschatte kosten. Geschreven door AiCallLog,
 * nooit rechtstreeks.
 */
class AiLog extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public const UPDATED_AT = null;

    protected $table = 'dashed__ai_logs';

    protected $guarded = [];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'units' => 'float',
        'cost_usd' => 'float',
        'duration_ms' => 'integer',
        'options' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeInMonth(Builder $query, ?\DateTimeInterface $moment = null): Builder
    {
        $moment = $moment ? \Carbon\Carbon::instance($moment) : now();

        return $query->whereBetween('created_at', [$moment->copy()->startOfMonth(), $moment->copy()->endOfMonth()]);
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens + $this->cache_read_tokens + $this->cache_write_tokens;
    }

    /** Leesbare naam van de functie die de aanroep deed: expliciet doel, anders de aanroepende klasse. */
    public function featureLabel(): string
    {
        if ($this->purpose) {
            return $this->purpose;
        }

        if ($this->caller) {
            return class_basename($this->caller);
        }

        return '-';
    }

    public function costEur(): ?float
    {
        if ($this->cost_usd === null) {
            return null;
        }

        return round($this->cost_usd * \Dashed\DashedAi\Logging\AiLogSettings::usdEurRate(), 6);
    }

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return [
            'text' => __('Tekst'),
            'json' => __('JSON'),
            'vision' => __('Beeldherkenning'),
            'image' => __('Afbeelding genereren'),
            'image_edit' => __('Afbeelding bewerken'),
            'embedding' => __('Embedding'),
            'messages' => __('Gesprek'),
            'stream' => __('Gesprek (stream)'),
            'translation' => __('Vertaling'),
        ];
    }

    /** @return array<string, string> */
    public static function providerLabels(): array
    {
        return [
            'claude' => 'Claude (Anthropic)',
            'openai' => 'OpenAI',
            'fal' => 'Fal.ai',
            'deepl' => 'DeepL',
        ];
    }
}
