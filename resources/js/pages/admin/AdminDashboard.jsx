import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api';
import { Avatar, Badge, EmptyState, Icon, PageHeader, PageLoader, Section, StatCard, StatusBadge, timeAgo, useToast } from '../../components/ui';

export default function AdminDashboard() {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    const fetchData = useCallback(() => {
        setLoading(true);
        api.get('/api/admin/dashboard')
            .then(({ data }) => setData(data))
            .catch(() => toast('Failed to load dashboard data.', 'error'))
            .finally(() => setLoading(false));
    }, [toast]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    if (loading) {
        return <PageLoader label="Loading overview…" />;
    }

    if (!data) {
        return <EmptyState icon="alert" title="Dashboard unavailable" message="Please try again later." />;
    }

    return (
        <div className="space-y-8">
            <PageHeader title="Admin overview" subtitle="Platform-wide stats and recent activity." />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Users" value={data.stats.total_users} icon="users" tone="brand" hint={`${data.stats.students} students · ${data.stats.instructors} instructors`} />
                <StatCard label="Courses" value={data.stats.total_courses} icon="book" tone="amber" hint={`${data.stats.published_courses} published · ${data.stats.draft_courses} drafts`} />
                <StatCard label="Enrollments" value={data.stats.total_enrollments} icon="checkCircle" tone="green" hint={`${data.stats.completed_enrollments} completed`} />
                <StatCard label="Certificates" value={data.stats.total_certificates} icon="certificate" tone="violet" />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Section title="Top courses" icon="trophy" className="lg:col-span-2">
                    {data.top_courses?.length ? (
                        <ul className="divide-y divide-slate-100">
                            {data.top_courses.map((course) => (
                                <li key={course.id}>
                                    <Link to={`/courses/${course.slug}`} className="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-slate-900 hover:text-brand-600">{course.title}</p>
                                            <p className="mt-0.5 text-xs text-slate-500">{course.lessons_count} lessons</p>
                                        </div>
                                        <Badge color="blue" dot>{course.enrollments_count} enrollments</Badge>
                                        <StatusBadge status={course.status} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="px-5 py-8">
                            <EmptyState icon="book" title="No courses yet" message="Courses will appear here once created." />
                        </div>
                    )}
                </Section>

                <Section title="Recent enrollments" icon="users">
                    {data.recent_enrollments?.length ? (
                        <ul className="divide-y divide-slate-100">
                            {data.recent_enrollments.map((e) => (
                                <li key={e.id} className="flex items-center gap-3 px-5 py-3">
                                    <Avatar src={e.student.avatar} name={e.student.name} size="h-9 w-9" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold text-slate-900">{e.student.name}</p>
                                        <p className="truncate text-xs text-slate-500">
                                            joined <Link to={`/courses/${e.course.slug}`} className="font-medium text-brand-600 hover:text-brand-700">{e.course.title}</Link>
                                        </p>
                                    </div>
                                    <span className="shrink-0 text-xs text-slate-400">{timeAgo(e.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="px-5 py-8">
                            <EmptyState icon="users" title="No enrollments yet" message="Recent enrollments will show up here." />
                        </div>
                    )}
                </Section>

                <Section title="Recent users" icon="user" className="lg:col-span-2">
                    {data.recent_users?.length ? (
                        <ul className="divide-y divide-slate-100">
                            {data.recent_users.map((user) => (
                                <li key={user.id} className="flex items-center gap-3 px-5 py-3">
                                    <Avatar src={user.avatar} name={user.name} size="h-9 w-9" />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <p className="truncate text-sm font-semibold text-slate-900">{user.name}</p>
                                            <span className={`h-2 w-2 shrink-0 rounded-full ${user.is_active ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                                        </div>
                                        <p className="truncate text-xs text-slate-500">{user.email}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {user.roles?.map((role) => (
                                            <Badge key={role} color="violet">{role}</Badge>
                                        ))}
                                    </div>
                                    <span className="shrink-0 text-xs text-slate-400">{timeAgo(user.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="px-5 py-8">
                            <EmptyState icon="user" title="No users yet" message="New users will appear here." />
                        </div>
                    )}
                </Section>
            </div>
        </div>
    );
}
