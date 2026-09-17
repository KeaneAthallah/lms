import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { apiError } from '../api';
import { Badge, Button, ButtonLink, cx, EmptyState, formatBytes, Icon, PageLoader, ProgressBar, useToast } from '../components/ui';

const lessonIcon = {
    video: 'video',
    quiz: 'puzzle',
    assignment: 'clipboard',
    text: 'doc',
    external_link: 'link',
    document: 'file',
};

function CurriculumPanel({ course, sections, lesson, activeId, slug }) {
    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-card">
            <div className="border-b border-slate-200 bg-slate-50 px-4 py-3">
                <Link to={`/courses/${slug}`} className="text-sm font-bold text-slate-900 hover:text-brand-600">
                    {course.title}
                </Link>
                <ProgressBar value={Number(lesson?.progress) || 0} className="mt-2" />
                <p className="mt-1 text-right text-xs font-semibold text-slate-500">{Number(lesson?.progress) || 0}%</p>
            </div>
            <nav className="max-h-[calc(100vh-16rem)] overflow-y-auto p-2" aria-label="Course curriculum">
                {sections?.map((section) => (
                    <div key={section.id} className="mb-2">
                        <p className="px-2 pb-1 pt-2 text-xs font-bold uppercase tracking-wide text-slate-400">{section.title}</p>
                        <ul className="space-y-0.5">
                            {(section.lessons ?? []).map((item) => {
                                const isActive = item.id === (lesson?.lesson?.id ?? activeId);
                                const done = item.progress?.completed;
                                return (
                                    <li key={item.id}>
                                        <Link
                                            to={`/learn/${slug}/${item.id}`}
                                            className={cx(
                                                'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition',
                                                isActive
                                                    ? 'bg-brand-50 font-semibold text-brand-700'
                                                    : 'text-slate-600 hover:bg-slate-50',
                                            )}
                                        >
                                            <span
                                                className={cx(
                                                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border',
                                                    done
                                                        ? 'border-emerald-500 bg-emerald-500 text-white dark:text-gray-50'
                                                        : 'border-slate-300 text-slate-400',
                                                )}
                                            >
                                                {done ? <Icon name="check" className="h-3 w-3" strokeWidth={3} /> : <Icon name={lessonIcon[item.type]} className="h-3 w-3" />}
                                            </span>
                                            <span className="min-w-0 flex-1 line-clamp-1">{item.title}</span>
                                            {item.quiz?.has_passed ? (
                                                <Badge color="green" className="px-1.5 py-0 text-[10px]">
                                                    Passed
                                                </Badge>
                                            ) : null}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </nav>
        </div>
    );
}

export default function Learn() {
    const { slug } = useParams();
    const splat = useParams()['*'] ?? '';
    const toast = useToast();

    const [data, setData] = useState(null);
    const [lesson, setLesson] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [showCurriculum, setShowCurriculum] = useState(false);

    const allLessons = useMemo(
        () => data?.course?.sections?.flatMap((s) => s.lessons ?? []) ?? [],
        [data],
    );

    useEffect(() => {
        setLoading(true);
        api.get(`/api/courses/${slug}/learn`)
            .then(({ data }) => setData(data))
            .catch((err) => setError(err.response?.status === 403 ? 'You are not enrolled in this course.' : apiError(err)))
            .finally(() => setLoading(false));
    }, [slug]);

    const activeId = lesson?.id ?? (splat ? Number(splat) : null);

    useEffect(() => {
        if (!data) return;
        const targetId = activeId && allLessons.some((l) => l.id === activeId) ? activeId : (allLessons[0]?.id ?? null);
        if (!targetId) return;
        api.get(`/api/courses/${slug}/learn/${targetId}`)
            .then(({ data }) => setLesson(data))
            .catch(() => setLesson(null));
    }, [data, activeId, slug, allLessons]);

    if (loading) return <PageLoader label="Loading course…" />;

    if (error || !data) {
        return <EmptyState icon="lock" title="Course access" message={error || 'Course content could not be loaded.'} />;
    }

    if (!lesson) {
        return <EmptyState icon="doc" title="No lessons yet" message="The instructor has not published lessons for this course." />;
    }

    const hasCurriculum = (data.course.sections ?? []).some((s) => (s.lessons ?? []).length);

    return (
        <div className="space-y-5">
            {hasCurriculum ? (
                <div className="lg:hidden">
                    <button
                        type="button"
                        onClick={() => setShowCurriculum((v) => !v)}
                        aria-expanded={showCurriculum}
                        className="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-left shadow-card"
                    >
                        <span className="flex min-w-0 items-center gap-2 text-sm font-semibold text-slate-900">
                            <Icon name="listChecks" className="h-4 w-4 shrink-0 text-brand-500" />
                            <span className="truncate">Curriculum</span>
                        </span>
                        <span className="flex shrink-0 items-center gap-1.5 text-xs font-medium text-slate-500">
                            {lesson.progress?.percent ?? 0}%
                            <Icon name={showCurriculum ? 'chevronDown' : 'chevronRight'} className="h-4 w-4" />
                        </span>
                    </button>
                    {showCurriculum ? (
                        <div className="mt-3 animate-fade-in">
                            <CurriculumPanel course={data.course} sections={data.course.sections} lesson={lesson} activeId={activeId} slug={slug} />
                        </div>
                    ) : null}
                </div>
            ) : null}

            <div className="grid gap-6 lg:grid-cols-[320px_1fr]">
                <aside className="hidden lg:sticky lg:top-20 lg:block lg:self-start">
                    <CurriculumPanel course={data.course} sections={data.course.sections} lesson={lesson} activeId={activeId} slug={slug} />
                </aside>

                <main className="min-w-0">
                    <LessonView lesson={lesson} slug={slug} allLessons={allLessons} toast={toast} onProgress={() => loadLesson(slug, lesson.lesson?.id)} />
                </main>
            </div>
        </div>
    );
}

async function loadLesson(slug, id) {
    const { data } = await api.get(`/api/courses/${slug}/learn/${id}`);
    return data;
}

function LessonView({ lesson, slug, allLessons, toast, onProgress }) {
    const [progress, setProgress] = useState(lesson.lesson?.progress?.percent ?? 0);
    const [complete, setComplete] = useState(lesson.lesson?.progress?.completed ?? false);
    const [busy, setBusy] = useState(false);
    const videoRef = useRef(null);
    const lastPush = useRef(0);

    useEffect(() => {
        setProgress(lesson.lesson?.progress?.percent ?? 0);
        setComplete(lesson.lesson?.progress?.completed ?? false);
    }, [lesson]);

    const push = useCallback(
        async (payload) => {
            setBusy(true);
            try {
                const { data } = await api.patch(`/api/lessons/${lesson.lesson.id}/progress`, payload);
                setProgress(data.lesson_progress.percent);
                setComplete(data.lesson_progress.completed);
                if (onProgress) onProgress();
                return data;
            } catch (err) {
                toast(apiError(err), 'error');
                return null;
            } finally {
                setBusy(false);
            }
        },
        [lesson, toast, onProgress],
    );

    const markComplete = () => push({ complete: true });

    const onVideoTime = () => {
        const video = videoRef.current;
        if (!video || video.duration === Infinity) return;
        const now = Date.now();
        if (now - lastPush.current < 5000) return;
        lastPush.current = now;
        const threshold = 0.9;
        if (video.currentTime / video.duration >= threshold) {
            markComplete();
        } else {
            push({ video_position: Math.floor(video.currentTime) });
        }
    };

    const handleVideoEnded = () => markComplete();

    const currentIndex = allLessons.findIndex((l) => l.id === lesson.lesson.id);
    const prevLesson = currentIndex > 0 ? allLessons[currentIndex - 1] : null;
    const nextLesson = currentIndex < allLessons.length - 1 ? allLessons[currentIndex + 1] : null;

    const l = lesson.lesson;
    const isYouTube = l.video_url && /(youtube\.com|youtu\.be)/.test(l.video_url);
    const isVimeo = l.video_url && /vimeo\.com/.test(l.video_url);
    const videoSrc = !isYouTube && !isVimeo ? l.video_url : null;

    return (
        <div className="animate-slide-in space-y-6">
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-card">
                <div className="border-b border-slate-200 bg-slate-900 px-5 py-4 text-white dark:bg-gray-900 dark:text-gray-50">
                    <p className="text-xs font-medium uppercase tracking-wide text-brand-300">
                        Lesson {lesson.navigation.current_index} of {lesson.navigation.total}
                    </p>
                    <h1 className="mt-0.5 text-lg font-bold">{l.title}</h1>
                </div>

                <div className="p-5">
                    {l.type === 'video' ? (
                        <div className="overflow-hidden rounded-lg bg-slate-900 dark:bg-gray-900">
                            {videoSrc ? (
                                <video
                                    ref={videoRef}
                                    src={videoSrc}
                                    controls
                                    className="aspect-video w-full"
                                    onTimeUpdate={onVideoTime}
                                    onEnded={handleVideoEnded}
                                    onLoadedMetadata={() => push({ video_position: 0 })}
                                />
                            ) : isYouTube ? (
                                <iframe
                                    src={l.video_url.replace('watch?v=', 'embed/').replace('youtu.be/', 'youtube.com/embed/')}
                                    title={l.title}
                                    className="aspect-video w-full"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                    allowFullScreen
                                />
                            ) : isVimeo ? (
                                <iframe src={l.video_url} title={l.title} className="aspect-video w-full" allowFullScreen />
                            ) : (
                                <div className="flex aspect-video w-full flex-col items-center justify-center gap-3 p-6 text-center text-sm text-slate-300">
                                    <Icon name="video" className="h-8 w-8" />
                                    <p>Video preview unavailable while offline.</p>
                                    {l.video_url ? (
                                        <a href={l.video_url} target="_blank" rel="noreferrer" className="font-semibold text-brand-400 hover:underline">
                                            Open video in a new tab →
                                        </a>
                                    ) : null}
                                </div>
                            )}
                        </div>
                    ) : null}

                    {l.type === 'text' && l.content ? (
                        <div className="prose prose-slate max-w-none">
                            <div dangerouslySetInnerHTML={{ __html: l.content }} />
                        </div>
                    ) : null}

                    {l.type === 'external_link' && l.external_url ? (
                        <div className="flex flex-col items-center gap-3 rounded-lg bg-slate-50 px-6 py-10 text-center">
                            <Icon name="link" className="h-8 w-8 text-brand-500" />
                            <p className="text-sm text-slate-600">This lesson links to an external resource.</p>
                            <a href={l.external_url} target="_blank" rel="noreferrer">
                                <Button icon="arrowRight">Open external resource</Button>
                            </a>
                        </div>
                    ) : null}

                    {l.type === 'quiz' ? (
                        <div className="flex flex-col items-center gap-3 rounded-lg bg-violet-50 px-6 py-10 text-center">
                            <Icon name="puzzle" className="h-8 w-8 text-violet-500" />
                            <p className="font-semibold text-slate-900">{l.quiz?.title ?? 'Quiz'}</p>
                            <p className="max-w-md text-sm text-slate-600">{l.quiz?.instructions ?? 'Test your knowledge with this quiz.'}</p>
                            {l.quiz?.questions_count ? (
                                <p className="text-xs text-slate-500">
                                    {l.quiz.questions_count} questions · pass at {l.quiz?.passing_score ?? 0}%
                                </p>
                            ) : null}
                            <ButtonLink to={`/quiz/${l.quiz?.id}`} variant="dark" icon="play" className="mt-2">
                                {l.quiz?.has_passed ? 'Retake quiz' : 'Start quiz'}
                            </ButtonLink>
                        </div>
                    ) : null}

                    {l.type === 'assignment' ? (
                        <div className="flex flex-col items-center gap-3 rounded-lg bg-amber-50 px-6 py-10 text-center">
                            <Icon name="clipboard" className="h-8 w-8 text-amber-500" />
                            <p className="font-semibold text-slate-900">{l.assignment?.title ?? 'Assignment'}</p>
                            <p className="max-w-md text-sm text-slate-600">{l.assignment?.brief ?? 'Submit your work to complete this lesson.'}</p>
                            <ButtonLink to={`/assignment/${l.assignment?.id}`} variant="dark" icon="pencil" className="mt-2">
                                {l.assignment?.submissions?.length ? 'Edit submission' : 'Complete assignment'}
                            </ButtonLink>
                        </div>
                    ) : null}

                    {l.materials?.length ? (
                        <div className="mt-5">
                            <h3 className="mb-2 text-sm font-bold text-slate-900">Lesson materials</h3>
                            <ul className="space-y-2">
                                {l.materials.map((m) => (
                                    <li key={m.id}>
                                        <a
                                            href={`/api/lessons/${l.id}/materials/${m.id}/download`}
                                            className="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-2.5 text-sm text-slate-700 transition hover:border-brand-300 hover:bg-brand-50"
                                        >
                                            <Icon name="file" className="h-4 w-4 text-brand-500" />
                                            <span className="min-w-0 flex-1 truncate">{m.filename}</span>
                                            <span className="shrink-0 text-xs text-slate-400">
                                                {formatBytes(m.size)}
                                                {m.is_downloadable ? ' · download' : ' · preview'}
                                            </span>
                                            <Icon name="download" className="h-4 w-4 shrink-0 text-slate-400" />
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    <div className="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-5">
                        <Button variant={complete ? 'success' : 'primary'} loading={busy} icon={complete ? 'check' : 'checkCircle'} onClick={markComplete}>
                            {complete ? 'Completed' : 'Mark as complete'}
                        </Button>
                        <div className="flex items-center gap-2">
                            {prevLesson ? (
                                <ButtonLink to={`/learn/${slug}/${prevLesson.id}`} variant="secondary" icon="arrowLeft">
                                    Previous
                                </ButtonLink>
                            ) : null}
                            {nextLesson ? (
                                <ButtonLink to={`/learn/${slug}/${nextLesson.id}`} icon="arrowRight">
                                    Next lesson
                                </ButtonLink>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}