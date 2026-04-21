<?php

namespace Dashed\DashedAi\Tests\Unit;

use Dashed\DashedAi\AiProvider;
use PHPUnit\Framework\TestCase;

class FakeProvider extends AiProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function supportedCapabilities(): array
    {
        return [];
    }

    public function text(string $prompt, array $options = []): ?string
    {
        return null;
    }

    public function json(string $prompt, array $options = []): ?array
    {
        return null;
    }

    public function vision(string $prompt, string $imageData, string $mimeType, array $options = []): ?string
    {
        return null;
    }

    public function image(string $prompt, array $options = []): ?string
    {
        return null;
    }

    public function embed(string $text, array $options = []): array
    {
        return [];
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function isConnected(): bool
    {
        return false;
    }

    public function parseForTest(?string $text): ?array
    {
        return $this->parseJsonResponse($text);
    }
}

class ParseJsonResponseTest extends TestCase
{
    private FakeProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider;
    }

    public function test_returns_null_for_empty_input(): void
    {
        $this->assertNull($this->provider->parseForTest(null));
        $this->assertNull($this->provider->parseForTest(''));
    }

    public function test_decodes_clean_json_object(): void
    {
        $this->assertSame(['a' => 1], $this->provider->parseForTest('{"a":1}'));
    }

    public function test_decodes_clean_json_array(): void
    {
        $this->assertSame([1, 2, 3], $this->provider->parseForTest('[1,2,3]'));
    }

    public function test_strips_json_fenced_code_block(): void
    {
        $input = "```json\n{\"a\":1}\n```";
        $this->assertSame(['a' => 1], $this->provider->parseForTest($input));
    }

    public function test_strips_bare_fenced_code_block(): void
    {
        $input = "```\n{\"a\":1}\n```";
        $this->assertSame(['a' => 1], $this->provider->parseForTest($input));
    }

    public function test_handles_prose_before_and_after_fence(): void
    {
        $input = "Here is the JSON you asked for:\n```json\n{\"a\":1,\"b\":[2,3]}\n```\nLet me know if anything else is needed.";
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $this->provider->parseForTest($input));
    }

    public function test_handles_unclosed_trailing_fence(): void
    {
        $input = "```json\n{\"a\":1}";
        $this->assertSame(['a' => 1], $this->provider->parseForTest($input));
    }

    public function test_extracts_balanced_object_from_surrounding_prose(): void
    {
        $input = 'Sure, here you go: {"items":[{"id":1}]} hope it helps.';
        $this->assertSame(['items' => [['id' => 1]]], $this->provider->parseForTest($input));
    }

    public function test_handles_braces_in_string_values(): void
    {
        $input = 'Sure: {"note":"value with { and } chars","ok":true} bye.';
        $this->assertSame(['note' => 'value with { and } chars', 'ok' => true], $this->provider->parseForTest($input));
    }

    public function test_returns_null_when_no_json_found(): void
    {
        $this->assertNull($this->provider->parseForTest('Sorry I cannot help.'));
    }
}
