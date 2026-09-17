import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api';
import { Button, Card, EmptyState, Icon, Input, PageHeader, PageLoader, Paginator, useToast, formatDate } from '../../components/ui';

export default function AdminCertificates() {
    const toast = useToast();

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    const [page, setPage] = useState(1);

    const load = useCallback(() => {
        setLoading(true);
        api.get('/api/admin/certificates', { params: { search: debounced, page } })
            .then(({ data }) => setData(data))
            .catch(() => toast('Could not load certificates.', 'error'))
            .finally(() => setLoading(false));
    }, [debounced, page, toast]);

    useEffect(() => {
        const t = setTimeout(() => setDebounced(search), 350);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        load();
    }, [load]);

    const copy = async (value) => {
        try {
            await navigator.clipboard.writeText(value);
            toast('Copied!', 'success');
        } catch {
            toast('Could not copy.', 'error');
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                icon="award"
                title="Certificates"
                subtitle={`View the ${data?.meta?.total ?? 0} certificates issued across the platform.`}
            />

            <div className="flex items-center justify-end gap-2">
                <Input
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                    placeholder="Search by number, student, or course…"
                    className="w-72"
                />
            </div>

            {loading ? (
                <PageLoader label="Loading certificates…" />
            ) : !data?.certificates?.length ? (
                <EmptyState icon="certificate" title="No certificates found" message="Certificates appear here once a student completes a course." />
            ) : (
                <Card className="divide-y divide-slate-100 p-0">
                    {data.certificates.map((cert) => (
                        <div key={cert.id} className="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center">
                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                    <Icon name="certificate" className="h-5 w-5" />
                                </div>
                                <div className="min-w-0">
                                    <p className="truncate font-mono text-sm font-semibold text-slate-900">{cert.certificate_number}</p>
                                    <p className="truncate text-sm text-slate-500">
                                        {cert.student?.name} · {cert.student?.email}
                                    </p>
                                </div>
                            </div>
                            <div className="flex min-w-0 items-center gap-1 lg:w-56">
                                <span className="truncate text-xs text-slate-400">{cert.identifier}</span>
                                <button
                                    type="button"
                                    onClick={() => copy(cert.identifier)}
                                    aria-label="Copy identifier"
                                    className="shrink-0 rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                >
                                    <Icon name="clipboard" className="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <Link
                                to={`/courses/${cert.course?.slug}`}
                                className="truncate text-sm font-medium text-slate-900 hover:text-brand-600"
                            >
                                {cert.course?.title}
                            </Link>
                            <span className="shrink-0 text-sm text-slate-500">{formatDate(cert.issued_at)}</span>
                            <Button
                                variant="ghost"
                                size="sm"
                                icon="eye"
                                onClick={() => window.open(`/certificates/${cert.identifier}`, '_blank')}
                            >
                                View
                            </Button>
                        </div>
                    ))}
                </Card>
            )}

            <Paginator meta={data?.meta} onPage={setPage} />
        </div>
    );
}