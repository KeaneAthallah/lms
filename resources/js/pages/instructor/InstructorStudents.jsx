import { useCallback, useEffect, useState } from 'react';
import api, { apiError } from '../../api';
import { Avatar, Badge, Card, EmptyState, Input, PageHeader, PageLoader, Paginator, ProgressBar, Select, useToast } from '../../components/ui';

const statusMeta = {
    active: { color: 'blue', label: 'In progress' },
    completed: { color: 'green', label: 'Completed' },
};

export default function InstructorStudents() {
    const slug = location.pathname.split('/')[3];
    const toast = useToast();

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);

    const load = useCallback(() => {
        setLoading(true);
        api.get(`/api/instructor/courses/${slug}/students`, { params: { search: debounced, status, page } })
            .then(({ data }) => setData(data))
            .catch(() => toast('Could not load students.', 'error'))
            .finally(() => setLoading(false));
    }, [slug, debounced, status, page, toast]);

    useEffect(() => {
        const t = setTimeout(() => setDebounced(search), 350);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <div className="space-y-6">
            <PageHeader
                title="Students"
                subtitle={`Manage the ${data?.student_count ?? 0} students enrolled in this course.`}
            />

            <div className="flex items-center justify-end gap-2">
                <Input
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                    placeholder="Search by name or email…"
                    className="w-64"
                />
                <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="w-40">
                    <option value="">All statuses</option>
                    <option value="active">In progress</option>
                    <option value="completed">Completed</option>
                </Select>
            </div>

            {loading ? (
                <PageLoader label="Loading students…" />
            ) : !data?.enrollments?.length ? (
                <EmptyState icon="users" title="No students found" message="Students appear here once they enroll in the course." />
            ) : (
                <Card className="divide-y divide-slate-100 p-0">
                    {data.enrollments.map((enrollment) => (
                        <div key={enrollment.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                <Avatar name={enrollment.student.name} src={enrollment.student.avatar} />
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-slate-900">{enrollment.student.name}</p>
                                    <p className="truncate text-sm text-slate-500">{enrollment.student.email}</p>
                                </div>
                            </div>
                            <div className="flex items-center gap-4 sm:w-72">
                                <div className="flex-1">
                                    <div className="mb-1 flex justify-between text-xs text-slate-500">
                                        <span>Progress</span>
                                        <span className="font-medium">{enrollment.progress_percent}%</span>
                                    </div>
                                    <ProgressBar value={enrollment.progress_percent} className="h-1.5" />
                                </div>
                                <Badge color={statusMeta[enrollment.status]?.color ?? 'slate'} dot>
                                    {statusMeta[enrollment.status]?.label ?? enrollment.status}
                                </Badge>
                            </div>
                        </div>
                    ))}
                </Card>
            )}

            <Paginator meta={data?.meta} onPage={setPage} />
        </div>
    );
}