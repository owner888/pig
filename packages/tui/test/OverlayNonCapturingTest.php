<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Component;
use Pig\Tui\Container;
use Pig\Tui\OverlayOptions;
use Pig\Tui\TuiMainScreen;

/**
 * Non-capturing overlays, overlay focus restore and the focus-driven drawing order — every case
 * of upstream's `overlay-non-capturing.test.ts`. Upstream's `unfocus({ target })` is
 * `unfocus($target, hasTarget: true)` here, per-instance `handleInput` replacements are
 * `FocusableOverlay::$onInput`, and a promise `.then()` is a `Loop::defer()`.
 */
final class OverlayNonCapturingTest extends TestCase
{
    private VirtualTerminal $terminal;

    private TuiMainScreen $tui;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (isset($this->tui)) {
            $this->tui->stop();
        }
    }

    /** Upstream's setup: content added, `$focus` focused, then started. */
    private function start(?Component $focus = null, ?Component $content = null, int $columns = 80, int $rows = 24): TuiMainScreen
    {
        $this->terminal = new VirtualTerminal($columns, $rows);
        $this->tui = new TuiMainScreen($this->terminal);
        $this->tui->addChild($content ?? new StaticOverlay([]));
        if ($focus !== null) {
            $this->tui->setFocus($focus);
        }
        $this->tui->start();

        return $this->tui;
    }

    private function renderAndFlush(): void
    {
        $this->tui->requestRender(true);
        $this->terminal->waitForRender();
    }

    private function send(string $data): void
    {
        $this->terminal->sendInput($data);
    }

    /** The character drawn in the top-left cell. */
    private function topLeft(): string
    {
        return substr($this->terminal->getViewport()[0], 0, 1);
    }

    private static function nc(): OverlayOptions
    {
        return new OverlayOptions(nonCapturing: true);
    }

    /** One cell at the top-left corner, where the rendering-order cases stack their overlays. */
    private static function cell(bool $nonCapturing = true): OverlayOptions
    {
        return new OverlayOptions(row: 0, col: 0, width: 1, nonCapturing: $nonCapturing);
    }

    private static function overlay(string $text): FocusableOverlay
    {
        return new FocusableOverlay([$text]);
    }

    // focus management

    public function testNonCapturingOverlayPreservesFocusOnCreation(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $tui->showOverlay($overlay, self::nc());
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($overlay->focused);
    }

    public function testFocusTransfersFocusToTheOverlay(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->focus();
        $this->renderAndFlush();
        $this->assertFalse($editor->focused);
        $this->assertTrue($overlay->focused);
        $this->assertTrue($handle->isFocused());
    }

    public function testUnfocusRestoresPreviousFocus(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->focus();
        $handle->unfocus();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($overlay->focused);
        $this->assertFalse($handle->isFocused());
    }

    public function testSetHiddenFalseOnNonCapturingOverlayDoesNotAutoFocus(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->setHidden(true);
        $handle->setHidden(false);
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($overlay->focused);
    }

    public function testHideWhenOverlayIsNotFocusedDoesNotChangeFocus(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->hide();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
    }

    public function testHideWhenFocusedRestoresFocusCorrectly(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->focus();
        $handle->hide();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($overlay->focused);
    }

    public function testCapturingOverlayRemovedWithNonCapturingBelowRestoresFocusToEditor(): void
    {
        $editor = self::overlay('EDITOR');
        $nonCapturing = self::overlay('NC');
        $capturing = self::overlay('CAP');
        $tui = $this->start($editor);

        $tui->showOverlay($nonCapturing, self::nc());
        $handle = $tui->showOverlay($capturing);
        $this->assertTrue($capturing->focused);
        $handle->hide();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($nonCapturing->focused);
    }

    public function testSubOverlayCleanupThenHideOverlayRestoresFocusAndInputToEditor(): void
    {
        $editor = self::overlay('EDITOR');
        $timer = self::overlay('TIMER');
        $controller = self::overlay('CTRL');
        $tui = $this->start($editor);

        $timerHandle = $tui->showOverlay($timer, self::nc());
        $tui->showOverlay($controller);
        $this->assertTrue($controller->focused);
        $this->assertFalse($editor->focused);
        $timerHandle->hide();
        $tui->hideOverlay();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($controller->focused);
        $this->assertFalse($timer->focused);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $editor->inputs);
        $this->assertSame([], $controller->inputs);
        $this->assertSame([], $timer->inputs);
    }

    public function testRemovedFocusedChildOverlayDoesNotBecomeParentOverlayFallback(): void
    {
        $editor = self::overlay('EDITOR');
        $child = self::overlay('CHILD');
        $parent = self::overlay('PARENT');
        $tui = $this->start($editor);

        $childHandle = $tui->showOverlay($child, self::nc());
        $childHandle->focus();
        $parentHandle = $tui->showOverlay($parent);
        $this->assertTrue($parent->focused);

        $childHandle->hide();
        $parentHandle->hide();
        $this->send('x');
        $this->renderAndFlush();

        $this->assertSame(['x'], $editor->inputs);
        $this->assertSame([], $child->inputs);
        $this->assertSame([], $parent->inputs);
        $this->assertTrue($editor->focused);
    }

    public function testMicrotaskDeferredSubOverlayPatternRestoresFocus(): void
    {
        $editor = self::overlay('EDITOR');
        $timer = self::overlay('TIMER');
        $controller = self::overlay('CTRL');
        $tui = $this->start($editor);

        // showExtensionCustom: the factory shows the timer synchronously, then a `.then()` shows
        // the controller on a later turn.
        $timerHandle = $tui->showOverlay($timer, self::nc());
        $done = static function () use ($timerHandle, $tui): void {
            $timerHandle->hide();
            $tui->hideOverlay();
        };
        Loop::get()->defer(static function () use ($tui, $controller): void {
            $tui->showOverlay($controller);
        });

        $this->renderAndFlush();

        $this->assertTrue($controller->focused);
        $this->assertFalse($editor->focused);

        // Esc: cleanup and close from inside handleInput.
        $done();
        $this->renderAndFlush();

        $this->assertTrue($editor->focused, 'editor should regain focus');
        $this->assertFalse($controller->focused);
        $this->assertFalse($timer->focused);

        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $editor->inputs, 'editor should receive input after close');
        $this->assertSame([], $controller->inputs);
    }

    public function testHandleInputRedirectionSkipsNonCapturingOverlaysWhenFocusedOverlayBecomesInvisible(): void
    {
        $editor = self::overlay('EDITOR');
        $fallbackCapturing = self::overlay('FALLBACK');
        $nonCapturing = self::overlay('NC');
        $primary = self::overlay('PRIMARY');
        $isVisible = true;
        $tui = $this->start($editor);

        $tui->showOverlay($fallbackCapturing);
        $tui->showOverlay($nonCapturing, self::nc());
        $tui->showOverlay($primary, new OverlayOptions(visible: static function () use (&$isVisible): bool {
            return $isVisible;
        }));
        $this->assertTrue($primary->focused);
        $isVisible = false;
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame([], $primary->inputs);
        $this->assertSame([], $nonCapturing->inputs);
        $this->assertSame(['x'], $fallbackCapturing->inputs);
        $this->assertTrue($fallbackCapturing->focused);
    }

    public function testActiveBaseFocusReplacementReceivesCloseInputBeforeOverlayRestore(): void
    {
        $editor = self::overlay('EDITOR');
        $replacement = self::overlay('REPLACEMENT');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);
        $overlay->onInput = static function (string $data) use ($tui, $replacement): void {
            if ($data === 'b') {
                $tui->setFocus($replacement);
            }
        };
        $replacement->onInput = static function (string $data) use ($tui, $editor): void {
            if ($data === "\r") {
                $tui->setFocus($editor);
            }
        };

        $tui->showOverlay($overlay);
        $this->assertTrue($overlay->focused);
        $this->send('b');
        $this->renderAndFlush();
        $this->assertTrue($replacement->focused);

        $this->send("\r");
        $this->renderAndFlush();
        $this->assertSame(["\r"], $replacement->inputs);
        $this->assertSame(['b'], $overlay->inputs);
        $this->assertTrue($overlay->focused);

        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['b', 'x'], $overlay->inputs);
    }

    public function testActiveReplacementStillReceivesInputWhenItIsAnotherOverlayPreFocus(): void
    {
        $editor = self::overlay('EDITOR');
        $replacement = self::overlay('REPLACEMENT');
        $passive = self::overlay('PASSIVE');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);
        $overlay->onInput = static function (string $data) use ($tui, $replacement): void {
            if ($data === 'b') {
                $tui->setFocus($replacement);
            }
        };
        $replacement->onInput = static function (string $data) use ($tui, $editor): void {
            if ($data === "\r") {
                $tui->setFocus($editor);
            }
        };

        $tui->setFocus($replacement);
        $tui->showOverlay($passive, self::nc());
        $tui->setFocus($editor);
        $tui->showOverlay($overlay);
        $this->send('b');
        $this->renderAndFlush();
        $this->assertTrue($replacement->focused);

        $this->send('1');
        $this->send("\r");
        $this->renderAndFlush();
        $this->assertSame(['1', "\r"], $replacement->inputs);
        $this->assertSame(['b'], $overlay->inputs);
        $this->assertTrue($overlay->focused);
    }

    public function testBlockedReplacementCanMoveFocusInternallyBeforeOverlayRestore(): void
    {
        $base = new Container();
        $editor = self::overlay('EDITOR');
        $firstReplacement = self::overlay('FIRST');
        $secondReplacement = self::overlay('SECOND');
        $overlay = self::overlay('OVERLAY');
        $base->addChild($editor);
        $base->addChild($firstReplacement);
        $base->addChild($secondReplacement);
        $tui = $this->start($editor, $base);
        $overlay->onInput = static function (string $data) use ($tui, $firstReplacement): void {
            if ($data === 'b') {
                $tui->setFocus($firstReplacement);
            }
        };
        $firstReplacement->onInput = static function (string $data) use ($tui, $secondReplacement): void {
            if ($data === 'n') {
                $tui->setFocus($secondReplacement);
            }
        };
        $secondReplacement->onInput = static function (string $data) use ($tui, $base, $editor): void {
            if ($data === "\r") {
                $base->clear();
                $base->addChild($editor);
                $tui->setFocus($editor);
            }
        };

        $tui->showOverlay($overlay);
        $this->send('b');
        $this->renderAndFlush();
        $this->send('n');
        $this->renderAndFlush();
        $this->send('2');
        $this->send("\r");
        $this->renderAndFlush();

        $this->assertSame(['b'], $overlay->inputs);
        $this->assertSame(['n'], $firstReplacement->inputs);
        $this->assertSame(['2', "\r"], $secondReplacement->inputs);
        $this->assertTrue($overlay->focused);
    }

    public function testRemovedReplacementRestoresOverlayEvenWhenOverlayPreFocusDiffersFromNextFocus(): void
    {
        $base = new Container();
        $editor = self::overlay('EDITOR');
        $palette = self::overlay('PALETTE');
        $replacement = self::overlay('REPLACEMENT');
        $overlay = self::overlay('OVERLAY');
        $base->addChild($editor);
        $base->addChild($palette);
        $base->addChild($replacement);
        $tui = $this->start($palette, $base);
        $overlay->onInput = static function (string $data) use ($tui, $replacement): void {
            if ($data === 'b') {
                $tui->setFocus($replacement);
            }
        };
        $replacement->onInput = static function (string $data) use ($tui, $base, $editor): void {
            if ($data === "\r") {
                $base->clear();
                $base->addChild($editor);
                $tui->setFocus($editor);
            }
        };

        $tui->showOverlay($overlay);
        $this->send('b');
        $this->renderAndFlush();
        $this->send("\r");
        $this->send('x');
        $this->renderAndFlush();

        $this->assertSame(['b', 'x'], $overlay->inputs);
        $this->assertSame(["\r"], $replacement->inputs);
        $this->assertSame([], $editor->inputs);
        $this->assertTrue($overlay->focused);
    }

    public function testUnfocusTargetReleasesABlockedOverlayWhileReplacementRemainsFocused(): void
    {
        $fallback = self::overlay('FALLBACK');
        $target = self::overlay('TARGET');
        $replacement = self::overlay('REPLACEMENT');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start();
        $replacement->onInput = static function (string $data) use ($tui, $fallback): void {
            if ($data === "\r") {
                $tui->setFocus($fallback);
            }
        };

        $overlayHandle = $tui->showOverlay($overlay);
        $overlay->onInput = static function (string $data) use ($tui, $replacement, $overlayHandle, $target): void {
            if ($data === 'b') {
                $tui->setFocus($replacement);
                $overlayHandle->unfocus($target, hasTarget: true);
            }
        };

        $this->send('b');
        $this->renderAndFlush();
        $this->assertTrue($replacement->focused);
        $this->send("\r");
        $this->send('x');
        $this->renderAndFlush();

        $this->assertSame(['b'], $overlay->inputs);
        $this->assertSame(["\r"], $replacement->inputs);
        $this->assertSame([], $fallback->inputs);
        $this->assertSame(['x'], $target->inputs);
    }

    public function testHandleInputRestoresFocusToAVisibleFocusedOverlayAfterBaseFocusSteal(): void
    {
        $editor = self::overlay('EDITOR');
        $replacement = self::overlay('REPLACEMENT');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $tui->showOverlay($overlay);
        $this->assertTrue($overlay->focused);
        $tui->setFocus($replacement);
        $tui->setFocus($editor);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $overlay->inputs);
        $this->assertSame([], $editor->inputs);
        $this->assertTrue($overlay->focused);
    }

    public function testHandleInputRestoresFocusToExplicitlyFocusedRawSubOverlayAfterBaseFocusSteal(): void
    {
        $editor = self::overlay('EDITOR');
        $controller = self::overlay('CONTROLLER');
        $subOverlay = self::overlay('SUB');
        $tui = $this->start($editor);

        $tui->showOverlay($controller);
        $subHandle = $tui->showOverlay($subOverlay, self::nc());
        $subHandle->focus();
        $tui->setFocus($editor);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $subOverlay->inputs);
        $this->assertSame([], $controller->inputs);
        $this->assertSame([], $editor->inputs);
    }

    public function testPassiveNonCapturingOverlayDoesNotRegainInputAfterBaseFocus(): void
    {
        $editor = self::overlay('EDITOR');
        $passive = self::overlay('PASSIVE');
        $tui = $this->start($editor);

        $tui->showOverlay($passive, self::nc());
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $editor->inputs);
        $this->assertSame([], $passive->inputs);
        $this->assertTrue($editor->focused);
    }

    public function testExplicitlyFocusedNonCapturingOverlayRegainsInputAfterBaseFocusSteal(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('NC');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->focus();
        $tui->setFocus($editor);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $overlay->inputs);
        $this->assertSame([], $editor->inputs);
    }

    public function testUnfocusPreventsVisibleOverlayFromRegainingInput(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay);
        $handle->unfocus();
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $editor->inputs);
        $this->assertSame([], $overlay->inputs);
        $this->assertTrue($editor->focused);
    }

    public function testSetFocusNullExplicitlyClearsVisibleOverlayRestore(): void
    {
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start();

        $tui->showOverlay($overlay);
        $tui->setFocus(null);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame([], $overlay->inputs);
        $this->assertFalse($overlay->focused);
    }

    public function testBlockedReplacementSetFocusNullResumesTheVisibleOverlay(): void
    {
        $replacement = self::overlay('REPLACEMENT');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start();
        $replacement->onInput = static function (string $data) use ($tui): void {
            if ($data === "\r") {
                $tui->setFocus(null);
            }
        };
        $overlay->onInput = static function (string $data) use ($tui, $replacement): void {
            if ($data === 'b') {
                $tui->setFocus($replacement);
            }
        };

        $tui->showOverlay($overlay);
        $this->send('b');
        $this->renderAndFlush();
        $this->send("\r");
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(["\r"], $replacement->inputs);
        $this->assertSame(['b', 'x'], $overlay->inputs);
        $this->assertTrue($overlay->focused);
    }

    public function testTemporarilyInvisibleFocusedOverlayFallsBackWithoutLosingRestoreEligibility(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $visible = true;
        $tui = $this->start($editor);

        $tui->showOverlay($overlay, new OverlayOptions(visible: static function () use (&$visible): bool {
            return $visible;
        }));
        $tui->setFocus($editor);
        $visible = false;
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $editor->inputs);
        $this->assertSame([], $overlay->inputs);
        $visible = true;
        $this->send('y');
        $this->renderAndFlush();
        $this->assertSame(['x'], $editor->inputs);
        $this->assertSame(['y'], $overlay->inputs);
    }

    public function testTemporarilyInvisibleFocusedOverlayWithNullPreFocusRestoresWhenVisibleAgain(): void
    {
        $overlay = self::overlay('OVERLAY');
        $visible = true;
        $tui = $this->start();

        $tui->showOverlay($overlay, new OverlayOptions(visible: static function () use (&$visible): bool {
            return $visible;
        }));
        $visible = false;
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame([], $overlay->inputs);
        $visible = true;
        $this->send('y');
        $this->renderAndFlush();
        $this->assertSame(['y'], $overlay->inputs);
    }

    public function testCyclicOverlayPreFocusAncestryDoesNotHangFocusChanges(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($overlay);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->focus();
        $tui->setFocus($editor);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $editor->inputs);
        $this->assertSame([], $overlay->inputs);
    }

    public function testHandleInputRestoresTheFocusOrderTopOverlayAfterBaseFocusSteal(): void
    {
        $editor = self::overlay('EDITOR');
        $lower = self::overlay('LOWER');
        $upper = self::overlay('UPPER');
        $tui = $this->start($editor);

        $lowerHandle = $tui->showOverlay($lower);
        $tui->showOverlay($upper);
        $lowerHandle->focus();
        $tui->setFocus($editor);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $lower->inputs);
        $this->assertSame([], $upper->inputs);
        $this->assertSame([], $editor->inputs);
    }

    public function testHideOverlayDoesNotReassignFocusWhenTopmostOverlayIsNonCapturing(): void
    {
        $editor = self::overlay('EDITOR');
        $capturing = self::overlay('CAP');
        $nonCapturing = self::overlay('NC');
        $tui = $this->start($editor);

        $tui->showOverlay($capturing);
        $tui->showOverlay($nonCapturing, self::nc());
        $this->assertTrue($capturing->focused);
        $tui->hideOverlay();
        $this->renderAndFlush();
        $this->assertTrue($capturing->focused);
    }

    public function testMultipleCapturingAndNonCapturingOverlaysRestoreFocusThroughRemovals(): void
    {
        $editor = self::overlay('EDITOR');
        $c1 = self::overlay('C1');
        $n1 = self::overlay('N1');
        $c2 = self::overlay('C2');
        $n2 = self::overlay('N2');
        $tui = $this->start($editor);

        $c1Handle = $tui->showOverlay($c1);
        $tui->showOverlay($n1, self::nc());
        $c2Handle = $tui->showOverlay($c2);
        $tui->showOverlay($n2, self::nc());
        $this->assertTrue($c2->focused);
        $c2Handle->hide();
        $this->renderAndFlush();
        $this->assertTrue($c1->focused);
        $c1Handle->hide();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
    }

    public function testCapturingOverlayUnfocusOnTopmostCapturingOverlayFallsBackToPreFocus(): void
    {
        $editor = self::overlay('EDITOR');
        $capturing = self::overlay('CAP');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($capturing);
        $this->assertTrue($capturing->focused);
        $handle->unfocus();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($capturing->focused);
    }

    // no-op guards

    public function testFocusOnHiddenOverlayIsANoOp(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->setHidden(true);
        $handle->focus();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($handle->isFocused());
    }

    public function testFocusAfterHideIsANoOp(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->hide();
        $handle->focus();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($handle->isFocused());
    }

    public function testUnfocusWhenOverlayDoesNotHaveFocusIsANoOp(): void
    {
        $editor = self::overlay('EDITOR');
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start($editor);

        $handle = $tui->showOverlay($overlay, self::nc());
        $handle->unfocus();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($overlay->focused);
    }

    public function testUnfocusWithNullPreFocusClearsFocusAndDoesNotRouteInputBackToOverlay(): void
    {
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start();

        $handle = $tui->showOverlay($overlay);
        $this->assertTrue($overlay->focused);
        $handle->unfocus();
        $this->assertFalse($overlay->focused);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame([], $overlay->inputs);
        $this->assertFalse($handle->isFocused());
    }

    // focus cycle prevention

    public function testToggleFocusBetweenNonCapturingOverlaysThenUnfocusReturnsToEditor(): void
    {
        $editor = self::overlay('EDITOR');
        $a = self::overlay('A');
        $b = self::overlay('B');
        $tui = $this->start($editor);

        $aHandle = $tui->showOverlay($a, self::nc());
        $bHandle = $tui->showOverlay($b, self::nc());
        $aHandle->focus();
        $bHandle->focus();
        $aHandle->focus();
        $aHandle->unfocus();
        $this->renderAndFlush();
        $this->assertTrue($editor->focused);
        $this->assertFalse($a->focused);
        $this->assertFalse($b->focused);
    }

    public function testExplicitUnfocusTargetSupportsCyclingBetweenThreeOverlaysAndEditor(): void
    {
        $editor = self::overlay('EDITOR');
        $a = self::overlay('A');
        $b = self::overlay('B');
        $c = self::overlay('C');
        $tui = $this->start($editor);

        $aHandle = $tui->showOverlay($a);
        $bHandle = $tui->showOverlay($b);
        $cHandle = $tui->showOverlay($c);

        $aHandle->focus();
        $this->send('a');
        $this->renderAndFlush();
        $bHandle->focus();
        $this->send('b');
        $this->renderAndFlush();
        $cHandle->focus();
        $this->send('c');
        $this->renderAndFlush();
        $cHandle->unfocus($editor, hasTarget: true);
        $this->send('e');
        $this->renderAndFlush();
        $aHandle->focus();
        $this->send('A');
        $this->renderAndFlush();
        $aHandle->unfocus($editor, hasTarget: true);
        $this->send('E');
        $this->renderAndFlush();

        $this->assertSame(['a', 'A'], $a->inputs);
        $this->assertSame(['b'], $b->inputs);
        $this->assertSame(['c'], $c->inputs);
        $this->assertSame(['e', 'E'], $editor->inputs);
        $this->assertTrue($editor->focused);
    }

    public function testExplicitNullUnfocusTargetClearsFocusWithoutRestoringOverlays(): void
    {
        $overlay = self::overlay('OVERLAY');
        $tui = $this->start();

        $handle = $tui->showOverlay($overlay);
        $handle->unfocus(null, hasTarget: true);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame([], $overlay->inputs);
        $this->assertFalse($handle->isFocused());
    }

    public function testHidingFocusedOverlayFallsBackToNextVisualFrontmostOverlay(): void
    {
        $editor = self::overlay('EDITOR');
        $a = self::overlay('A');
        $b = self::overlay('B');
        $c = self::overlay('C');
        $tui = $this->start($editor);

        $aHandle = $tui->showOverlay($a);
        $bHandle = $tui->showOverlay($b);
        $tui->showOverlay($c);
        $aHandle->focus();
        $bHandle->focus();
        $bHandle->setHidden(true);
        $this->send('x');
        $this->renderAndFlush();
        $this->assertSame(['x'], $a->inputs);
        $this->assertSame([], $c->inputs);
        $this->assertTrue($a->focused);
    }

    // rendering order

    public function testFocusOnAlreadyFocusedOverlayBumpsVisualOrder(): void
    {
        $editor = self::overlay('EDITOR');
        $tui = $this->start($editor, null, 20, 6);

        $aHandle = $tui->showOverlay(new StaticOverlay(['A']), self::cell());
        $tui->showOverlay(new StaticOverlay(['B']), self::cell());
        $aHandle->focus();
        $tui->showOverlay(new StaticOverlay(['C']), self::cell());
        $this->renderAndFlush();
        $this->assertSame('C', $this->topLeft());
        $aHandle->focus();
        $this->renderAndFlush();
        $this->assertSame('A', $this->topLeft());
        $this->assertTrue($aHandle->isFocused());
    }

    public function testDefaultRenderingOrderForOverlappingOverlaysFollowsCreationOrder(): void
    {
        $tui = $this->start(null, null, 20, 6);

        $tui->showOverlay(new StaticOverlay(['A']), self::cell());
        $tui->showOverlay(new StaticOverlay(['B']), self::cell());
        $this->renderAndFlush();
        $this->assertSame('B', $this->topLeft());
    }

    public function testFocusOnLowerOverlayRendersItOnTop(): void
    {
        $tui = $this->start(null, null, 20, 6);

        $lower = $tui->showOverlay(new StaticOverlay(['A']), self::cell());
        $tui->showOverlay(new StaticOverlay(['B']), self::cell());
        $this->renderAndFlush();
        $this->assertSame('B', $this->topLeft());
        $lower->focus();
        $this->renderAndFlush();
        $this->assertSame('A', $this->topLeft());
    }

    public function testFocusingMiddleOverlayPlacesItOnTopWhilePreservingOthersRelativeOrder(): void
    {
        $tui = $this->start(null, null, 20, 6);

        $tui->showOverlay(new StaticOverlay(['A']), self::cell());
        $middle = $tui->showOverlay(new StaticOverlay(['B']), self::cell());
        $top = $tui->showOverlay(new StaticOverlay(['C']), self::cell());
        $this->renderAndFlush();
        $this->assertSame('C', $this->topLeft());
        $middle->focus();
        $this->renderAndFlush();
        $this->assertSame('B', $this->topLeft());
        $middle->hide();
        $this->renderAndFlush();
        $this->assertSame('C', $this->topLeft());
        $top->hide();
        $this->renderAndFlush();
        $this->assertSame('A', $this->topLeft());
    }

    public function testCapturingOverlayHiddenAndShownAgainRendersOnTopAfterUnhide(): void
    {
        $tui = $this->start(null, null, 20, 6);

        $tui->showOverlay(new StaticOverlay(['A']), self::cell());
        $capturing = $tui->showOverlay(new StaticOverlay(['B']), self::cell(nonCapturing: false));
        $this->renderAndFlush();
        $this->assertSame('B', $this->topLeft());
        $capturing->setHidden(true);
        $tui->showOverlay(new StaticOverlay(['C']), self::cell());
        $this->renderAndFlush();
        $this->assertSame('C', $this->topLeft());
        $capturing->setHidden(false);
        $this->renderAndFlush();
        $this->assertSame('B', $this->topLeft());
    }

    public function testUnfocusDoesNotChangeVisualOrderUntilAnotherOverlayIsFocused(): void
    {
        $editor = self::overlay('EDITOR');
        $tui = $this->start($editor, null, 20, 6);

        $a = $tui->showOverlay(new StaticOverlay(['A']), self::cell());
        $b = $tui->showOverlay(new StaticOverlay(['B']), self::cell());
        $this->renderAndFlush();
        $this->assertSame('B', $this->topLeft());
        $a->focus();
        $this->renderAndFlush();
        $this->assertSame('A', $this->topLeft());
        $a->unfocus();
        $this->renderAndFlush();
        $this->assertSame('A', $this->topLeft());
        $b->focus();
        $this->renderAndFlush();
        $this->assertSame('B', $this->topLeft());
    }
}
