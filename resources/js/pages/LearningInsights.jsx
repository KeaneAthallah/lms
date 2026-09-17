import { useEffect, useState } from 'react';
import api from '../api';
import { Alert, Badge, ButtonLink, EmptyState, Icon, PageHeader, PageLoader, Section, StatCard, timeAgo } from '../components/ui';

const readinessMeta = {
    not_ready: { color: 'slate', label: 'Not ready yet' },
    preparing: { color: 'amber', label: 'Prepare first' },
    ready: { color: 'green', label: 'Ready' },
};

const planActionMeta = {
    Review: { icon: 'refresh', chip: 'text-amber-700 bg-amber-50' },
    Continue: { icon: 'play', chip: 'text-brand-700 bg-brand-50' },
    Practice: { icon: 'puzzle', chip: 'text-violet-700 bg-violet-50' },
};

const momentumMeta = {
    'Steady momentum': 'green',
    Active: 'blue',
    'Getting back on track': 'amber',
    'Light week': 'amber',
    'No activity yet': 'slate',
};

function FocusCard({ focus }) {
    const isReview = focus.type === 'review';
    const priority = focus.priority === 'high';

    return (
        <div
            className={`flex flex-col gap-4 rounded-xl border p-5 sm:flex-row sm:items-center ${
                isReview ? 'border-red-200 bg-red-50/70' : 'border-brand-200 bg-brand-50/70'
            }`}
        >
            <div
                className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${
                    isReview ? 'bg-red-100 text-red-600' : 'bg-brand-100 text-brand-600'
                }`}
            >
                <Icon name={isReview ? 'alert' : 'bookOpen'} className="h-6 w-6" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge color={isReview ? (priority ? 'red' : 'amber') : 'blue'} dot>
                        {isReview ? (priority ? 'High priority review' : 'Review recommended') : 'Continue learning'}
                    </Badge>
                    <span className="text-xs text-slate-500">{focus.lesson.course.title}</span>
                </div>
                <h3 className="mt-1 text-lg font-bold text-slate-900">{focus.lesson.title}</h3>
                <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{focus.reason}</p>
            </div>
            <ButtonLink to={focus.cta.to} variant={isReview ? 'dark' : 'primary'} icon={isReview ? 'refresh' : 'play'} className="shrink-0">
                {focus.cta.label}
            </ButtonLink>
        </div>
    );
}

function ReviewList({ reviews }) {
    return (
        <ul className="divide-y divide-slate-100">
            {reviews.map((review) => (
                <li key={review.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                        <Icon name="refresh" className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="font-semibold text-slate-900">{review.lesson.title}</h3>
                            <Badge color={review.priority === 'high' ? 'red' : 'amber'} dot>
                                {review.priority === 'high' ? 'High priority' : 'Medium priority'}
                            </Badge>
                            <span className="text-xs font-medium text-slate-400">{review.lesson.course.title}</span>
                        </div>
                        <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{review.reason}</p>
                        <p className="mt-1 text-xs font-semibold text-slate-500">{review.evidence}</p>
                    </div>
                    <ButtonLink to={review.cta.to} variant="secondary" size="sm" icon="refresh" className="shrink-0">
                        Review
                    </ButtonLink>
                </li>
            ))}
        </ul>
    );
}

function RecommendationList({ recommendations }) {
    return (
        <ul className="divide-y divide-slate-100">
            {recommendations.map((item) => (
                <li key={item.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <Icon name="bookOpen" className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h3 className="font-semibold text-slate-900">{item.lesson.title}</h3>
                        <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{item.reason}</p>
                    </div>
                    <ButtonLink to={item.cta.to} size="sm" icon="arrowRight" className="shrink-0">
                        Continue
                    </ButtonLink>
                </li>
            ))}
        </ul>
    );
}

function ReadinessList({ items }) {
    return (
        <ul className="divide-y divide-slate-100">
            {items.map((item) => {
                const meta = readinessMeta[item.state] ?? { color: 'slate', label: item.state };

                return (
                    <li key={item.lesson_id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600">
                            <Icon name="puzzle" className="h-5 w-5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="font-semibold text-slate-900">{item.quiz.title}</h3>
                                <Badge color={meta.color} dot>
                                    {meta.label}
                                </Badge>
                            </div>
                            <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{item.reason}</p>
                        </div>
                        <ButtonLink to={item.cta.to} size="sm" icon="arrowRight" className="shrink-0">
                            {item.cta.label}
                        </ButtonLink>
                    </li>
                );
            })}
        </ul>
    );
}

function StudyPlan({ items, totalMinutes }) {
    return (
        <ul className="divide-y divide-slate-100">
            {items.map((item) => {
                const meta = planActionMeta[item.action] ?? { icon: 'play', chip: 'text-slate-600 bg-slate-100' };

                return (
                    <li key={`${item.order}-${item.action}`} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">
                            {item.order}
                        </span>
                        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${meta.chip}`}>
                            <Icon name={meta.icon} className="h-5 w-5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <h3 className="font-semibold text-slate-900">{item.lesson.title}</h3>
                            <p className="text-xs font-medium text-slate-500">
                                {item.action} · {item.lesson.course.title}
                            </p>
                            <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{item.reason}</p>
                        </div>
                        <div className="shrink-0 text-right">
                            <p className="mb-1 flex items-center justify-end gap-1 text-sm font-semibold text-slate-700">
                                <Icon name="clock" className="h-4 w-4" />
                                ~{item.minutes} {item.estimated ? 'min (est.)' : 'min'}
                            </p>
                            <ButtonLink to={item.cta.to} variant="secondary" size="sm" icon={meta.icon} className="w-full sm:w-auto">
                                {item.action}
                            </ButtonLink>
                        </div>
                    </li>
                );
            })}

            <li className="flex items-center justify-between bg-slate-50 px-5 py-3">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Estimated total</span>
                <span className="flex items-center gap-1 text-sm font-bold text-slate-800">
                    <Icon name="clock" className="h-4 w-4" />
                    ~{totalMinutes} min
                </span>
            </li>
        </ul>
    );
}

