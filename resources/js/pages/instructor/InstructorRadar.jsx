import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../api';
import { Alert, Avatar, Badge, EmptyState, Icon, PageHeader, PageLoader, ProgressBar, Section, StatCard, timeAgo } from '../../components/ui';

const flagMeta = {
    repeated_assessment_difficulty: { color: 'red', label: 'Repeated assessment difficulty', icon: 'alert' },
    needs_attention: { color: 'red', label: 'Needs attention', icon: 'target' },
    assignment_overdue: { color: 'amber', label: 'Assignment overdue', icon: 'clock' },
    low_recent_activity: { color: 'slate', label: 'Low recent activity', icon: 'compass' },
    strong_progress: { color: 'green', label: 'Strong progress', icon: 'trendingUp' },
};

function StudentRow({ row }) {
    const { student, flags = [], stats = {} } = row;

    return (
        <li className="flex flex-col gap-4 px-5 py-4">
            <div className="flex flex-wrap items-center gap-3">
                <Avatar src={null} name={student.name} size="h-10 w-10" />
                <div className="min-w-0 flex-1">
                    <h3 className="truncate font-semibold text-slate-900">{student.name}</h3>
                    <p className="truncate text-xs text-slate-500">{student.email}</p>
                </div>
                <div className="shrink-0 text-right">
                    <p className="flex items-center justify-end gap-1 text-sm font-bold text-slate-700">
                        <Icon name="trendingUp" className="h-4 w-4" />
                        {row.progress_percent}%
                    </p>
                    <p className="text-xs text-slate-400">
                        {row.last_accessed_at ? <>Last active {timeAgo(row.last_accessed_at)}</> : 'No activity yet'}
                    </p>
                </div>
            </div>

            <div className="flex items-center gap-3">
                <ProgressBar value={row.progress_percent} color="bg-brand-600" className="max-w-xs" />
                <span className="text-xs font-medium text-slate-500">
                    {stats.completed_lessons}/{stats.total_lessons} lessons
                </span>
                {stats.best_quiz_percentage !== null ? (
                    <span className="text-xs font-medium text-slate-500">Best quiz {stats.best_quiz_percentage}%</span>
                ) : null}
            </div>

            {flags.length ? (
                <div className="flex flex-wrap gap-2">
                    {flags.map((flag) => {
                        const meta = flagMeta[flag.kind] ?? { color: 'slate', label: flag.label, icon: 'info' };

                        return (
                            <span
                                key={flag.kind}
                                className="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200"
                                title={flag.evidence}
                            >
                                <Icon name={meta.icon} className="h-3.5 w-3.5 text-slate-400" />
                                {flag.label}
                            </span>
                        );
                    })}
                </div>
            ) : (
                <p className="text-xs text-slate-400">No flags — no concerning or exceptional signals right now.</p>
            )}
        </li>
    );
}

export default function InstructorRadar() {
    const { slug } = useParams();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        api.get(`/api/instructor/courses/${slug}/radar`)
            .then(({ data }) => setData(data.data))
            .catch(() => setFailed(true))
            .finally(() => setLoading(false));
    }, [slug]);

    if (loading) return <PageLoader label="Scanning the learning radar…" />;

    if (failed || !data) {
        return (
            <EmptyState
                icon="trendingUp"
                title="Radar unavailable"
                message="This course's learning radar could not be loaded. It's available to the course instructor and admins only."
            />
        );
    }

    const { students = [], course = {} } = data;
    const needsAttention = students.filter((row) => row.flags.some((flag) => flag.kind === 'needs_attention' || flag.kind === 'repeated_assessment_difficulty')).length;
    const overdue = students.filter((row) => row.flags.some((flag) => flag.kind === 'assignment_overdue')).length;
    const lowActivity = students.filter((row) => row.flags.some((flag) => flag.kind === 'low_recent_activity')).length;
    const strongProgress = students.filter((row) => row.flags.some((flag) => flag.kind === 'strong_progress')).length;

    return (
        <div className="space-y-8">
            <PageHeader
                icon="trendingUp"
                title="Learning radar"
                subtitle={`A neutral, evidence-based view of how enrolled students are engaging with “${course.title}”.`}
            />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Enrolled students" value={students.length} icon="users" tone="brand" />
                <StatCard label="Need attention" value={needsAttention} icon="alert" tone={needsAttention ? 'red' : 'slate'} />
                <StatCard label="Assignments overdue" value={overdue} icon="clock" tone={overdue ? 'amber' : 'slate'} />
                <StatCard label="Strong progress" value={strongProgress} icon="trendingUp" tone={strongProgress ? 'green' : 'slate'} />
            </div>

            {students.length ? (
                <Section
                    title="Student signals"
                    icon="users"
                    actions={<Badge color="blue" dot>{students.length} students</Badge>}
                    bodyClassName="p-0"
                >
                    <ul className="divide-y divide-slate-100">
                        {students.map((row) => (
                            <StudentRow key={row.student.id} row={row} />
                        ))}
                    </ul>
                </Section>
            ) : (
                <EmptyState icon="users" title="No enrolled students yet" message="Once students enroll, their learning signals will appear here." />
            )}

            {lowActivity > 0 ? (
                <Alert tone="info" icon="info" title="What the radar shows">
                    These signals are computed from recorded course activity only — quiz results relative to passing scores, graded
                    assignment results, lesson completion, and the course's due dates. They describe observable behavior and never
                    infer motivation, aptitude, or personality.
                </Alert>
            ) : null}
        </div>
    );
}