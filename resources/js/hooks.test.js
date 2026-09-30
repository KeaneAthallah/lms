import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { usePolling } from './hooks';

/**
 * `usePolling` carries four behaviours that entry 42 introduced and nothing
 * verified. They are all invisible in review and all were the point of the
 * change, so this suite pins them.
 *
 * Two things about writing these. `tick()` is async, so the in-flight guard is
 * only released once a microtask has run — the timers are advanced with
 * `advanceTimersByTimeAsync` rather than the synchronous variant, or the second
 * tick is skipped for the wrong reason and the test would pass while asserting
 * nothing about the guard. And `document.hidden` is not writable in jsdom, so
 * it is redefined per test; the hook only ever reads it (on mount and on
 * `visibilitychange`), so a getter over a variable is enough.
 */
describe('usePolling', () => {
    let tabVisible = true;

    beforeEach(() => {
        vi.useFakeTimers();
        tabVisible = true;

        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => !tabVisible,
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    const advance = async (ms) => {
        await act(async () => {
            await vi.advanceTimersByTimeAsync(ms);
        });
    };

    const hide = async () => {
        tabVisible = false;
        await act(async () => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
    };

    const show = async () => {
        tabVisible = true;
        await act(async () => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
    };

    it('does not poll on mount, because callers load on mount themselves', async () => {
        const callback = vi.fn();
        renderHook(() => usePolling(callback, { interval: 1000 }));

        expect(callback).not.toHaveBeenCalled();

        await advance(1000);

        expect(callback).toHaveBeenCalledTimes(1);
    });

    it('polls on every interval once started', async () => {
        const callback = vi.fn();
        renderHook(() => usePolling(callback, { interval: 1000 }));

        await advance(3000);

        expect(callback).toHaveBeenCalledTimes(3);
    });

    it('does not stack requests when one is still in flight', async () => {
        let resolveFirst;
        const callback = vi.fn(() => new Promise((resolve) => {
            resolveFirst = resolve;
        }));

        renderHook(() => usePolling(callback, { interval: 1000 }));

        // A first tick that will not settle.
        await advance(1000);
        expect(callback).toHaveBeenCalledTimes(1);

        // Two more intervals pass while it is still outstanding. This is the race
        // the hook exists to prevent: without the in-flight guard these would be
        // three concurrent requests, and whichever resolved last would win.
        await advance(2000);
        expect(callback).toHaveBeenCalledTimes(1);

        await act(async () => {
            resolveFirst();
            await Promise.resolve();
        });

        // Once it settles, polling resumes.
        await advance(1000);
        expect(callback).toHaveBeenCalledTimes(2);
    });

    it('stops polling while the tab is hidden', async () => {
        const callback = vi.fn();
        renderHook(() => usePolling(callback, { interval: 1000 }));

        await advance(1000);
        expect(callback).toHaveBeenCalledTimes(1);

        await hide();

        await advance(5000);

        expect(callback).toHaveBeenCalledTimes(1);
    });

    it('catches up immediately when the tab becomes visible again', async () => {
        const callback = vi.fn();
        renderHook(() => usePolling(callback, { interval: 1000 }));

        await advance(1000);
        expect(callback).toHaveBeenCalledTimes(1);

        await hide();
        await show();

        // The user who switched away for a minute is the one who needs current
        // state now, not after another full interval.
        expect(callback).toHaveBeenCalledTimes(2);
    });

    it('catches up on a return to visibility even if no interval has fired yet', async () => {
        const callback = vi.fn();
        renderHook(() => usePolling(callback, { interval: 1000 }));

        // `hasPolled` is set when the effect runs while visible, so the hook
        // cannot distinguish "has polled" from "has been active". A tab hidden
        // and shown again inside the first interval is therefore treated as a
        // return from hidden and polls immediately, costing one request beyond
        // the mount load. That is the documented intent — only the *first* poll
        // is skipped — and it is pinned here so the two are not confused later.
        await hide();
        await show();

        expect(callback).toHaveBeenCalledTimes(1);
    });

    it('does not restart the timer when the callback identity changes', async () => {
        const callback = vi.fn();
        const { rerender } = renderHook(
            ({ cb }) => usePolling(cb, { interval: 1000 }),
            { initialProps: { cb: callback } }
        );

        await advance(1000);
        expect(callback).toHaveBeenCalledTimes(1);

        // An inline arrow is a new function every render. If the callback were
        // an effect dependency the timer would reset on each render and a
        // re-rendering parent would starve the poll entirely.
        const replacement = vi.fn();
        rerender({ cb: replacement });

        await advance(1000);

        expect(replacement).toHaveBeenCalledTimes(1);
        expect(callback).toHaveBeenCalledTimes(1);
    });

    it('does not poll when disabled', async () => {
        const callback = vi.fn();
        renderHook(() => usePolling(callback, { interval: 1000, enabled: false }));

        await advance(5000);

        expect(callback).not.toHaveBeenCalled();
    });

    it('clears its interval on unmount', async () => {
        const callback = vi.fn();
        const { unmount } = renderHook(() => usePolling(callback, { interval: 1000 }));

        unmount();

        await advance(5000);

        expect(callback).not.toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
    });
});
