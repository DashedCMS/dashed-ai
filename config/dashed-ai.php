<?php

return [
    'default_provider' => null,

    /*
     * Logboek van AI-aanroepen. Elke aanroep van Claude, OpenAI, Fal.ai en
     * DeepL wordt vastgelegd in dashed__ai_logs met verbruik en geschatte
     * kosten. De schakelaars hieronder zijn de terugval; de instellingen bij
     * Instellingen, AI gaan voor als ze gezet zijn.
     */
    'log' => [
        // Prompt en antwoord bewaren (anders alleen cijfers en context).
        'store_content' => true,
        // Prompt en antwoord worden boven dit aantal tekens ingekort.
        'max_content_chars' => 20000,
        // Omrekenkoers voor de EUR-weergave; de providers factureren in USD.
        'usd_eur_rate' => 0.92,
        // Maandbudget in USD, 0 = geen bewaking.
        'monthly_budget_usd' => 0,
        // Bij welke percentages van het budget er een mail gaat.
        'budget_alert_percentages' => [80, 100],
    ],

    /*
     * Prijzen in USD, zoals de providers factureren. Tokenmodellen per miljoen
     * tokens (input, output, cache_read, cache_write); eenheidsmodellen met
     * per_unit en unit (image, megapixel, million_characters). Een model
     * wordt gevonden op de langste sleutel die een prefix van de modelnaam is,
     * zodat 'claude-haiku-4-5-20251001' op 'claude-haiku-4-5' valt. Een
     * onbekend model krijgt geen kosten (null) in het logboek, geen nul.
     *
     * Peildatum 19 oktober 2026. Controleer bij een nieuwe modelversie de
     * prijspagina van de provider en overschrijf hier wat afwijkt.
     */
    'pricing' => [
        'claude' => [
            'claude-opus-4-7' => ['input' => 5.00, 'output' => 25.00, 'cache_read' => 0.50, 'cache_write' => 6.25],
            'claude-opus-4-6' => ['input' => 5.00, 'output' => 25.00, 'cache_read' => 0.50, 'cache_write' => 6.25],
            'claude-opus-4-5' => ['input' => 5.00, 'output' => 25.00, 'cache_read' => 0.50, 'cache_write' => 6.25],
            'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75],
            'claude-sonnet-4-5' => ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_read' => 0.10, 'cache_write' => 1.25],
        ],
        'openai' => [
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
            'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
            'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
            'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
            'text-embedding-3-small' => ['input' => 0.02],
            'text-embedding-3-large' => ['input' => 0.13],
            'dall-e-3' => ['per_unit' => 0.04, 'unit' => 'image'],
            'gpt-image-1' => ['per_unit' => 0.04, 'unit' => 'image'],
        ],
        'fal' => [
            // flux/dev en clarity-upscaler rekenen per megapixel van de uitvoer.
            'fal-ai/flux/dev' => ['per_unit' => 0.025, 'unit' => 'megapixel'],
            'fal-ai/clarity-upscaler' => ['per_unit' => 0.03, 'unit' => 'megapixel'],
            'fal-ai/nano-banana/edit' => ['per_unit' => 0.039, 'unit' => 'image'],
            'fal-ai/birefnet' => ['per_unit' => 0.002, 'unit' => 'image'],
        ],
        'deepl' => [
            // DeepL API Pro: 20 euro per miljoen tekens, hier omgerekend naar USD.
            'pro' => ['per_unit' => 21.50, 'unit' => 'million_characters'],
            'free' => ['per_unit' => 0, 'unit' => 'million_characters'],
        ],
    ],
];
