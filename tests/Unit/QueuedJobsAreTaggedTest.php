<?php

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Finder\Finder;

/**
 * Business rule: every job that goes through Horizon declares its tags, so it can be
 * monitored on the dashboard. Horizon reads `tags()` from the notification, mailable,
 * event or listener behind the job, which is why the rule is checked on those classes.
 * A ShouldBroadcast event is a job too (BroadcastEvent); ShouldBroadcastNow never queues.
 *
 * @return list<class-string>
 */
function queuedClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/app') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class)) {
            continue;
        }

        $queuedBroadcast = is_subclass_of($class, ShouldBroadcast::class) && ! is_subclass_of($class, ShouldBroadcastNow::class);

        if (is_subclass_of($class, ShouldQueue::class) || $queuedBroadcast) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

test('every queued class declares Horizon tags', function (): void {
    $untagged = array_filter(queuedClasses(), fn (string $class): bool => ! method_exists($class, 'tags'));

    expect(queuedClasses())->not->toBeEmpty()
        ->and(array_values($untagged))->toBe([]);
});
