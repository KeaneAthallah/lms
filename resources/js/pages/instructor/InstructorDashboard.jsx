import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api';
import { useAuth } from '../../auth';
import { Avatar, Badge, ButtonLink, Card, DataTable, EmptyState, Icon, PageHeader, PageLoader, ProgressBar, Section, StatCard, StatusBadge, timeAgo } from '../../components/ui';

export default function InstructorDashboard() {
    const { user } = useAuth();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/api/instructor/dashboard')
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <PageLoader label="Loading your studio…" />;

    if (!data) {
        return <EmptyState icon="alert" title="Dashboard unavailable" message="Please try again later." />;
    }

    const quizColumns = [
        {
            key: 'student',
            header: 'Student',
            render: (q) => (
                <div className="flex items-center gap-2">
                    <Avatar src={q.avatar} name={q.student} size="h-7 w-7" />
                    <span className="font-medium text-slate-900">{q.student}</span>
                </div>
            ),
        },
        { key: 'quiz', header: 'Quiz', render: (q) => <span className="text-slate-600">{q.quiz}</span> },
        {
            key: 'score',
            header: 'Score',
            align: 'right',
            thClassName: 'hidden sm:table-cell',
            tdClassName: 'hidden sm:table-cell',
            render: (q) => (
                <span className="flex items-center justify-end gap-2">
                    <span className="font-semibold text-slate-900">{q.score_percentage}%</span>
                    <Badge color={q.passed ? 'green' : 'red'} dot>
                        {q.passed ? 'Passed' : 'Failed'}
                    </Badge>
                </span>
            ),
        },
        {
            key: 'when',
            header: 'When',
            align: 'right',
            thClassName: 'hidden md:table-cell',
            tdClassName: 'hidden md:table-cell',
            render: (q) => <span className="text-slate-500">{timeAgo(q.submitted_at)}</span>,
        },
    ];

    return (
        <div className="space-y-8">
            <PageHeader
                icon="home"
                title="Instructor studio"
                subtitle={`Welcome back, ${user?.name?.split(' ')[0]}. Here's what's happening.`}
                actions={
                    <ButtonLink to="/instructor/courses" variant="primary" icon="plus">
                        New course
                    </ButtonLink>
                }
            />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Courses" value={data.stats.total_courses} icon="book" tone="brand" hint={`${data.stats.published_courses} published`} />
                <StatCard label="Students" value={data.stats.total_students} icon="users" tone="amber" hint={`${data.stats.total_enrollments} enrollments`} />
                <StatCard label="Completed" value={data.stats.completed_enrollments} icon="checkCircle" tone="green" />
                <StatCard label="Pending grading" value={data.stats.pending_assignments} icon="clipboard" tone={data.stats.pending_assignments > 0 ? 'red' : 'slate'} />
            </div>

            {data.pending_submissions?.length ? (
                <Section title="Awaiting your review" icon="clipboard">
                    <div className="grid gap-3 p-4 sm:grid-cols-2">
                        {data.pending_submissions.map((s) => (
                            <Link
                                key={s.id}
                                to={`/instructor/courses/${s.course.slug}/submissions`}
                                className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-card transition hover:border-amber-300 hover:bg-amber-50/40"
                            >
                                <Avatar src={s.student.avatar} name={s.student.name} size="h-10 w-10" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold text-slate-900">{s.student.name}</p>
                                    <p className="truncate text-xs text-slate-500">
                                        submitted <span className="font-medium text-slate-700">{s.assignment.title}</span>
                                    </p>
                                    <p className="text-[11px] text-slate-400">{s.course.title}</p>
                                </div>
                                <Badge color="red" dot className="shrink-0">
                                    Review
                                </Badge>
                            </Link>
                        ))}
                    </div>
                </Section>
            ) : null}

            {data.recent_quiz_activity?.length ? (
                <Section title="Recent quiz activity" icon="puzzle">
                    <DataTable rows={data.recent_quiz_activity} rowKey={(q) => q.id} columns={quizColumns} />
                </Section>
            ) : null}

            {data.recent_enrollments?.length ? (
                <Section title="Recent enrollments" icon="users">
                    <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                        {data.recent_enrollments.map((e) => (
                            <div key={`${e.student.id}-${e.course.id}`} className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-card">
                                <Avatar src={e.student.avatar} name={e.student.name} size="h-9 w-9" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold text-slate-900">{e.student.name}</p>
                                    <p className="truncate text-xs text-slate-500">joined {e.course.title}</p>
                                    <p className="text-[11px] text-slate-400">{timeAgo(e.created_at)}</p>
                                </div>
                                <span className="shrink-0 text-xs font-semibold text-brand-600">{e.progress_percent}%</span>
                            </div>
                        ))}
                    </div>
                </Section>
            ) : null}

            {data.course_stats?.length ? (
                <Section
                    title="Course performance"
                    icon="chart"
                    actions={
                        <Link to="/instructor/courses" className="text-sm font-medium text-brand-600 hover:text-brand-700">
                            Manage courses →
                        </Link>
                    }
                >
                    <div className="grid gap-4 p-4 lg:grid-cols-2">
                        {data.course_stats.map((c) => (
                            <Card key={c.id} className="p-4">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link to={`/instructor/courses/${c.slug}`} className="line-clamp-1 font-semibold text-slate-900 hover:text-brand-600">
                                            {c.title}
                                        </Link>
                                        <p className="text-xs text-slate-500">{c.enrollments_count} students · {c.completed_count} completed</p>
                                    </div>
                                    <StatusBadge status={c.status} />
                                </div>
                                <div className="mt-3">
                                    <ProgressBar value={c.average_progress} />
                                    <p className="mt-1 text-right text-xs font-semibold text-slate-500">avg {c.average_progress}%</p>
                                </div>
                            </Card>
                        ))}
                    </div>
                </Section>
            ) : null}
        </div>
    );
}