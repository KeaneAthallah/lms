import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { apiError } from '../../api';
import { Button, Card, ConfirmDialog, EmptyState, Icon, Input, PageHeader, PageLoader, Paginator, ProgressBar, Select, StatusBadge, formatDate, useToast } from '../../components/ui';

export default function AdminEnrollments() {
    const toast = useToast();

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);

    const [confirmDelete, setConfirmDelete] = useState(null);
    const [deleting, setDeleting] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        api.get('/api/admin/enrollments', { params: { search: debounced, status, page } })
            .then(({ data }) => setData(data))
            .catch(() => toast('Could not load enrollments.', 'error'))
            .finally(() => setLoading(false));
    }, [debounced, status, page, toast]);

    useEffect(() => {
        const t = setTimeout(() => setDebounced(search), 350);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        load();
    }, [load]);

    const handleDelete = async () => {
        if (!confirmDelete) return;
        setDeleting(true);
        try {
            await api.delete(`/api/admin/enrollments/${confirmDelete.id}`);
            toast('Enrollment removed.', 'success');
            setConfirmDelete(null);
            load();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                title="Enrollments"
                subtitle={`Manage the ${data?.meta?.total ?? 0} enrollments across all courses.`}
            />

            <div className="flex items-center justify-end gap-2">
                <Input
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                    placeholder="Search by student or course…"
                    className="w-64"
                />
                <Select
                    value={status}
                    onChange={(e) => { setStatus(e.target.value); setPage(1); }}
                    className="w-40"
                >
                    <option value="">All statuses</option>
                    <option value="active">In progress</option>
                    <option value="completed">Completed</option>
                </Select>
            </div>

            {loading ? (
                <PageLoader label="Loading enrollments…" />
            ) : !data?.enrollments?.length ? (
                <EmptyState icon="book" title="No enrollments found" message="Try adjusting your search or filters." />
            ) : (
                <Card className="divide-y divide-slate-100 p-0">
                    {data.enrollments.map((enrollment) => (
                        <div key={enrollment.id} className="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center">
                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium text-slate-900">{enrollment.student.name}</p>
                                    <p className="truncate text-sm text-slate-500">{enrollment.student.email}</p>
                                </div>
                                <div className="hidden min-w-0 max-w-52 sm:block">
                                    <Link
                                        to={`/courses/${enrollment.course.slug}`}
                                        className="truncate text-sm font-medium text-brand-600 hover:text-brand-700 hover:underline"
                                    >
                                        {enrollment.course.title}
                                    </Link>
                                </div>
                            </div>
                            <div className="flex items-center gap-4 sm:w-64">
                                <div className="flex-1">
                                    <div className="mb-1 flex justify-between text-xs text-slate-500">
                                        <span>Progress</span>
                                        <span className="font-medium">{enrollment.progress_percent}%</span>
                                    </div>
                                    <ProgressBar value={enrollment.progress_percent} />
                                </div>
                            </div>
                            <StatusBadge status={enrollment.status} />
                            <span className="text-xs text-slate-500">{formatDate(enrollment.created_at)}</span>
                            <Button
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                className="text-red-500 hover:bg-red-50 hover:text-red-600"
                                onClick={() => setConfirmDelete(enrollment)}
                            >
                                Remove
                            </Button>
                        </div>
                    ))}
                </Card>
            )}

            <Paginator meta={data?.meta} onPage={setPage} />

            <ConfirmDialog
                open={!!confirmDelete}
                onClose={() => setConfirmDelete(null)}
                title="Remove enrollment"
                message={`Are you sure you want to remove ${confirmDelete?.student?.name} from ${confirmDelete?.course?.title}? Completed enrollments cannot be removed.`}
                confirmLabel="Remove enrollment"
                icon="trash"
                tone="danger"
                loading={deleting}
                onConfirm={handleDelete}
            />
        </div>
    );
}