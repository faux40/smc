<?php

namespace Tests\Feature\Events;

use App\Events\ClassChanged;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * O3 — origin_tab must be captured at dispatch time, not at broadcast
 * time. Every event's broadcastWith() runs in the queue worker, where the
 * X-Origin-Tab request header doesn't exist; an event that calls
 * RealtimeOrigin::tab() there always broadcasts origin_tab=null and the
 * originating tab can never self-filter its echoes. One source-level sweep
 * across the whole event list pins the pattern (per the J6 template) so a
 * new event that regresses to the lazy call fails here by name.
 */
class OriginTabCaptureTest extends TestCase
{
    public function test_no_broadcast_event_reads_the_origin_tab_lazily(): void
    {
        $eventFiles = File::files(app_path('Events'));
        $this->assertNotEmpty($eventFiles);
        $swept = 0;

        foreach ($eventFiles as $file) {
            $source = $file->getContents();

            if (! str_contains($source, 'origin_tab')) {
                continue;
            }

            $swept++;

            preg_match(
                '/function broadcastWith\(\): array\s*\{(.*?)\n    \}/s',
                $source,
                $broadcastWith,
            );
            $this->assertNotEmpty(
                $broadcastWith,
                "{$file->getFilename()}: couldn't locate broadcastWith() — update this sweep.",
            );

            $this->assertStringNotContainsString(
                'RealtimeOrigin::tab()',
                $broadcastWith[1],
                "{$file->getFilename()} reads RealtimeOrigin::tab() inside broadcastWith(), "
                    .'which runs in the queue worker where the X-Origin-Tab header is gone. '
                    .'Capture it in the constructor instead (see TrainingAssignmentCreated).',
            );

            // And the capture actually happens: the constructor stores the
            // tab on the instance for broadcastWith() to use later.
            $this->assertMatchesRegularExpression(
                '/RealtimeOrigin::tab\(\)/',
                $source,
                "{$file->getFilename()} never captures RealtimeOrigin::tab() at all.",
            );
        }

        $this->assertGreaterThan(30, $swept, 'The sweep should cover the whole broadcast event list.');
    }

    public function test_captured_origin_tab_survives_the_queue_round_trip(): void
    {
        // Constructed during the request (header present), broadcast in the
        // worker (header gone, event rehydrated from the queue payload).
        request()->headers->set('X-Origin-Tab', 'tab-abc');
        $event = new ClassChanged('class-1', 'org-1', 'updated');
        request()->headers->remove('X-Origin-Tab');

        $payload = unserialize(serialize($event))->broadcastWith();
        $this->assertSame('tab-abc', $payload['origin_tab']);
    }

    public function test_origin_tab_is_null_without_a_request_header(): void
    {
        $event = new ClassChanged('class-1', 'org-1', 'updated');

        $this->assertNull($event->broadcastWith()['origin_tab']);
    }
}
