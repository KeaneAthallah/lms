import { useEffect, useRef, useState } from 'react';

/**
 * Calls `callback` on an interval, on one timer, with three things a naive
 * `setInterval` gets wrong.
 *
 * It stops while the tab is hidden. A support inbox left open in a background
 * tab is still a request every eight seconds against a table it will not draw.
 * It catches up on the way back, because the user who switched away for a minute
 * is exactly the one who needs the current state immediately, not in eight
 * seconds' time.
 *
 * And it never stacks. An interval that fires while the previous request is
 * still in flight lets two responses race, and the older one can land last and
 * overwrite newer state — a message that arrives in the thread view and then
 * vanishes.
 *
 * The callback is held in a ref, so passing an inline arrow does not restart the
 * timer on every render.
 *
 * @param {() => Promise<unknown>|unknown} callback
 * @param {{ interval?: number, enabled?: boolean }} [options]
 */
export function usePolling(callback, { interval = 8000, enabled = true } = {}) {
    const callbackRef = useRef(callback);
    callbackRef.current = callback;

    const [hidden, setHidden] = useState(() => typeof document !== 'undefined' && document.hidden);
    const inFlight = useRef(false);
    const hasPolled = useRef(false);

    useEffect(() => {
        const onVisibilityChange = () => setHidden(document.hidden);

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => document.removeEventListener('visibilitychange', onVisibilityChange);
    }, []);

    useEffect(() => {
        if (!enabled || hidden) return undefined;

        const tick = async () => {
            if (inFlight.current) return;

            inFlight.current = true;

            try {
                await callbackRef.current();
            } finally {
                inFlight.current = false;
            }
        };

        // The first poll is skipped because callers load on mount themselves; every
        // one after it is the return from a hidden tab.
        if (hasPolled.current) tick();
        hasPolled.current = true;

        const timer = window.setInterval(tick, interval);

        return () => window.clearInterval(timer);
    }, [enabled, hidden, interval]);
}
