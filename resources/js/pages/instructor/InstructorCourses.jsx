import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { apiError } from '../../api';
import { Button, EmptyState, Field, Icon, Input, Modal, PageHeader, PageLoader, Paginator, Select, StatusBadge, Textarea, useToast } from '../../components/ui';

export default function InstructorCourses() {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');

    const [createOpen, setCreateOpen] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setLoading(true);
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (page > 1) params.set('page', page);
        api.get(`/api/instructor/courses?${params.toString()}`)
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [page, search, status]);

    const create = async (form) => {
        setSaving(true);
        try {
            const { data } = await api.post('/api/instructor/courses', form);
            toast('Course created.', 'success');
            setCreateOpen(false);
            setData(null);
            window.location.assign(`/instructor/courses/${data.data.slug}`);
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                title="My courses"
                subtitle="Build your curriculum, quizzes and assignments from one place."
                actions={
                    <Button icon="plus" onClick={() => setCreateOpen(true)}>
                        New course
                    </Button>
                }
            />

            <div className="flex flex-wrap gap-3">
                <Input
                    placeholder="Search courses…"
                    defaultValue={search}
                    onChange={(e) => {
                        setPage(1);
                        clearTimeout(window.__courseSearch);
                        window.__courseSearch = setTimeout(() => setSearch(e.target.value), 300);
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
                    title="No courses yet"
                    message="Create your first course and start sharing your knowledge."
                    action={
                        <Button icon="plus" onClick={() => setCreateOpen(true)}>
                            New course
                        </Button>
                    }
                />
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {(data?.data ?? []).map((course) => (
                            <Link key={course.id} to={`/instructor/courses/${course.slug}`} className="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lift">
                                <div className="relative aspect-video bg-slate-200">
                                    {course.thumbnail_url ? (
                                        <img src={course.thumbnail_url} alt={course.title} className="h-full w-full object-cover" loading="lazy" />
                                    ) : (
                                        <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-600 to-brand-900 text-white dark:text-gray-50">
                                            <Icon name="book" className="h-8 w-8" />
                                        </div>
                                    )}
                                    <span className="absolute right-3 top-3">
                                        <StatusBadge status={course.status} className="bg-white/90 backdrop-blur" />
                                    </span>
                                </div>
                                <div className="p-4">
                                    <h3 className="line-clamp-1 font-semibold text-slate-900 group-hover:text-brand-600">{course.title}</h3>
                                    <p className="mt-1 flex items-center gap-3 text-xs text-slate-500">
                                        <span className="flex items-center gap-1"><Icon name="play" className="h-3 w-3" />{course.lessons_count ?? 0} lessons</span>
                                        <span className="flex items-center gap-1"><Icon name="users" className="h-3 w-3" />{course.enrollments_count ?? 0} students</span>
                                    </p>
                                    <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-sm">
                                        <span className="font-semibold text-brand-600">Edit course</span>
                                        <Icon name="arrowRight" className="h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-500" />
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                    <Paginator meta={data?.meta} onPage={setPage} />
                </>
            )}

            <CreateCourseModal open={createOpen} onClose={() => setCreateOpen(false)} saving={saving} onSubmit={create} />
        </div>
    );
}

function CreateCourseModal({ open, onClose, saving, onSubmit }) {
    const [categories, setCategories] = useState([]);
    const [form, setForm] = useState({ title: '', short_description: '', category_id: '', level: 'beginner', description: '' });

    useEffect(() => {
        api.get('/api/courses/categories')
            .then(({ data }) => setCategories(data ?? []))
            .catch(() => {});
    }, [open]);

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Create a new course"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button loading={saving} icon="plus" onClick={() => onSubmit(form)}>
                        Create course
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Course title" required>
                    <Input value={form.title} onChange={set('title')} placeholder="e.g. Modern Web Development with Laravel" />
                </Field>
                <Field label="Short description" required>
                    <Textarea value={form.short_description} onChange={set('short_description')} className="min-h-20" placeholder="One or two sentences shown on course cards." />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Category">
                        <Select value={form.category_id} onChange={set('category_id')}>
                            <option value="">General</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Level">
                        <Select value={form.level} onChange={set('level')}>
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                        </Select>
                    </Field>
                </div>
            </div>
        </Modal>
    );
}