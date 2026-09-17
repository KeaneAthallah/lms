import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { Alert, Badge, ButtonLink, EmptyState, Icon, PageHeader, PageLoader, ProgressBar, Section, StatCard, timeAgo } from '../components/ui';

const conceptStatusMeta = {
    mastered: { color: 'green', label: 'Mastered', icon: 'checkCircle', chip: 'bg-emerald-50 text-emerald-600' },
    building: { color: 'blue', label: 'Building', icon: 'trendingUp', chip: 'bg-brand-50 text-brand-600' },
    review: { color: 'red', label: 'Review', icon: 'refresh', chip: 'bg-amber-50 text-amber-600' },
    unassessed: { color: 'slate', label: 'Unassessed', icon: 'compass', chip: 'bg-slate-100 text-slate-500' },
};

const readinessMeta = {
    not_ready: { color: 'slate', label: 'Not ready yet' },
    preparing: { color: 'amber', label: 'Prepare first' },
    ready: { color: 'green', label: 'Ready' },
    passed: { color: 'blue', label: 'Passed' },
    unknown: { color: 'slate', label: 'Unmeasured' },
};

function ConceptRow({ concept }) {
    const meta = conceptStatusMeta[concept.status] ?? conceptStatusMeta.unassessed;

    return (
        <li className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${meta.chip}`}>
                <Icon name={meta.icon} className="h-5 w-5" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-semibold text-slate-900">{concept.concept}</h3>
                    <Badge color={meta.color} dot>{meta.label}</Badge>
                    <span className="text-xs font-medium text-slate-400">
                        {concept.lessons_completed}/{concept.lessons_total} lessons
                    </span>
                </div>
                <div className="mt-2 flex items-center gap-3">
                    <ProgressBar
                        value={concept.mastery_percent}
                        color={concept.status === 'mastered' ? 'bg-emerald-500' : concept.status === 'review' ? 'bg-amber-500' : 'bg-brand-600'}
                        className="max-w-xs"
                    />
                    <span className="text-sm font-bold text-slate-700">{concept.mastery_percent}%</span>
                </div>
                {concept.assessment_score !== null ? (
                    <p className="mt-1 text-xs text-slate-500">
                        Best recent quiz: {concept.assessment_score}%
                        {concept.last_attempt_at ? <> · last attempt {timeAgo(concept.last_attempt_at)}</> : null}
                    </p>
                ) : (
                    <p className="mt-1 text-xs text-slate-500">No quiz taken yet in this concept — mastery reflects completed lessons only.</p>
                )}
            </div>
            {concept.next_lesson_id && (
                <ButtonLink to="#" size="sm" variant="ghost" icon="arrowRight" className="hidden shrink-0 sm:inline-flex">
                    Next lesson
                </ButtonLink>
            )}
        </li>
    );
}

function UpcomingQuizCard({ item }) {
    if (!item) return null;
    const meta = readinessMeta[item.state] ?? { color: 'slate', label: item.state };

    return (
        <div className="mt-4 flex flex-col gap-3 rounded-lg border border-violet-200 bg-violet-50/60 p-4 sm:flex-row sm:items-center">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-600">
                <Icon name="puzzle" className="h-5 w-5" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-semibold uppercase tracking-wide text-violet-500">Up next quiz</span>
                    <Badge color={meta.color} dot>{meta.label}</Badge>
                </div>
                <h3 className="mt-0.5 font-semibold text-slate-900">{item.quiz.title}</h3>
                <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{item.reason}</p>
            </div>
            {item.cta ? (
                <ButtonLink to={item.cta.to} size="sm" icon="arrowRight" className="shrink-0">
                    {item.cta.label}
                </ButtonLink>
            ) : null}
        </div>
    );
}

function CourseCard({ entry }) {
    const { course, concepts = [] } = entry;

    return (
        <Section
            title={course.title}
            icon="bookOpen"
            actions={
                <div className="flex flex-wrap items-center gap-3">
                    <span className="text-xs font-medium text-slate-400">
                        {entry.completed_lessons}/{entry.total_lessons} lessons completed
                    </span>
                    <span className="flex items-center gap-1 text-sm font-bold text-slate-700">
                        {entry.progress_percent}%
                    </span>
                </div>
            }
        >
            <div className="px-5 pt-4">
                <ProgressBar value={entry.progress_percent} color="bg-brand-600" />
            </div>

            {entry.next_lesson ? (
                <div className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                        <Icon name="play" className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Continue where you left off</p>
                        <h3 className="font-semibold text-slate-900">
                            <Link to={entry.next_lesson.to} className="hover:underline">{entry.next_lesson.title}</Link>
                        </h3>
                    </div>
                    <ButtonLink to={entry.next_lesson.to} size="sm" icon="play" className="shrink-0">
                        Continue
                    </ButtonLink>
                </div>
            ) : null}

            <UpcomingQuizCard item={entry.upcoming_quiz} />

            {concepts.length ? (
                <ul className="divide-y divide-slate-100">
                    {concepts.map((concept) => (
                        <ConceptRow key={`${concept.section_id}-${concept.concept}`} concept={concept} />
                    ))}
                </ul>
            ) : null}
        </Section>
    );
}

export default function LearningMap() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        api.get('/api/learning-map')
            .then(({ data }) => setData(data.data))
            .catch(() => setFailed(true))
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <PageLoader label="Building your learning map…" />;

    if (failed || !data) {
        return (
            <EmptyState
                icon="compass"
                title="Learning map unavailable"
                message="We couldn't build your learning map right now. Please try again in a moment."
            />
        );
    }

    const totalConcepts = data.courses.reduce((sum, course) => sum + course.concepts.length, 0);
    const reviewConcepts = data.courses.reduce(
        (sum, course) => sum + course.concepts.filter((c) => c.status === 'review').length,
        0,
    );

    return (
        <div className="space-y-8">
            <PageHeader
                icon="compass"
                title="Learning map"
                subtitle="A concept-by-concept view of every course you're actively learning, built from your real quiz results and lesson progress."
            />

            {data.courses.length ? (
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard
                        label="Overall mastery"
                        value={`${data.overall_percent}%`}
                        icon="cpu"
                        tone={data.overall_percent >= 75 ? 'green' : data.overall_percent >= 60 ? 'amber' : 'brand'}
                    />
                    <StatCard label="Active courses" value={data.courses.length} icon="book" tone="brand" />
                    <StatCard label="Concepts tracked" value={totalConcepts} icon="layers" tone="violet" />
                    <StatCard label="Needs review" value={reviewConcepts} icon="alert" tone={reviewConcepts ? 'red' : 'slate'} />
                </div>
            ) : null}

            {data.courses.map((entry) => (
                <CourseCard key={entry.course.id} entry={entry} />
            ))}

            {data.courses.length === 0 ? (
                <EmptyState
                    icon="compass"
                    title="No active courses yet"
                    message="Enroll in a course and start completing lessons to grow your learning map."
                    action={<ButtonLink to="/browse" icon="search">Browse courses</ButtonLink>}
                />
            ) : null}

            <Alert tone="info" icon="sparkles" title="How mastery is calculated">
                Mastery combines your best recent quiz result (60%) with the share of lessons you've completed (40%) per course
                section. Concepts you haven't been assessed on are labeled “Unassessed” — nothing is ever claimed without evidence,
                and no artificial intelligence is involved.
            </Alert>
        </div>
    );
}