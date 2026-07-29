<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\HandRolledDeferredDetector;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\HandRolledLiveTransportDetector;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\InlineEventHandlerDetector;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\MechanismDetectorInterface;

/**
 * The detectors added after the first rule proved the shape.
 *
 * Same discipline as the first: each positive case is paired with the cases it
 * must stay quiet on, because the expensive failure here is not a missed finding
 * — it is a wrong one, which teaches everybody to skip the whole channel.
 */
final class MechanismDetectorSetTest extends TestCase
{
    /** @param list<string> $lines */
    private static function transport(array $lines): array
    {
        return (new HandRolledLiveTransportDetector())->detect('module.js', $lines);
    }

    /** @param list<string> $lines */
    private static function inline(array $lines): array
    {
        return (new InlineEventHandlerDetector())->detect('page.html.twig', $lines);
    }

    #[Test]
    public function an_event_source_opened_by_hand_is_reported(): void
    {
        // The highest-precision signal in the set: EventSource exists for one
        // purpose, so its presence in application code is not ambiguous.
        $findings = self::transport(["const es = new EventSource('/live/feed');"]);

        self::assertCount(1, $findings);
        self::assertSame('ssr.transport', $findings[0]->capabilityId);
        self::assertStringContainsString('EventSource', $findings[0]->evidence);
    }

    #[Test]
    public function a_hand_opened_web_socket_is_reported(): void
    {
        self::assertCount(1, self::transport(["const ws = new WebSocket('wss://host/x');"]));
    }

    #[Test]
    public function a_timer_around_a_request_is_reported_as_polling(): void
    {
        $findings = self::transport([
            'setInterval(async () => {',
            "  const res = await fetch('/orders/count');",
            '  render(await res.json());',
            '}, 5000);',
        ]);

        self::assertCount(1, $findings);
        self::assertStringContainsString('polling', $findings[0]->evidence);
        self::assertSame(1, $findings[0]->line);
    }

    #[Test]
    public function a_timer_with_no_request_is_left_alone(): void
    {
        // A countdown or an animation tick. Reporting it would be the rule
        // inventing an intent it cannot see.
        self::assertSame([], self::transport([
            'setInterval(() => {',
            '  seconds -= 1;',
            '  label.textContent = String(seconds);',
            '}, 1000);',
        ]));
    }

    #[Test]
    public function a_timer_far_from_an_unrelated_request_is_not_called_polling(): void
    {
        $lines = array_merge(
            ['setInterval(tick, 1000);'],
            array_fill(0, 30, '// ...'),
            ["submitButton.onclick = () => fetch('/save', { method: 'POST' });"],
        );

        self::assertSame([], self::transport($lines));
    }

    #[Test]
    public function an_inline_click_handler_is_reported(): void
    {
        $findings = self::inline(['<button onclick="save()">Save</button>']);

        self::assertCount(1, $findings);
        self::assertSame('ui.behavior', $findings[0]->capabilityId);
        self::assertStringContainsString('Content-Security-Policy', $findings[0]->evidence);
    }

    #[Test]
    public function any_event_attribute_is_matched_not_a_fixed_list(): void
    {
        // Regression: the first version enumerated nine event names and missed
        // `onmouseout` sitting one line below an `onmouseover` it did catch, in
        // a real module template. An enumeration of a growing vocabulary is
        // always one entry behind, so the match is generic.
        foreach (['onmouseout', 'onwheel', 'ondragstart', 'onpointerdown'] as $attribute) {
            $findings = self::inline(['<div ' . $attribute . '="go()"></div>']);
            self::assertCount(1, $findings, $attribute . ' must be reported');
        }
    }

    #[Test]
    public function short_lookalikes_are_not_mistaken_for_handlers(): void
    {
        // `once=` begins with "on" too. Requiring three more letters keeps it out
        // without reintroducing a hard-coded list.
        self::assertSame([], self::inline(['<div once="true"></div>']));
    }

    #[Test]
    public function data_attributes_that_merely_contain_on_are_not_matched(): void
    {
        // `data-onclick` and the framework's own `ui-on` are not inline
        // handlers. Matching them would fire on the correct solution.
        self::assertSame([], self::inline([
            '<button data-onclick="save">Save</button>',
            '<div ui-behavior="dropdown" ui-on:click="open"></div>',
        ]));
    }

    #[Test]
    public function every_detector_declares_the_extension_it_reads(): void
    {
        // The lint feeds files by extension; a detector that returned the wrong
        // one would simply never run, and would look like a passing check.
        $detectors = [
            new HandRolledDeferredDetector(),
            new HandRolledLiveTransportDetector(),
            new InlineEventHandlerDetector(),
        ];

        foreach ($detectors as $detector) {
            self::assertInstanceOf(MechanismDetectorInterface::class, $detector);
            self::assertNotSame('', $detector->extension());
            self::assertStringNotContainsString('.', $detector->extension(), 'extension is given without the dot');
        }
    }
}
