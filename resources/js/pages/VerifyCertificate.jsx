import { useState } from 'react';
import { Button, Field, Icon, Input } from '../components/ui';

export default function VerifyCertificate() {
    const [identifier, setIdentifier] = useState('');
    const [error, setError] = useState('');

    const submit = (e) => {
        e.preventDefault();
        const value = identifier.trim();
        if (!value) {
            setError('Masukkan nomor sertifikat untuk melanjutkan.');
            return;
        }
        setError('');
        window.location.assign(`/certificates/verify/${encodeURIComponent(value)}`);
    };

    return (
        <div className="mx-auto max-w-xl">
            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div className="mb-2 flex items-center gap-2">
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                        <Icon name="badgeCheck" className="h-5 w-5" />
                    </span>
                    <h1 className="text-xl font-bold text-slate-900">Verifikasi Sertifikat</h1>
                </div>
                <p className="text-sm leading-relaxed text-slate-500">
                    Masukkan nomor sertifikat yang tertera pada dokumen untuk memeriksa keaslian dan rincian penerimanya.
                </p>

                <form onSubmit={submit} className="mt-6 space-y-4">
                    <Field label="Nomor sertifikat" required hint="Nomor ini tercantum pada sertifikat yang diterbitkan.">
                        <Input
                            value={identifier}
                            onChange={(e) => setIdentifier(e.target.value)}
                            placeholder="Contoh: LMS-2026-000123"
                            className="font-mono"
                            autoFocus
                        />
                    </Field>
                    {error ? <p className="text-sm font-medium text-red-600">{error}</p> : null}
                    <Button type="submit" icon="badgeCheck" className="w-full">
                        Periksa sertifikat
                    </Button>
                </form>
            </div>

            <div className="mt-6 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
                <div className="flex items-start gap-3">
                    <Icon name="shieldCheck" className="mt-0.5 h-4 w-4 shrink-0 text-brand-500" />
                    <p>
                        Hasil pemeriksaan akan ditampilkan pada halaman resmi platform. Selalu gunakan laman ini untuk
                        memastikan keaslian sertifikat sebelum digunakan.
                    </p>
                </div>
            </div>
        </div>
    );
}