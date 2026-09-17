import { useCallback, useEffect, useState } from 'react';
import api from '../../api';
import { Card, Icon, PageHeader, PageLoader, Section, useToast } from '../../components/ui';

function StatCard({ label, value, sub, icon, color }) {
    return (
        <Card className="p-5">
            <div className="flex items-center gap-4">
                <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${color}`}>
                    <Icon name={icon} className="h-6 w-6" />
                </div>
                <div className="min-w-0">
                    <p className="text-2xl font-bold text-slate-900">{value}</p>
                    <p className="truncate text-sm text-slate-500">{label}</p>
                    {sub ? <p className="text-xs text-slate-400">{sub}</p> : null}
                </div>
            </div>
        </Card>
    );
}

function Bar({ label, value, color }) {
    return (
        <div>
            <div className="mb-1 flex items-center justify-between text-sm">
                <span className="font-medium text-slate-700">{label}</span>
                <span className="text-slate-500">{value} students</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${color}`} style={{ width: `${Math.min(value * 10, 100)}%` }} />
            </div>
        </div>
    );
}

export default function InstructorAnalytics() {
    const slug = location.pathname.split('/')[3];
    const toast = useToast();
    const [data, setData] = useState(null);

    const load = useCallback(() => {
        api.get(`/api/instructor/courses/${slug}/analytics`)
            .then(({ data }) => setData(data))
            .catch(() => toast('Could not load analytics.', 'error'));
    }, [slug, toast]);

    useEffect(() => {
        load();
    }, [load]);

    if (!data) {
        return <PageLoader label="Loading analytics…" />;
    }

    const en = data.enrollment;
    const as = data.assignments;
    const qz = data.quizzes;

    return (
        <div className="space-y-6">
            <PageHeader title="Analytics" subtitle="Course engagement, completions, and performance." />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Total enrollments" value={en.total} icon="users" color="bg-blue-50 text-blue-600" />
                <StatCard label="Completions" value={en.completed} sub={`${en.completion_rate}% completion rate`} icon="checkCircle" color="bg-emerald-50 text-emerald-600" />
                <StatCard label="Average progress" value={`${en.average_progress}%`} icon="trendingUp" color="bg-brand-50 text-brand-600" />
                <StatCard label="Quiz pass rate" value={`${qz.pass_rate}%`} sub={`${qz.average_score}% average score`} icon="puzzle" color="bg-violet-50 text-violet-600" />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Section title="Progress distribution" icon="trendingUp" bodyClassName="p-5">
                    <div className="space-y-3">
                        <Bar label="Just started (0–24%)" value={en.distribution['0-24']} color="bg-red-400" />
                        <Bar label="Getting there (25–49%)" value={en.distribution['25-49']} color="bg-amber-400" />
                        <Bar label="Almost done (50–74%)" value={en.distribution['50-74']} color="bg-sky-400" />
                        <Bar label="Finishing up (75–99%)" value={en.distribution['75-99']} color="bg-brand-400" />
                        <Bar label="Completed (100%)" value={en.distribution['100']} color="bg-emerald-500" />
                    </div>
                </Section>

                <Section title="Assignments" icon="clipboard" bodyClassName="p-5">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="rounded-xl bg-slate-50 p-4 text-center">
                            <p className="text-2xl font-bold text-slate-900">{as.total}</p>
                            <p className="text-xs text-slate-500">Assignments</p>
                        </div>
                        <div className="rounded-xl bg-slate-50 p-4 text-center">
                            <p className="text-2xl font-bold text-slate-900">{as.submitted}</p>
                            <p className="text-xs text-slate-500">Submissions</p>
                        </div>
                        <div className="rounded-xl bg-emerald-50 p-4 text-center">
                            <p className="text-2xl font-bold text-emerald-700">{as.graded}</p>
                            <p className="text-xs text-slate-500">Graded</p>
                        </div>
                        <div className="rounded-xl bg-amber-50 p-4 text-center">
                            <p className="text-2xl font-bold text-amber-700">{as.pending}</p>
                            <p className="text-xs text-slate-500">Pending review</p>
                        </div>
                    </div>
                </Section>
            </div>

            <Section title="Quiz performance" icon="puzzle" bodyClassName="p-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl bg-slate-50 p-4 text-center">
                        <p className="text-2xl font-bold text-slate-900">{qz.total_quizzes}</p>
                        <p className="text-xs text-slate-500">Quizzes</p>
                    </div>
                    <div className="rounded-xl bg-slate-50 p-4 text-center">
                        <p className="text-2xl font-bold text-slate-900">{qz.attempts}</p>
                        <p className="text-xs text-slate-500">Attempts</p>
                    </div>
                    <div className="rounded-xl bg-emerald-50 p-4 text-center">
                        <p className="text-2xl font-bold text-emerald-700">
                            {qz.attempts ? `${Math.round((qz.passed / qz.attempts) * 100)}%` : '—'}
                        </p>
                        <p className="text-xs text-slate-500">{qz.passed} passed</p>
                    </div>
                </div>
            </Section>
        </div>
    );
}