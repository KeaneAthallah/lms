import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

export const THEME_STORAGE_KEY = 'lms.theme';
export const THEME_MODES = ['system', 'light', 'dark'];

const ThemeContext = createContext(null);

function readPreference() {
    try {
        const stored = localStorage.getItem(THEME_STORAGE_KEY);
        if (THEME_MODES.includes(stored)) return stored;
    } catch {
        // ignore storage errors — fall back to system
    }
    return 'system';
}

function systemDark() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function resolveTheme(preference, systemIsDark) {
    if (preference === 'system') return systemIsDark ? 'dark' : 'light';
    return preference;
}

function applyTheme(resolved) {
    const root = document.documentElement;
    root.classList.toggle('dark', resolved === 'dark');
    root.style.colorScheme = resolved;
}

export function ThemeProvider({ children }) {
    const [preference, setPreference] = useState(readPreference);
    const [systemIsDark, setSystemIsDark] = useState(systemDark);

    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = (event) => setSystemIsDark(event.matches);
        media.addEventListener('change', onChange);
        return () => media.removeEventListener('change', onChange);
    }, []);

    const resolved = resolveTheme(preference, systemIsDark);

    useEffect(() => {
        applyTheme(resolved);
    }, [resolved]);

    useEffect(() => {
        try {
            localStorage.setItem(THEME_STORAGE_KEY, preference);
        } catch {
            // storage unavailable — theme just won't persist
        }
    }, [preference]);

    const setTheme = useCallback((next) => {
        setPreference(THEME_MODES.includes(next) ? next : 'system');
    }, []);

    const toggle = useCallback(() => {
        setPreference((previous) => (resolveTheme(previous, systemDark()) === 'dark' ? 'light' : 'dark'));
    }, []);

    const value = useMemo(
        () => ({ preference, resolved, systemIsDark, setTheme, toggle }),
        [preference, resolved, systemIsDark, setTheme, toggle],
    );

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
    const context = useContext(ThemeContext);
    if (!context) throw new Error('useTheme must be used within a ThemeProvider');
    return context;
}