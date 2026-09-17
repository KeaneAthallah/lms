import { useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import api, { apiError } from '../api';
import { useAuth } from '../auth';
import { Button, Field, Icon, Input, Spinner } from '../components/ui';

export default function Login() {
    const { isAuthenticated, login } = useAuth();
    const navigate = useNavigate();
    const [params] = useSearchParams();
    const next = params.get('next') || '/';

    const [form, setForm] = useState({ email: '', password: '' });
    const [errors, setErrors] = useState({});
    const [loading, setLoading] = useState(false);
    const [globalError, setGlobalError] = useState('');

    if (isAuthenticated) {
        return <Navigate to={next} replace />;
    }

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    const submit = async (e) => {
        e.preventDefault();
        setErrors({});
        setGlobalError('');
        setLoading(true);
        try {
            await login(form.email.trim(), form.password);
            navigate(next, { replace: true });
        } catch (error) {
            const response = error.response?.data;
            if (response?.errors) {
                setErrors(response.errors);
            } else {
                setGlobalError(apiError(error));
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-brand-700 via-brand-800 to-gray-900 px-4 py-10">
            <Link to="/" className="mb-6 flex items-center gap-2.5 text-white dark:text-gray-50">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/20">
                    <Icon name="book" className="h-4.5 w-4.5" />
                </span>
                <span className="text-lg font-bold">{window.__LMS_CONFIG__?.institutionName ?? 'LMS'}</span>
            </Link>

            <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-2xl">
                <h1 className="text-2xl font-bold text-slate-900">Welcome back</h1>
                <p className="mt-1 text-sm text-slate-500">Sign in to continue to your courses.</p>

                {globalError ? (
                    <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{globalError}</div>
                ) : null}

                <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
                    <Field label="Email address" error={errors.email?.[0]} required>
                        <Input type="email" autoComplete="email" value={form.email} onChange={set('email')} placeholder="you@example.com" />
                    </Field>
                    <Field label="Password" error={errors.password?.[0]} required>
                        <Input type="password" autoComplete="current-password" value={form.password} onChange={set('password')} placeholder="••••••••" />
                    </Field>
                    <Button type="submit" loading={loading} className="w-full" size="lg">
                        Sign in
                    </Button>
                </form>

                <p className="mt-6 text-center text-sm text-slate-500">
                    Don&apos;t have an account?{' '}
                    <Link to="/register" className="font-semibold text-brand-600 hover:text-brand-700">
                        Create one
                    </Link>
                </p>

                <div className="mt-6 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-500">
                    <p className="font-semibold text-slate-600">Demo accounts</p>
                    <p>Student: alex@example.com · password</p>
                    <p>Instructor: sarah@example.com · password</p>
                    <p>Admin: admin@example.com · password</p>
                </div>
            </div>
        </div>
    );
}