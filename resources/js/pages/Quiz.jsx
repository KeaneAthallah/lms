import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { apiError } from '../api';
import { Badge, Button, ButtonLink, Card, ConfirmDialog, cx, EmptyState, Icon, PageLoader, useToast } from '../components/ui';
import QuestionInput from '../components/quiz/QuestionInput';
import QuestionReview from '../components/quiz/QuestionReview';
import QuestionText from '../components/quiz/QuestionText';
import { isAnswered } from '../components/quiz/questionTypes';

/**
 * How long to wait after the last keystroke before writing to the server.
 *
 * Long enough that a student thinking through a question types one save rather
 * than one per character, short enough that closing the tab immediately after
 * answering still feels like it kept the work.
 */
const AUTOSAVE_DELAY_MS = 1000;

/**
 * Whether the answers on screen are on the server yet.
 *
 * Only worth showing once there is something to report: an untimed quiz the
 * student has not touched yet would otherwise open on a permanent "saved".
 */
const saveLabel = {
    idle: '',
    dirty: '· saving…',
    saving: '· saving…',
    saved: '· saved',
    error: '· not saved — your answers will be sent when you submit',
};

function formatTime(seconds) {
    if (seconds <= 0) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

function DiagnosisBlock({ diagnosis }) {
    const statusMeta = {
        strong: { color: 'green', label: 'Strong' },
        good: { color: 'blue', label: 'Solid' },
        caution: { color: 'amber', label: 'Caution' },
        review: { color: 'red', label: 'Needs review' },
    };
    const meta = statusMeta[diagnosis.status] ?? { color: 'slate', label: diagnosis.status };

    return (
        <Card className="p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600">
                    <Icon name="compass" className="h-6 w-6" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-semibold uppercase tracking-wide text-violet-500">Explain my mistakes</p>
                    <h2 className="text-lg font-bold text-slate-900">Diagnosis · {diagnosis.concept}</h2>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <Badge color={meta.color} dot>{meta.label}</Badge>
                    <Badge color="slate">{diagnosis.accuracy}% accuracy</Badge>
                    {diagnosis.needs_recovery ? <Badge color="red">Recovery path available</Badge> : null}
                </div>
            </div>

            <div className="mt-4 space-y-2 text-sm leading-relaxed text-slate-600">
                <p>{diagnosis.why}</p>
                <p className="rounded-lg bg-slate-50 px-3 py-2 font-medium text-slate-700">{diagnosis.summary}</p>
            </div>

            {diagnosis.misconceptions?.length ? (
                <div className="mt-5">
                    <h3 className="mb-2 text-sm font-bold text-slate-900">Misconceptions to fix</h3>
                    <ul className="space-y-3">
                        {diagnosis.misconceptions.map((m) => (
                            <li key={m.question_id} className="rounded-xl border border-slate-200 p-4">
                                <p className="font-semibold text-slate-900">{m.question_text}</p>
                                <p className="mt-2 text-sm text-slate-600">
                                    <span className="font-semibold text-red-600">You chose:</span> {m.submitted_answer_text}
                                </p>
                                <p className="mt-0.5 text-sm text-slate-600">
                                    <span className="font-semibold text-emerald-600">Correct answer:</span> {m.correct_answer_text}
                                </p>
                                {m.explanation ? (
                                    <p className="mt-2 rounded-lg bg-violet-50 px-3 py-2 text-sm text-violet-800">{m.explanation}</p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {diagnosis.recommendations?.length ? (
                <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                    <span className="text-sm font-bold text-slate-900">Recovery path:</span>
                    {diagnosis.recommendations.map((recommendation) => (
                        <ButtonLink
                            key={`${recommendation.type}-${recommendation.to}`}
                            to={recommendation.to}
                            size="sm"
                            variant={recommendation.type === 'review' ? 'secondary' : 'dark'}
                            icon={recommendation.type === 'review' ? 'refresh' : 'refresh'}
                        >
                            {recommendation.label}
                        </ButtonLink>
                    ))}
                </div>
            ) : null}
        </Card>
    );
}

export default function QuizPage() {
    const { id } = useParams();
    const toast = useToast();

    const [quiz, setQuiz] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const [mode, setMode] = useState('intro'); // intro | running | result
    const [attempt, setAttempt] = useState(null); // { id, started_at, expires_at }
    const [questions, setQuestions] = useState([]);
    const [answers, setAnswers] = useState({});
    const [result, setResult] = useState(null);
    const [busy, setBusy] = useState(false);
    const [timeLeft, setTimeLeft] = useState(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [saveState, setSaveState] = useState('idle'); // idle | dirty | saving | saved | error
    const timerRef = useRef(null);
    const saveTimerRef = useRef(null);
    const answersRef = useRef({});
    // Read inside the unmount and visibilitychange handlers, which run outside
    // the render that closed the quiz. Without it a save would fire after the
    // final submit and come back as "already submitted".
    const runningRef = useRef(false);
    const autoSubmittedRef = useRef(false);

    runningRef.current = mode === 'running';
    answersRef.current = answers;

    useEffect(() => {
        api.get(`/api/quizzes/${id}`)
            .then(({ data }) => setQuiz(data.data))
            .catch((err) => setError(apiError(err)))
            .finally(() => setLoading(false));
    }, [id]);

    const clearTimer = useCallback(() => {
        if (timerRef.current) clearInterval(timerRef.current);
        timerRef.current = null;
    }, []);

    useEffect(() => () => clearTimer(), [clearTimer]);

    const start = async () => {
        setBusy(true);
        try {
            const { data } = await api.post(`/api/quizzes/${id}/start`);
            setAttempt({ id: data.attempt.id, expires_at: data.expires_at });
            setQuestions(data.questions ?? []);
            // A resumed attempt opens with its saved answers already in place.
            // Hydrating from the server rather than starting blank is the whole
            // point: a student who closed the tab mid-quiz gets their paper back.
            setAnswers(data.answers ?? {});
            setSaveState(data.resumed ? 'saved' : 'idle');
            autoSubmittedRef.current = false;
            setMode('running');

            if (data.resumed) toast('Resumed your attempt.', 'info');
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setBusy(false);
        }
    };

    const submit = useCallback(
        async (finalAnswers) => {
            setBusy(true);
            clearTimer();
            try {
                const payload = Object.entries(finalAnswers).map(([qid, answer]) => ({
                    question_id: Number(qid),
                    answer,
                }));
                const { data } = await api.post(`/api/quiz-attempts/${attempt.id}/submit`, { questions: payload });
                runningRef.current = false;
                setResult(data);
                setMode('result');
            } catch (err) {
                toast(apiError(err), 'error');
                setMode('intro');
            } finally {
                setBusy(false);
            }
        },
        [attempt, clearTimer, toast],
    );

    /**
     * Push every answer held in the browser to the server.
     *
     * The whole set rather than just what changed, because a draft row is keyed
     * by (attempt, question) so a re-save is idempotent, and resending is the
     * cheapest way to be sure the server holds the same paper the student sees.
     * The alternative -- tracking dirty ids -- saves a few hundred bytes and
     * loses answers every time a save races the next edit.
     */
    const flushAnswers = useCallback(async () => {
        if (saveTimerRef.current) {
            clearTimeout(saveTimerRef.current);
            saveTimerRef.current = null;
        }

        if (!runningRef.current || !attempt?.id) return;

        const payload = Object.entries(answersRef.current).map(([qid, answer]) => ({
            question_id: Number(qid),
            answer,
        }));

        setSaveState('saving');

        try {
            await api.patch(`/api/quiz-attempts/${attempt.id}/answers`, { answers: payload });
            setSaveState('saved');
        } catch {
            // Silent on purpose. The common failure is a save that raced the
            // final submit, which is not the student's problem and does not
            // deserve a toast. A real failure shows as the "not saved" hint, and
            // the submit path re-sends every answer anyway, so nothing is lost.
            setSaveState('error');
        }
    }, [attempt]);

    // The two moments a debounce cannot cover: the component going away, and the
    // tab being backgrounded or closed. Both are a normal part of taking a quiz,
    // and a lost debounce window in either is work the student would have to
    // redo by hand.
    useEffect(() => {
        const onHidden = () => {
            if (document.visibilityState === 'hidden') flushAnswers();
        };

        document.addEventListener('visibilitychange', onHidden);

        return () => {
            document.removeEventListener('visibilitychange', onHidden);
            flushAnswers();
        };
    }, [flushAnswers]);

    useEffect(() => {
        if (mode !== 'running' || !attempt?.expires_at) return;
        const tick = () => {
            const remaining = Math.floor((new Date(attempt.expires_at).getTime() - Date.now()) / 1000);
            setTimeLeft(remaining);

            // Hand the timer in rather than throwing the paper away. Sending
            // the student back to the overview discarded every answer and left
            // the attempt open, so they could start a fresh full-length one and
            // repeat until they passed. The server scores what was saved, so the
            // deadline is the end of their time and not the end of their work.
            if (remaining <= 0 && !autoSubmittedRef.current) {
                autoSubmittedRef.current = true;
                clearTimer();
                toast('Time is up — submitting your saved answers.', 'info');
                submit(answersRef.current);
            }
        };
        tick();
        timerRef.current = setInterval(tick, 1000);
        return clearTimer;
    }, [mode, attempt, clearTimer, submit, toast]);

    const setAnswer = (qid, value) => {
        setAnswers((prev) => ({ ...prev, [qid]: value }));
        setSaveState('dirty');

        if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
        saveTimerRef.current = setTimeout(flushAnswers, AUTOSAVE_DELAY_MS);
    };

    // Counted per question rather than by key count: an empty multi-select array
    // and a fill-in-the-blank with one blank left empty are both stored but not
    // answered, and both would otherwise let the student submit an incomplete quiz.
    const answered = questions.filter((question) => isAnswered(question, answers[question.id])).length;

    const resultSummary = useMemo(() => {
        if (!result?.attempt || !questions?.length) return null;
        const correct = result.questions.filter((q) => q.is_correct).length;
        return { correct, total: result.questions.length };
    }, [result, questions]);

    if (loading) {
        return <PageLoader label="Loading quiz…" />;
    }

    if (error || !quiz) {
        return <EmptyState icon="alert" title="Quiz unavailable" message={error || 'This quiz could not be loaded.'} />;
    }

    if (mode === 'result' && result) {
        return <QuizResult result={result} onClose={() => setMode('intro')} summary={resultSummary} quizTitle={quiz.title} quizId={quiz.id} />;
    }

    if (mode === 'running') {
        return (
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-bold text-slate-900">{quiz.title}</h1>
                    {timeLeft !== null ? (
                        <span
                            className={cx(
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold',
                                timeLeft < 60 ? 'bg-red-50 text-red-600' : 'bg-slate-900 text-white',
                            )}
                        >
                            <Icon name="clock" className="h-4 w-4" />
                            {formatTime(timeLeft)}
                        </span>
                    ) : null}
                </div>

                <div className="space-y-5">
                    {questions.map((question, index) => (
                        <Card key={question.id} className="p-5">
                            <div className="mb-3 flex items-start justify-between gap-3">
                                <p className="font-semibold text-slate-900">
                                    <span className="mr-1.5 text-brand-600">Q{index + 1}.</span>
                                    <QuestionText text={question.question_text} />
                                </p>
                                <Badge color="slate">{question.points} pt</Badge>
                            </div>
                            <QuestionInput
                                question={question}
                                value={answers[question.id]}
                                onChange={(value) => setAnswer(question.id, value)}
                            />
                        </Card>
                    ))}
                </div>

                <div className="flex items-center justify-between">
                    <p className="text-sm text-slate-500">
                        {answered} of {questions.length} answered
                        <span className="ml-2 text-slate-400">{saveLabel[saveState]}</span>
                    </p>
                    <Button
                        variant="dark"
                        loading={busy}
                        onClick={() => setConfirmOpen(true)}
                        disabled={answered < questions.length}
                        icon="check"
                    >
                        Submit quiz
                    </Button>
                </div>

                <ConfirmDialog
                    open={confirmOpen}
                    onClose={() => setConfirmOpen(false)}
                    title="Submit quiz?"
                    message={`You will submit ${answered} of ${questions.length} questions. You cannot change your answers after submitting.`}
                    confirmLabel="Submit quiz"
                    icon="check"
                    tone="primary"
                    loading={busy}
                    onConfirm={() => {
                        setConfirmOpen(false);
                        submit(answers);
                    }}
                />
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-2xl space-y-6">
            <div className="rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
                <div className="flex items-center gap-2">
                    <Badge color="blue">{quiz.course_id ? 'Course quiz' : 'Quiz'}</Badge>
                    {quiz.time_limit_minutes ? (
                        <Badge color="amber">
                            <Icon name="clock" className="h-3 w-3" /> {quiz.time_limit_minutes} min
                        </Badge>
                    ) : null}
                </div>
                <h1 className="mt-3 text-2xl font-bold text-slate-900">{quiz.title}</h1>
                {quiz.instructions ? <p className="mt-2 text-sm leading-relaxed text-slate-600">{quiz.instructions}</p> : null}
                {quiz.description ? <p className="mt-1 text-sm text-slate-500">{quiz.description}</p> : null}

                <dl className="mt-6 grid grid-cols-2 gap-4 border-t border-slate-100 pt-5 text-sm sm:grid-cols-3">
                    <div>
                        <dt className="text-xs uppercase tracking-wide text-slate-400">Questions</dt>
                        <dd className="mt-0.5 font-semibold text-slate-900">{quiz.questions_count ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs uppercase tracking-wide text-slate-400">Passing score</dt>
                        <dd className="mt-0.5 font-semibold text-slate-900">{quiz.passing_score}%</dd>
                    </div>
                    <div>
                        <dt className="text-xs uppercase tracking-wide text-slate-400">Attempts used</dt>
                        <dd className="mt-0.5 font-semibold text-slate-900">
                            {quiz.attempts_allowed ? `${quiz.attempts_used ?? 0} / ${quiz.attempts_allowed}` : 'Unlimited'}
                        </dd>
                    </div>
                </dl>

                {quiz.best_score !== null && quiz.best_score !== undefined ? (
                    <p className="mt-4 text-sm text-slate-500">
                        Best score: <span className="font-semibold text-slate-900">{quiz.best_score}%</span>{' '}
                        {quiz.has_passed ? <Badge color="green">Passed</Badge> : null}
                    </p>
                ) : null}

                <div className="mt-6 flex items-center justify-between gap-3">
                    {quiz.lesson?.course_slug ? (
                        <Link to={`/learn/${quiz.lesson.course_slug}`} className="text-sm font-medium text-brand-600 hover:text-brand-700">
                            ← Back to course
                        </Link>
                    ) : (
                        <span />
                    )}
                    <Button onClick={start} loading={busy} size="lg" icon="play">
                        {quiz.live_attempt ? 'Resume quiz' : 'Start quiz'}
                    </Button>
                </div>

                {quiz.live_attempt ? (
                    <p className="mt-4 rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-800">
                        You have an attempt in progress
                        {quiz.live_attempt.expires_at ? (
                            <>
                                {' '}
                                that closes{' '}
                                <span className="font-semibold">
                                    {new Date(quiz.live_attempt.expires_at).toLocaleTimeString([], {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </span>
                            </>
                        ) : null}
                        . Your answers are saved as you go, and resuming keeps the time you have left — it does not
                        restart the clock.
                    </p>
                ) : null}
            </div>
        </div>
    );
}

/**
 * A question that awards partial credit lands between full marks and nothing,
 * so a binary correct/incorrect badge would misreport it. Show the real number
 * whenever it is not all-or-nothing, and colour it by how close it got.
 */
function pointsBadgeLabel(question) {
    const earned = Number(question.points_earned ?? 0);
    const possible = Number(question.points ?? 0);

    if (earned <= 0) return `0 / ${possible} pts`;
    if (earned >= possible) return `+${earned} pts`;

    return `${earned} / ${possible} pts`;
}

function pointsBadgeColor(question) {
    const earned = Number(question.points_earned ?? 0);
    const possible = Number(question.points ?? 0);

    if (earned <= 0) return 'red';
    if (earned >= possible) return 'green';

    return 'amber';
}

function QuizResult({ result, quizTitle, quizId, onClose, summary }) {
    const attempt = result.attempt;
    const passed = Boolean(attempt.passed);

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <div
                className={cx(
                    'overflow-hidden rounded-xl border shadow-sm',
                    passed ? 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white dark:border-emerald-900/60 dark:from-emerald-950 dark:to-gray-900' : 'border-red-200 bg-gradient-to-br from-red-50 to-white dark:border-red-900/60 dark:from-red-950 dark:to-gray-900',
                )}
            >
                <div className="p-8 text-center">
                    <span
                        className={cx(
                            'mx-auto flex h-16 w-16 items-center justify-center rounded-full text-white dark:text-gray-50',
                            passed ? 'bg-emerald-500' : 'bg-red-500',
                        )}
                    >
                        <Icon name={passed ? 'checkCircle' : 'x'} className="h-8 w-8" />
                    </span>
                    <h1 className="mt-4 text-2xl font-bold text-slate-900">{passed ? 'Quiz passed!' : 'Quiz not passed'}</h1>
                    <p className="mt-1 text-sm text-slate-500">{quizTitle}</p>
                    <p className="mt-6 text-4xl font-extrabold text-slate-900">
                        {attempt.score_percentage !== null && attempt.score_percentage !== undefined ? `${attempt.score_percentage}%` : '—'}
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        Score {attempt.score ?? 0} / {attempt.quiz?.total_points ?? 0} points{summary ? ` · ${summary.correct} of ${summary.total} correct` : ''}
                    </p>
                    <div className="mt-4 text-sm text-slate-600">
                        {passed ? "Great work — this lesson is now marked complete." : `You need ${attempt.quiz?.passing_score ?? 0}% to pass.`}
                    </div>
                    <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
                        <Button variant="secondary" onClick={onClose}>
                            Back to quiz overview
                        </Button>
                        <Button variant="dark" onClick={() => window.location.assign(`/quiz/${quizId}`)}>
                            Retake quiz
                        </Button>
                    </div>
                </div>
            </div>

            {result.diagnosis ? <DiagnosisBlock diagnosis={result.diagnosis} /> : null}

            <div>
                <h2 className="mb-3 text-lg font-bold text-slate-900">Question review</h2>
                <div className="space-y-4">
                    {(result.questions ?? []).map((question, index) => (
                        <Card key={question.id} className="p-5">
                            <div className="flex items-start justify-between gap-3">
                                <p className="font-semibold text-slate-900">
                                    <span className="mr-1.5 text-brand-600">Q{index + 1}.</span>
                                    <QuestionText text={question.question_text} answers={question.submitted_answer} fallback="left blank" />
                                </p>
                                <Badge color={pointsBadgeColor(question)}>{pointsBadgeLabel(question)}</Badge>
                            </div>

                            <QuestionReview question={question} />
                            {question.explanation ? (
                                <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">{question.explanation}</p>
                            ) : null}
                        </Card>
                    ))}
                </div>
            </div>
        </div>
    );
}