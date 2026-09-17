import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../auth';
import CourseCard from '../components/CourseCard';
import { ButtonLink, Card, EmptyState, Icon, Input, PageHeader, PageLoader, Paginator, Select } from '../components/ui';

export default function Browse() {
    const [params, setParams] = useSearchParams();
    const { user } = useAuth();

    const search = params.get('search') ?? '';
    const category = params.get('category') ?? '';
    const level = params.get('level') ?? '';
    const sort = params.get('sort') ?? 'newest';
    const page = Number(params.get('page') ?? 1);

    const [categories, setCategories] = useState([]);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/api/courses/categories')
            .then(({ data }) => setCategories(data ?? []))
            .catch(() => {});
    }, []);

    useEffect(() => {
        setLoading(true);
        const query = new URLSearchParams();
        if (search) query.set('search', search);
        if (category) query.set('category', category);
        if (level) query.set('level', level);
        if (sort) query.set('sort', sort);
        if (page && page > 1) query.set('page', page);
        api.get(`/api/courses?${query.toString()}`)
            .then(({ data: res }) => setData(res))
            .finally(() => setLoading(false));
    }, [search, category, level, sort, page]);

    const update = (patch) => {
        const next = new URLSearchParams(params);
        Object.entries(patch).forEach(([key, value]) => {
            if (value) next.set(key, value);
            else next.delete(key);
        });
        if (!('page' in patch)) next.delete('page');
        setParams(next, { replace: true });
    };

    const meta = data?.meta;
    const enrolled = useMemo(() => new Set((user?.courses ?? []).map((c) => c.id)), [user]);
    const hasFilters = Boolean(search || category || level);

    return (
        <div className="space-y-6">
            <PageHeader icon="book" title="Browse courses" subtitle={`${meta?.total ?? 0} courses to choose from.`} />

            <Card className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
                <div className="relative lg:col-span-2">
                    <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                        <Icon name="search" className="h-4 w-4" />
                    </span>
                    <Input
                        type="search"
                        placeholder="Search courses…"
                        defaultValue={search}
                        onKeyDown={(e) => e.key === 'Enter' && update({ search: e.target.value.trim() })}
                        className="bg-slate-50 pl-9 focus:bg-white"
                    />
                </div>
                <Select value={category} onChange={(e) => update({ category: e.target.value })}>
                    <option value="">All categories</option>
                    {categories.map((c) => (
                        <option key={c.id} value={c.slug}>
                            {c.name}
                        </option>
                    ))}
                </Select>
                <Select value={level} onChange={(e) => update({ level: e.target.value })}>
                    <option value="">All levels</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                </Select>
                <Select value={sort} onChange={(e) => update({ sort: e.target.value })}>
                    <option value="newest">Newest</option>
                    <option value="popular">Most popular</option>
                    <option value="title">Title A–Z</option>
                </Select>
            </Card>

            {loading ? (
                <PageLoader label="Loading courses…" />
            ) : (data?.data ?? []).length === 0 ? (
                <EmptyState
                    icon="search"
                    title="No courses match your filters"
                    message="Try adjusting your search or clearing the filters."
                    action={
                        hasFilters ? (
                            <ButtonLink to="/browse" variant="secondary" icon="x">
                                Clear all filters
                            </ButtonLink>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {(data?.data ?? []).map((course) => (
                            <CourseCard key={course.id} course={{ ...course, progress_percent: enrolled.has(course.id) ? course.progress_percent : undefined }} />
                        ))}
                    </div>
                    <Paginator meta={meta} onPage={(p) => update({ page: p })} />
                </>
            )}
        </div>
    );
}