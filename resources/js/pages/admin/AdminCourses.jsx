import { useCallback, useEffect, useState } from 'react';
import api, { apiError } from '../../api';
import CourseCard from '../../components/CourseCard';
import { Button, ConfirmDialog, EmptyState, Icon, Input, PageHeader, PageLoader, Paginator, Select, useToast } from '../../components/ui';

export default function AdminCourses() {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');

    const [deleteCourse, setDeleteCourse] = useState(null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (page > 1) params.set('page', page);
        api.get(`/api/admin/courses?${params.toString()}`)
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [search, status, page]);

    useEffect(() => {
        load();
    }, [load]);

    const destroy = async () => {
        setDeleting(true);
        try {
            await api.delete(`/api/admin/courses/${deleteCourse.slug}`);
            toast('Course deleted.', 'success');
            setDeleteOpen(false);
            setDeleteCourse(null);
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
                title="Courses"
                subtitle="Manage all courses across the platform."
            />

            <div className="flex flex-wrap gap-3">
                <Input
                    placeholder="Search courses…"
                    defaultValue={search}
                    onChange={(e) => {
                        setPage(1);
                        clearTimeout(window.__adminCourseSearch);
                        window.__adminCourseSearch = setTimeout(() => setSearch(e.target.value), 300);
                    }}
                    className="max-w-sm"
                />
                <Select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }} className="w-44">
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="archived">Archived</option>
                </Select>
            </div>

            {loading ? (
                <PageLoader label="Loading courses…" />
            ) : (data?.data ?? []).length === 0 ? (
                <EmptyState
                    icon="book"
                    title="No courses found"
                    message="Try adjusting your search or filters."
                />
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {(data?.data ?? []).map((course) => (
                            <div key={course.id} className="group relative">
                                <CourseCard course={course} />
                                <button
                                    type="button"
                                    onClick={() => { setDeleteCourse(course); setDeleteOpen(true); }}
                                    aria-label={`Delete ${course.title}`}
                                    className="absolute right-2 top-2 z-10 flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-slate-500 opacity-0 shadow-sm ring-1 ring-slate-200 backdrop-blur transition hover:bg-red-50 hover:text-red-600 focus:opacity-100 group-hover:opacity-100"
                                >
                                    <Icon name="trash" className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        ))}
                    </div>
                    <Paginator meta={data?.meta} onPage={setPage} />
                </>
            )}

            <ConfirmDialog
                open={deleteOpen}
                onClose={() => { setDeleteOpen(false); setDeleteCourse(null); }}
                title="Delete course"
                message={`Are you sure you want to delete ${deleteCourse?.title}? This action cannot be undone.`}
                confirmLabel="Delete course"
                icon="trash"
                tone="danger"
                loading={deleting}
                onConfirm={destroy}
            />
        </div>
    );
}