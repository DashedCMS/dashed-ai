<?php

namespace Dashed\DashedAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Dashed\DashedAi\Logging\AiPricing;

class AiPricingOverridesTest extends TestCase
{
    protected array $config = [
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10],
    ];

    public function test_without_overrides_config_is_used_with_longest_prefix(): void
    {
        $price = AiPricing::lookup([], $this->config, 'claude', 'claude-sonnet-5-5-20261101');

        $this->assertSame(0.10, $price['cache_read']);
    }

    public function test_exact_override_beats_config(): void
    {
        $overrides = [['provider' => 'claude', 'model' => 'claude-sonnet-5-5', 'input' => '3,5', 'output' => 15]];

        $price = AiPricing::lookup($overrides, $this->config, 'claude', 'claude-sonnet-5-5');

        $this->assertSame(['input' => 3.5, 'output' => 15.0], $price);
    }

    public function test_longest_prefix_among_overrides_wins(): void
    {
        $overrides = [
            ['provider' => 'claude', 'model' => 'claude-sonnet', 'input' => 1],
            ['provider' => 'claude', 'model' => 'claude-sonnet-5', 'input' => 9],
        ];

        $price = AiPricing::lookup($overrides, [], 'claude', 'claude-sonnet-5-5');

        $this->assertSame(9.0, $price['input']);
    }

    public function test_override_for_other_provider_or_blank_row_is_ignored(): void
    {
        $overrides = [
            ['provider' => 'openai', 'model' => 'claude-sonnet-5-5', 'input' => 99],
            ['provider' => 'claude', 'model' => 'claude-sonnet-5-5'],
            ['provider' => 'claude', 'model' => '', 'input' => 5],
        ];

        $price = AiPricing::lookup($overrides, $this->config, 'claude', 'claude-sonnet-5-5');

        $this->assertSame(2.0, $price['input']);
    }

    public function test_per_unit_override_gets_unit(): void
    {
        $overrides = [['provider' => 'fal', 'model' => 'fal-ai/x', 'per_unit' => '0.2', 'unit' => 'image']];

        $this->assertSame(['per_unit' => 0.2, 'unit' => 'image'], AiPricing::lookup($overrides, [], 'fal', 'fal-ai/x'));
    }

    public function test_unknown_or_empty_model_gives_null(): void
    {
        $this->assertNull(AiPricing::lookup([], $this->config, 'claude', 'gpt-x'));
        $this->assertNull(AiPricing::lookup([], $this->config, 'claude', null));
    }
}
