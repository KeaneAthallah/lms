import { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { apiError } from '../api';
import { useAuth } from '../auth';
import { Button, Field, Icon, Input } from '../components/ui';

export default function Register() {
    const { isAuthenticated, register } = useAuth();
    const navigate = useNavigate();

    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const [errors, setErrors] = useState({});
    const [loading, setLoading] = useState(false);
    const [globalError, setGlobalError] = useState('');

    if (isAuthenticated) {
        return <Navigate to="/" replace />;
    }

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    const submit = async (e) => {
        e.preventDefault();
        setErrors({});
        setGlobalError('');
        setLoading(true);
        try {
            await register(form);
            navigate('/', { replace: true });
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
                <h1 className="text-2xl font-bold text-slate-900">Create your account</h1>
                <p className="mt-1 text-sm text-slate-500">Start learning in minutes.</p>

                {globalError ? (
                    <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{globalError}</div>
                ) : null}

                <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
                    <Field label="Full name" error={errors.name?.[0]} required>
                        <Input autoComplete="name" value={form.name} onChange={set('name')} placeholder="Jane Doe" />
                    </Field>
                    <Field label="Email address" error={errors.email?.[0]} required>
                        <Input type="email" autoComplete="email" value={form.email} onChange={set('email')} placeholder="you@example.com" />
                    </Field>
                    <Field label="Password" hint="At least 8 characters." error={errors.password?.[0]} required>
                        <Input type="password" autoComplete="new-password" value={form.password} onChange={set('password')} placeholder="••••••••" />
                    </Field>
                    <Field label="Confirm password" error={errors.password_confirmation?.[0]} required>
                        <Input
                            type="password"
                            autoComplete="new-password"
                            value={form.password_confirmation}
                            onChange={set('password_confirmation')}
                            placeholder="••••••••"
                        />
                    </Field>
                    <Button type="submit" loading={loading} className="w-full" size="lg">
                        Create account
                    </Button>
                </form>

                <p className="mt-6 text-center text-sm text-slate-500">
                    Already have an account?{' '}
                    <Link to="/login" className="font-semibold text-brand-600 hover:text-brand-700">
                        Sign in
                    </Link>
                </p>
            </div>
        </div>
    );
}