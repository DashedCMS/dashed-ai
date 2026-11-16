<?php

namespace Dashed\DashedAi\Tests\Unit;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Dashed\DashedAi\Logging\AiLogger;
use Illuminate\Database\Eloquent\Model;

class AiLoggerContextTest extends TestCase
{
    protected function subject(int $id): Model
    {
        $model = new class () extends Model {
        };
        $model->id = $id;

        return $model;
    }

    public function test_no_frame_gives_empty_context(): void
    {
        $this->assertSame(['subject' => null, 'purpose' => null, 'batch' => null], AiLogger::resolveContext([]));
    }

    public function test_frame_is_used_inside_within_and_popped_after(): void
    {
        $subject = $this->subject(1);

        $inside = AiLogger::within(['subject' => $subject, 'purpose' => 'frame', 'batch' => 'b1'], fn () => AiLogger::resolveContext([]));

        $this->assertSame($subject, $inside['subject']);
        $this->assertSame('frame', $inside['purpose']);
        $this->assertSame('b1', $inside['batch']);
        $this->assertSame([], AiLogger::currentFrame());
    }

    public function test_inner_frame_overrides_outer_keys_only(): void
    {
        $result = AiLogger::within(['purpose' => 'outer', 'batch' => 'b'], fn () => AiLogger::within(['purpose' => 'inner'], fn () => AiLogger::resolveContext([])));

        $this->assertSame('inner', $result['purpose']);
        $this->assertSame('b', $result['batch']);
    }

    public function test_explicit_context_beats_frame_and_purpose_priority(): void
    {
        $frameSubject = $this->subject(1);
        $own = $this->subject(2);

        $result = AiLogger::within(['subject' => $frameSubject, 'purpose' => 'frame', 'batch' => 'fb'], function () use ($own) {
            return [
                'explicit' => AiLogger::resolveContext(['subject' => $own, 'purpose' => 'explicit', 'batch' => 'eb'], ['purpose' => 'option']),
                'option' => AiLogger::resolveContext(['purpose' => null], ['purpose' => 'option']),
                'frame' => AiLogger::resolveContext([], []),
            ];
        });

        $this->assertSame($own, $result['explicit']['subject']);
        $this->assertSame('explicit', $result['explicit']['purpose']);
        $this->assertSame('eb', $result['explicit']['batch']);
        $this->assertSame('option', $result['option']['purpose']);
        $this->assertSame('frame', $result['frame']['purpose']);
    }

    public function test_frame_is_popped_when_callback_throws(): void
    {
        try {
            AiLogger::within(['purpose' => 'x'], fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        $this->assertSame([], AiLogger::currentFrame());
    }

    public function test_within_returns_callback_value(): void
    {
        $this->assertSame(42, AiLogger::within([], fn () => 42));
    }

    public function test_subject_parts_uses_morph_class_and_key(): void
    {
        $method = new \ReflectionMethod(AiLogger::class, 'subjectParts');

        $subject = $this->subject(7);
        $this->assertSame([$subject->getMorphClass(), 7], $method->invoke(null, $subject));
        $this->assertSame([null, null], $method->invoke(null, new class () extends Model {
        }));
        $this->assertSame([null, null], $method->invoke(null, null));
    }
}
