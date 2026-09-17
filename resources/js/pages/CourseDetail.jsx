import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { apiError } from '../api';
import { useAuth } from '../auth';
import { Avatar, Badge, Button, ButtonLink, cx, EmptyState, Icon, PageLoader, ProgressBar, useToast } from '../components/ui';

const levelTone = { beginner: 'green', intermediate: 'amber', advanced: 'red', all: 'blue' };

const lessonIcon = {
    video: 'video',
    quiz: 'puzzle',
    assignment: 'clipboard',
    text: 'doc',
    document: 'file',
    external_link: 'link',
};

const roadmapStateMeta = {
    completed: { color: 'green', label: 'Completed', icon: 'checkCircle' },
    in_progress: { color: 'blue', label: 'In progress', icon: 'play' },
    not_started: { color: 'slate', label: 'Not started', icon: 'clock' },
};

function RoadmapStrip() {
    const { slug } = useParams();
    const { user } = useAuth();
    const [roadmap, setRoadmap] = useState(null);

    useEffect(() => {
        if (!user) return;
        let active = true;
        api.get(`/api/courses/${slug}/roadmap`)
            .then(({ data }) => {
                if (active) setRoadmap(data.data);
            })
            .catch(() => {});
        return () => {
            active = false;
        };
    }, [slug, user]);

    if (!roadmap || roadmap.state === 'none' || !roadmap.prerequisites?.length) return null;

    return (
        <section className="rounded-2xl border border-brand-200 bg-brand-50/50 p-5">
            <div className="flex flex-wrap items-center gap-2">
                <Icon name="compass" className="h-5 w-5 text-brand-600" />
                <h2 className="font-bold text-slate-900">Recommended preparation</h2>
                <span className="text-xs text-slate-500">Informational — you can still enroll directly.</span>
            </div>
            <ul className="mt-4 space-y-3">
                {roadmap.prerequisites.map((entry) => {
                    const meta = roadmapStateMeta[entry.state] ?? { color: 'slate', label: entry.state, icon: 'info' };

                    return (
                        <li key={entry.course.id} className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                <Icon name={meta.icon} className="h-4.5 w-4.5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="font-semibold text-slate-900">{entry.course.title}</h3>
                                    <Badge color={meta.color} dot>{meta.label}</Badge>
                                </div>
                                <div className="mt-2 flex items-center gap-2">
                                    <ProgressBar value={entry.progress_percent} color="bg-brand-600" className="max-w-40" />
                                    <span className="text-xs font-semibold text-slate-500">{entry.progress_percent}%</span>
                                </div>
                            </div>
                            {entry.cta ? (
                                <ButtonLink to={entry.cta.to} size="sm" variant="secondary" icon="arrowRight" className="shrink-0">
                                    {entry.cta.label}
                                </ButtonLink>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

export default function CourseDetail() {
    const { slug } = useParams();
    const { user, isAdmin, isStudent } = useAuth();
    const toast = useToast();

    const [course, setCourse] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [enrolling, setEnrolling] = useState(false);

    useEffect(() => {
        setLoading(true);
        api.get(`/api/courses/${slug}`)
            .then(({ data }) => setCourse(data.data))
            .catch((err) => setError(err.response?.status === 404 ? 'This course could not be found.' : apiError(err)))
            .finally(() => setLoading(false));
    }, [slug]);

    if (loading) return <PageLoader label="Loading course…" />;

    if (error || !course) {
        return <EmptyState icon="alert" title="Course not found" message={error || 'This course is no longer available.'} />;
    }

    const enrollment = course.enrollment;
    const isOwner = user && (isAdmin || user.id === course.instructor?.id);
    const isEnrolled = Boolean(enrollment);

    const sections = course.sections ?? [];
    const totalLessons = sections.reduce((sum, s) => sum + (s.lessons?.length ?? 0), 0) ?? course.lessons_count ?? 0;

    const enroll = async () => {
        setEnrolling(true);
        try {
            await api.post(`/api/courses/${course.slug}/enroll`);
            toast('You are now enrolled!', 'success');
            const { data } = await api.get(`/api/courses/${course.slug}`);
            setCourse(data.data);
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setEnrolling(false);
        }
    };

    return (
        <div className="space-y-8">
            <section className="relative overflow-hidden rounded-3xl bg-slate-900 text-white dark:bg-gray-900 dark:text-gray-50">
                <div className="absolute inset-0 bg-grid opacity-20" />
                <div className="absolute -right-24 -top-24 h-72 w-72 rounded-full bg-brand-500/30 blur-3xl" />
                <div className="relative grid gap-0 lg:grid-cols-2">
                    <div className="flex flex-col justify-center p-8 sm:p-10">
                        <div className="mb-4 flex flex-wrap items-center gap-2">
                            {course.category ? <Badge color="blue">{course.category.name}</Badge> : null}
                            {course.level ? <Badge color={levelTone[course.level] ?? 'blue'}>{course.level}</Badge> : null}
                        </div>
                        <h1 className="text-2xl font-extrabold leading-tight sm:text-3xl">{course.title}</h1>
                        {course.description ? <p className="mt-3 text-sm leading-relaxed text-slate-300 line-clamp-5">{course.description}</p> : null}
                        <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-400">
                            <span className="flex items-center gap-1.5">
                                <Icon name="play" className="h-4 w-4" />
                                {totalLessons} lessons
                            </span>
                            {course.duration_minutes ? (
                                <span className="flex items-center gap-1.5">
                                    <Icon name="clock" className="h-4 w-4" />
                                    {course.duration_minutes} min
                                </span>
                            ) : null}
                            <span className="flex items-center gap-1.5">
                                <Icon name="users" className="h-4 w-4" />
                                {course.enrollments_count ?? 0} enrolled
                            </span>
                        </div>
                        {course.instructor ? (
                            <div className="mt-6 flex items-center gap-3">
                                <Avatar src={course.instructor.avatar_url} name={course.instructor.name} size="h-11 w-11" />
                                <div>
                                    <p className="text-xs text-slate-400">Instructor</p>
                                    <p className="text-sm font-semibold">{course.instructor.name}</p>
                                </div>
                            </div>
                        ) : null}
                    </div>
                    <div className="relative flex min-h-64 items-center justify-center bg-gradient-to-br from-brand-600 to-brand-900 p-8">
                        {course.thumbnail_url ? (
                            <img src={course.thumbnail_url} alt={course.title} className="absolute inset-0 h-full w-full object-cover opacity-40" />
                        ) : null}
                        <div className="relative w-full max-w-xs rounded-2xl bg-white p-6 text-slate-900 shadow-2xl">
                            <ul className="space-y-2 text-left text-xs font-medium text-slate-600">
                                <li className="flex items-center gap-1.5">
                                    <Icon name="checkCircle" className="h-4 w-4 shrink-0 text-emerald-500" />
                                    Complete curriculum included
                                </li>
                                <li className="flex items-center gap-1.5">
                                    <Icon name="chart" className="h-4 w-4 shrink-0 text-emerald-500" />
                                    Progress tracking & assessments
                                </li>
                                <li className="flex items-center gap-1.5">
                                    <Icon name="badgeCheck" className="h-4 w-4 shrink-0 text-emerald-500" />
                                    Verifiable certificate on completion
                                </li>
                            </ul>
                            {isOwner ? (
                                <Button variant="dark" icon="pencil" className="mt-4 w-full" onClick={() => window.location.assign(`/instructor/courses/${course.slug}`)}>
                                    Manage course
                                </Button>
                            ) : isEnrolled ? (
                                <Button variant="dark" icon="play" className="mt-4 w-full" onClick={() => window.location.assign(`/learn/${course.slug}`)}>
                                    {enrollment.progress_percent > 0 ? 'Continue learning' : 'Start course'}
                                </Button>
                            ) : isStudent ? (
                                <Button variant="dark" icon="play" loading={enrolling} className="mt-4 w-full" onClick={enroll}>
                                    Enroll now
                                </Button>
                            ) : user ? (
                                <p className="mt-4 rounded-lg bg-white/10 px-3 py-2 text-center text-xs font-medium text-white/80 ring-1 ring-white/20 dark:text-gray-50/80">
                                    Preview available — sign in as a student to enroll.
                                </p>
                            ) : (
                                <ButtonLink to="/login" variant="dark" icon="play" className="mt-4 w-full">
                                    Sign in to enroll
                                </ButtonLink>
                            )}
                            {isEnrolled ? (
                                <div className="mt-4">
                                    <ProgressBar value={enrollment.progress_percent ?? 0} />
                                    <p className="mt-1 text-center text-xs font-semibold text-slate-600">
                                        {enrollment.progress_percent ?? 0}% complete
                                    </p>
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </section>

            {isEnrolled || isOwner ? <RoadmapStrip /> : null}

            <section className="grid gap-8 lg:grid-cols-3">
                <div className="space-y-8 lg:col-span-2">
                    {course.learning_objectives?.length ? (
                        <div>
                            <h2 className="mb-3 text-lg font-bold text-slate-900">What you&apos;ll learn</h2>
                            <ul className="grid gap-2 sm:grid-cols-2">
                                {course.learning_objectives.map((objective, i) => (
                                    <li key={i} className="flex items-start gap-2 text-sm text-slate-600">
                                        <Icon name="check" className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" strokeWidth={2.5} />
                                        {objective}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    <div>
                        <h2 className="mb-3 text-lg font-bold text-slate-900">Course content</h2>
                        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                            {sections.map((section, si) => (
                                <div key={section.id} className={cx(si > 0 && 'border-t border-slate-200')}>
                                    <div className="flex items-center justify-between bg-slate-50 px-4 py-3">
                                        <h3 className="font-semibold text-slate-900">{section.title}</h3>
                                        <span className="text-xs text-slate-500">{(section.lessons ?? []).length} lessons</span>
                                    </div>
                                    <ul className="divide-y divide-slate-100">
                                        {(section.lessons ?? []).map((lesson) => (
                                            <li key={lesson.id}>
                                                <div className="flex items-center gap-3 px-4 py-2.5 text-sm">
                                                    <Icon name={lessonIcon[lesson.type] ?? 'doc'} className="h-4 w-4 text-slate-400" />
                                                    <span className="flex-1 text-slate-700">{lesson.title}</span>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                            {sections.length === 0 ? (
                                <p className="px-4 py-6 text-sm text-slate-500">Course content is being prepared by the instructor.</p>
                            ) : null}
                        </div>
                    </div>
                </div>

                <aside className="space-y-6">
                    {course.requirements?.length ? (
                        <div className="rounded-xl border border-slate-200 bg-white p-5">
                            <h3 className="mb-2 flex items-center gap-2 text-sm font-bold text-slate-900">
                                <Icon name="info" className="h-4 w-4 text-brand-500" />
                                Requirements
                            </h3>
                            <ul className="list-inside list-disc space-y-1 text-sm text-slate-600">
                                {course.requirements.map((p, i) => (
                                    <li key={i}>{p}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                    {course.instructor ? (
                        <div className="rounded-xl border border-slate-200 bg-white p-5">
                            <h3 className="mb-3 text-sm font-bold text-slate-900">About the instructor</h3>
                            <div className="flex items-center gap-3">
                                <Avatar src={course.instructor.avatar_url} name={course.instructor.name} size="h-12 w-12" />
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-slate-900">{course.instructor.name}</p>
                                    {course.instructor.headline ? <p className="truncate text-xs text-slate-500">{course.instructor.headline}</p> : null}
                                </div>
                            </div>
                            {course.instructor.bio ? <p className="mt-3 text-sm leading-relaxed text-slate-600">{course.instructor.bio}</p> : null}
                        </div>
                    ) : null}
                    {isEnrolled && totalLessons > 0 ? (
                        <Button icon="play" className="w-full" onClick={() => window.location.assign(`/learn/${course.slug}`)}>
                            Go to course
                        </Button>
                    ) : null}
                </aside>
            </section>
        </div>
    );
}