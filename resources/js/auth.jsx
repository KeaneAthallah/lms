import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import api from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(() => window.__LMS_USER__ ?? null);

    const login = useCallback(async (email, password) => {
        const { data } = await api.post('/api/login', { email, password });
        setUser(data.data);
        return data;
    }, []);

    const register = useCallback(async (payload) => {
        const { data } = await api.post('/api/register', payload);
        setUser(data.data);
        return data;
    }, []);

    const logout = useCallback(async () => {
        try {
            await api.post('/api/logout');
        } catch {
            // ignore network errors on logout
        }
        setUser(null);
        window.__LMS_USER__ = null;
        window.location.assign('/');
    }, []);

    const refresh = useCallback(async () => {
        const { data } = await api.get('/api/me');
        setUser(data.data);
        window.__LMS_USER__ = data.data;
        return data.data;
    }, []);

    const updateUser = useCallback((patch) => {
        setUser((prev) => ({ ...prev, ...patch }));
    }, []);

    const value = useMemo(() => {
        const roles = user?.roles ?? [];
        const permissions = user?.permissions ?? [];

        return {
            user,
            setUser,
            login,
            register,
            logout,
            refresh,
            updateUser,
            isAuthenticated: Boolean(user),
            isAdmin: roles.includes('admin'),
            isInstructor: roles.includes('instructor'),
            isStudent: roles.includes('student'),
            hasRole: (role) => roles.includes(role),
            can: (permission) => permissions.includes(permission) || roles.includes('admin'),
        };
    }, [user, login, register, logout, refresh, updateUser]);

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return ctx;
}