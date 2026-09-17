import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../auth';
import { Badge, ButtonLink, DataTable, EmptyState, formatDate, Icon, PageHeader, PageLoader, ProgressBar, Section, StatCard } from '../components/ui';

export default function StudentDashboard() {
    const { user } = useAuth();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/api/dashboard')
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <PageLoader label="Loading your dashboard…" />;

    if (!data) {
        return <EmptyState icon="alert" title="Dashboard unavailable" message="Please try again later." />;
    }

    const assignmentColumns = [
        {
            key: 'assignment',
            header: 'Assignment',
            render: (a) => (
                <Link to={`/assignment/${a.id}`} className="font-medium text-brand-600 hover:text-brand-700">
                    {a.title}
                </Link>
            ),
        },
        {
            key: 'course',
            header: 'Course',
            thClassName: 'hidden sm:table-cell',
            tdClassName: 'hidden sm:table-cell',
            render: (a) => (
                <Link to={`/courses/${a.course.slug}`} className="text-slate-500 hover:text-brand-600">
                    {a.course.title}
                </Link>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            thClassName: 'hidden md:table-cell',
            tdClassName: 'hidden md:table-cell',
            render: (a) => (a.has_submission ? <Badge color="green" dot>Submitted</Badge> : <Badge color="slate" dot>Pending</Badge>),
        },
        {
            key: 'due',
            header: 'Due',
            align: 'right',
            render: (a) => <span className="font-medium text-slate-700">{formatDate(a.due_at) || 'Open'}</span>,
        },
    ];

    return (
        <div className="space-y-8">
            <PageHeader
                icon="home"
                title={`Welcome back, ${user?.name?.split(' ')[0] ?? 'Student'}`}
                subtitle="Here's an overview of your learning progress."
            />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Enrolled" value={data.stats.enrolled_courses} icon="book" tone="brand" />
                <StatCard label="In progress" value={data.stats.in_progress} icon="play" tone="amber" />
                <StatCard label="Completed" value={data.stats.completed} icon="checkCircle" tone="green" />
                <StatCard label="Certificates" value={data.stats.certificates} icon="award" tone="violet" />
            </div>

            {data.continue_learning?.length ? (
                <Section title="Continue learning" icon="play">
                    <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                        {data.continue_learning.map(({ course, progress_percent }) => (
                            <Link
                                key={course.id}
                                to={`/learn/${course.slug}`}
                                className="group flex items-start gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-card transition hover:-translate-y-0.5 hover:shadow-lift"
                            >
                                <div className="h-14 w-14 shrink-0 overflow-hidden rounded-lg bg-slate-200">
                                    {course.thumbnail_url ? (
                                        <img src={course.thumbnail_url} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                        <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-500 to-brand-700 text-white">
                                            <Icon name="book" className="h-5 w-5" />
                                        </div>
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <h3 className="line-clamp-1 font-semibold text-slate-900 group-hover:text-brand-700">{course.title}</h3>
                                    <p className="text-xs text-slate-500">{course.instructor?.name}</p>
                                    <ProgressBar value={progress_percent} className="mt-2" />
                                    <p className="mt-1 text-right text-[11px] font-semibold text-slate-500">{progress_percent}%</p>
                                </div>
                            </Link>
                        ))}
                    </div>
                </Section>
            ) : null}

            {data.upcoming_assignments?.length ? (
                <Section title="Upcoming assignments" icon="clipboard">
                    <DataTable rows={data.upcoming_assignments} rowKey={(a) => a.id} columns={assignmentColumns} />
                </Section>
            ) : null}

            {data.recent_activity?.length ? (
                <Section title="Recent activity" icon="bell">
                    <ul className="divide-y divide-slate-100">
                        {data.recent_activity.map((item) => (
                            <li key={item.id} className="flex items-start gap-3 px-5 py-3">
                                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                                    <Icon name={item.type?.includes('enroll') ? 'book' : item.type?.includes('grade') ? 'checkCircle' : 'bell'} className="h-4 w-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-slate-900">{item.title}</p>
                                    <p className="line-clamp-1 text-xs text-slate-500">{item.message}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Section>
            ) : null}

            {!data.continue_learning?.length && !data.upcoming_assignments?.length && !data.recent_activity?.length ? (
                <EmptyState
                    icon="book"
                    title="Your dashboard is empty"
                    message="Enroll in a course to see your progress here."
                    action={<ButtonLink to="/browse" icon="search">Browse courses</ButtonLink>}
                />
            ) : null}
        </div>
    );
}