export default function LearningInsights() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        api.get('/api/learning-insights')
            .then(({ data }) => setData(data.data))
            .catch(() => setFailed(true))
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <PageLoader label="Computing your learning insights…" />;

    if (failed || !data) {
        return (
            <EmptyState
                icon="alert"
                title="Insights unavailable"
                message="We couldn't generate your learning insights right now. Please try again in a moment."
            />
        );
    }

    const summary = data.summary ?? {};
    const momentum = summary.momentum ?? {};
    const { focus, reviews = [], recommendations = [], quiz_readiness = [], study_plan = [] } = data;

    const momentumBadge = momentumMeta[momentum.label] ?? 'slate';

    const hasContent =
        (summary.active_courses ?? 0) > 0 || reviews.length > 0 || recommendations.length > 0 || quiz_readiness.length > 0 || study_plan.length > 0;

    return (
        <div className="space-y-8">
            <PageHeader
                icon="target"
                title="Learning insights"
                subtitle="What to focus on next, based on your activity, quiz results, and grades."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {momentum.label ? <Badge color={momentumBadge} dot>{momentum.label}</Badge> : null}
                        {momentum.last_activity_at ? <span className="text-xs text-slate-400">Last activity {timeAgo(momentum.last_activity_at)}</span> : null}
                    </div>
                }
            />

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Active courses" value={summary.active_courses ?? 0} icon="book" tone="brand" />
                <StatCard
                    label="Lessons this week"
                    value={momentum.lessons_completed_7d ?? 0}
                    icon="checkCircle"
                    tone="green"
                    hint={momentum.streak_days ? `${momentum.streak_days}-day streak` : null}
                />
                <StatCard
                    label="Quiz attempts"
                    value={momentum.quiz_attempts_7d ?? 0}
                    icon="puzzle"
                    tone="amber"
                    hint={momentum.submissions_7d ? `${momentum.submissions_7d} graded this week` : null}
                />
                <StatCard label="Needs review" value={summary.reviews_count ?? 0} icon="alert" tone={summary.reviews_count ? 'red' : 'slate'} />
            </div>

            {focus ? <FocusCard focus={focus} /> : null}

            {reviews.length ? (
                <Section title="Needs review" icon="alert" actions={<Badge color="red" dot>{reviews.length} lessons</Badge>}>
                    <ReviewList reviews={reviews} />
                </Section>
            ) : null}

            {recommendations.length ? (
                <Section title="Recommended next" icon="play">
                    <RecommendationList recommendations={recommendations} />
                </Section>
            ) : null}

            {quiz_readiness.length ? (
                <Section title="Quiz readiness" icon="puzzle">
                    <ReadinessList items={quiz_readiness} />
                </Section>
            ) : null}

            {study_plan.length ? (
                <Section title="Suggested study plan" icon="layers" actions={<Badge color="blue" dot>~{summary.study_plan_total_minutes} min</Badge>}>
                    <StudyPlan items={study_plan} totalMinutes={summary.study_plan_total_minutes} />
                </Section>
            ) : null}

            {hasContent ? null : (
                <EmptyState
                    icon="target"
                    title="No learning insights yet"
                    message="Enroll in a course and complete a few lessons to unlock personalized recommendations."
                    action={<ButtonLink to="/browse" icon="search">Browse courses</ButtonLink>}
                />
            )}

            <Alert tone="info" icon="sparkles" title="How these recommendations work">
                Every suggestion on this page is generated from your own progress, quiz results, and grades using fixed, transparent rules
                — no artificial intelligence is used.
            </Alert>
        </div>
    );
}