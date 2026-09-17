import { useRef, useState } from 'react';
import api, { apiError } from '../api';
import { useAuth } from '../auth';
import { Avatar, Button, Field, Icon, Input, PageHeader, Section, Textarea, useToast } from '../components/ui';

export default function Profile() {
    const { user, setUser } = useAuth();
    const toast = useToast();
    const inputRef = useRef(null);

    const [form, setForm] = useState({
        name: user?.name ?? '',
        email: user?.email ?? '',
        headline: user?.headline ?? '',
        bio: user?.bio ?? '',
    });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [avatarFile, setAvatarFile] = useState(null);

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    const save = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});

        const formData = new FormData();
        Object.entries(form).forEach(([key, value]) => formData.append(key, value ?? ''));
        if (avatarFile) formData.append('avatar', avatarFile);

        try {
            const { data } = await api.post('/api/profile', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setUser({ ...user, ...data.data, avatar: data.data.avatar_url });
            setAvatarFile(null);
            toast('Profile updated.', 'success');
        } catch (err) {
            const response = err.response?.data;
            setErrors(response?.errors ?? {});
            toast(apiError(err), 'error');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <PageHeader icon="user" title="Profile & settings" subtitle="Update your personal information and avatar." />

            <Section title="Personal information" icon="user">
                <form onSubmit={save} className="space-y-5 p-6">
                    <div className="flex items-center gap-4">
                        <Avatar src={avatarFile ? URL.createObjectURL(avatarFile) : (user?.avatar_url ?? user?.avatar)} name={form.name} size="h-16 w-16" />
                        <div>
                            <input ref={inputRef} type="file" accept="image/*" hidden onChange={(e) => setAvatarFile(e.target.files[0])} />
                            <Button type="button" variant="secondary" size="sm" icon="image" onClick={() => inputRef.current?.click()}>
                                Change photo
                            </Button>
                            {avatarFile ? (
                                <p className="mt-1 text-xs text-slate-500">{avatarFile.name}</p>
                            ) : (
                                <p className="mt-1 text-xs text-slate-400">PNG or JPG, square crops best.</p>
                            )}
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Full name" required error={errors.name?.[0]}>
                            <Input value={form.name} onChange={set('name')} />
                        </Field>
                        <Field label="Email address" required error={errors.email?.[0]}>
                            <Input type="email" value={form.email} onChange={set('email')} />
                        </Field>
                    </div>

                    <Field label="Headline" hint="A short line shown next to your name." error={errors.headline?.[0]}>
                        <Input value={form.headline} onChange={set('headline')} placeholder="Full-stack developer learning Laravel" />
                    </Field>

                    <Field label="Bio" error={errors.bio?.[0]}>
                        <Textarea value={form.bio} onChange={set('bio')} placeholder="Tell others a little about yourself…" />
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button loading={busy} icon="check">
                            Save changes
                        </Button>
                    </div>
                </form>
            </Section>

            {user?.roles?.length ? (
                <Section title="Access level" icon="shield">
                    <p className="px-5 py-4 text-sm text-slate-500">{user.role_labels?.join(', ') ?? user.roles.join(', ')}</p>
                </Section>
            ) : null}
        </div>
    );
}