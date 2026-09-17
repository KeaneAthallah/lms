import { useEffect, useState } from 'react';
import api from '../api';
import { Badge, Card, EmptyState, formatDate, Icon, PageHeader, PageLoader } from '../components/ui';

export default function Certificates() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/api/certificates')
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <PageLoader label="Loading your certificates…" />;

    const certificates = data?.data ?? [];

    return (
        <div className="space-y-6">
            <PageHeader
                icon="award"
                title="Certificates"
                subtitle="Complete a course to earn a shareable, verifiable certificate."
            />

            {certificates.length === 0 ? (
                <EmptyState
                    icon="award"
                    title="No certificates yet"
                    message="Finish a course with a passing score on every quiz to unlock your certificate."
                />
            ) : (
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {certificates.map((cert) => {
                        const printedUrl = `/certificates/${cert.identifier}`;
                        return (
                            <Card key={cert.id} className="relative overflow-hidden">
                                <div className="h-24 bg-gradient-to-br from-brand-700 to-brand-900" />
                                <div className="p-5">
                                    <div className="-mt-12 mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-white text-brand-600 shadow-md">
                                        <Icon name="award" className="h-6 w-6" />
                                    </div>
                                    <h3 className="font-bold text-slate-900">{cert.course?.title ?? 'Course'}</h3>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        Awarded to {cert.student?.name ?? 'you'} · {formatDate(cert.issued_at)}
                                    </p>
                                    <p className="mt-2 inline-block rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-500">
                                        {cert.certificate_number}
                                    </p>
                                    <div className="mt-4 flex items-center gap-2">
                                        <a
                                            href={printedUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 transition hover:bg-slate-50"
                                        >
                                            <Icon name="eye" className="h-4 w-4" />
                                            View
                                        </a>
                                        <a
                                            href={cert.verify_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 dark:text-gray-50"
                                        >
                                            <Icon name="checkCircle" className="h-4 w-4" />
                                            Verify
                                        </a>
                                    </div>
                                    <Badge color="green" dot className="absolute right-4 top-3 bg-white/90 backdrop-blur">
                                        Verified
                                    </Badge>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}
        </div>
    );
}