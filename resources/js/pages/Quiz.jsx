import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { apiError } from '../api';
import { Badge, Button, ButtonLink, Card, ConfirmDialog, cx, EmptyState, Icon, Input, PageLoader, useToast } from '../components/ui';

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
    const timerRef = useRef(null);

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
            setAnswers({});
            setMode('running');
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

    useEffect(() => {
        if (mode !== 'running' || !attempt?.expires_at) return;
        const tick = () => {
            const remaining = Math.floor((new Date(attempt.expires_at).getTime() - Date.now()) / 1000);
            setTimeLeft(remaining);
            if (remaining <= 0) {
                clearTimer();
                setMode('intro');
                toast('Time is up for this attempt.', 'info');
            }
        };
        tick();
        timerRef.current = setInterval(tick, 1000);
        return clearTimer;
    }, [mode, attempt, clearTimer, toast]);

    const setAnswer = (qid, value) => setAnswers((prev) => ({ ...prev, [qid]: value }));

    const answered = Object.keys(answers).length;

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
                                    {question.question_text}
                                </p>
                                <Badge color="slate">{question.points} pt</Badge>
                            </div>
                            {question.type === 'short_answer' ? (
                                <Input
                                    value={answers[question.id] ?? ''}
                                    onChange={(e) => setAnswer(question.id, e.target.value)}
                                    placeholder="Type your answer…"
                                />
                            ) : (
                                <div className="space-y-2">
                                    {question.options.map((option) => (
                                        <label
                                            key={option.id}
                                            className={cx(
                                                'flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-2.5 text-sm transition',
                                                Number(answers[question.id]) === option.id
                                                    ? 'border-brand-500 bg-brand-50 text-brand-900'
                                                    : 'border-slate-200 text-slate-700 hover:border-brand-300',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name={`q-${question.id}`}
                                                checked={Number(answers[question.id]) === option.id}
                                                onChange={() => setAnswer(question.id, option.id)}
                                                className="h-4 w-4 accent-brand-600"
                                            />
                                            {option.option_text}
                                        </label>
                                    ))}
                                </div>
                            )}
                        </Card>
                    ))}
                </div>

                <div className="flex items-center justify-between">
                    <p className="text-sm text-slate-500">
                        {answered} of {questions.length} answered
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
                        Start quiz
                    </Button>
                </div>
            </div>
        </div>
    );
}

function QuizResult({ result, quizTitle, quizId, onClose, summary }) {
    const attempt = result.attempt;
    const passed = Boolean(attempt.passed);

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <div
                className={cx(
                    'overflow-hidden rounded-xl border shadow-sm',
                    passed ? 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white' : 'border-red-200 bg-gradient-to-br from-red-50 to-white',
                )}
            >
                <div className="p-8 text-center">
                    <span
                        className={cx(
                            'mx-auto flex h-16 w-16 items-center justify-center rounded-full text-white',
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
                                    {question.question_text}
                                </p>
                                <Badge color={question.is_correct ? 'green' : 'red'}>
                                    {question.is_correct ? `+${question.points_earned} pts` : `0 / ${question.points} pts`}
                                </Badge>
                            </div>

                            {question.type === 'short_answer' ? (
                                <p className="mt-3 text-sm text-slate-600">
                                    Your answer: <span className="font-semibold">{question.submitted_answer || '—'}</span>
                                </p>
                            ) : (
                                <div className="mt-3 space-y-1.5">
                                    {question.options.map((option) => (
                                        <div
                                            key={option.id}
                                            className={cx(
                                                'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                                                option.chosen && option.is_correct && 'border-emerald-300 bg-emerald-50 text-emerald-800',
                                                option.chosen && !option.is_correct && 'border-red-300 bg-red-50 text-red-800',
                                                !option.chosen && option.is_correct && 'border-emerald-200 bg-emerald-50/50 text-emerald-700',
                                                !option.chosen && !option.is_correct && 'border-slate-200 text-slate-600',
                                            )}
                                        >
                                            {option.is_correct ? (
                                                <Icon name="check" className="h-4 w-4 text-emerald-500" strokeWidth={2.5} />
                                            ) : option.chosen ? (
                                                <Icon name="x" className="h-4 w-4 text-red-500" strokeWidth={2.5} />
                                            ) : (
                                                <span className="h-4 w-4" />
                                            )}
                                            <span className="flex-1">{option.option_text}</span>
                                            {option.chosen ? <Badge color={option.is_correct ? 'green' : 'red'}>Your answer</Badge> : null}
                                        </div>
                                    ))}
                                </div>
                            )}
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