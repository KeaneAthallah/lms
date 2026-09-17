import { useEffect, useState } from 'react';
import api from '../api';
import { Alert, Badge, Button, ButtonLink, EmptyState, Icon, PageHeader, PageLoader, Section } from '../components/ui';

function ChallengeQuiz({ challenge, onReset }) {
    const [selected, setSelected] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [result, setResult] = useState(null);

    const handleSubmit = () => {
        setSubmitting(true);
        api.post('/api/learning/challenge/submit', {
            questions: challenge.questions.map((q) => q.id),
            answers: Object.entries(selected).map(([question_id, answer]) => ({ question_id: Number(question_id), answer })),
        })
            .then(({ data }) => setResult(data.data))
            .finally(() => setSubmitting(false));
    };

    if (result) {
        return (
            <Section
                title="Challenge results"
                icon="trophy"
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Badge color={result.percent >= 75 ? 'green' : result.percent >= 60 ? 'amber' : 'red'} dot>
                            {result.correct_count}/{result.total} correct
                        </Badge>
                        <Badge color="brand" dot>{result.percent}%</Badge>
                    </div>
                }
            >
                <p className="px-5 pt-4 text-sm leading-relaxed text-slate-600">{result.summary}</p>

                <ul className="mt-2 divide-y divide-slate-100">
                    {result.questions.map((q) => (
                        <li key={q.id} className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:gap-4">
                            <span
                                className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ${
                                    q.is_correct ? 'bg-emerald-500' : 'bg-red-500'
                                }`}
                            >
                                {q.is_correct ? '✓' : '✕'}
                            </span>
                            <div className="min-w-0 flex-1">
                                <h3 className="font-semibold text-slate-900">{q.question_text}</h3>
                                <p className="mt-1 text-sm text-slate-600">
                                    <span className="font-semibold text-slate-500">Your answer:</span> {q.submitted_answer_text ?? '—'}
                                </p>
                                {!q.is_correct ? (
                                    <p className="mt-0.5 text-sm text-slate-600">
                                        <span className="font-semibold text-emerald-600">Correct answer:</span> {q.correct_answer_text}
                                    </p>
                                ) : null}
                                {q.explanation ? (
                                    <p className="mt-1.5 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">{q.explanation}</p>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>

                <div className="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-4">
                    <Button onClick={onReset} icon="refresh">New challenge</Button>
                    <ButtonLink to="/learning-map" variant="secondary" icon="compass">Back to learning map</ButtonLink>
                </div>
            </Section>
        );
    }

    return (
        <Section
            title="Practice challenge"
            icon="cpu"
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Badge color="violet" dot>{challenge.concept}</Badge>
                    <span className="text-xs font-medium text-slate-400">Mastery {challenge.mastery_percent}%</span>
                </div>
            }
        >
            <p className="px-5 pt-4 text-sm leading-relaxed text-slate-600">
                {challenge.total_questions} real questions from the “{challenge.concept}” concept in <strong>{challenge.course.title}</strong>.
                Pick an answer for each question — this is practice only and never affects your grade.
            </p>

            <ul className="mt-2 divide-y divide-slate-100">
                {challenge.questions.map((question) => (
                    <li key={question.id} className="px-5 py-4">
                        <h3 className="font-semibold text-slate-900">
                            <span className="mr-1.5 text-slate-400">
                                {challenge.questions.indexOf(question) + 1}.
                            </span>
                            {question.question_text}
                        </h3>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {question.options.map((option) => {
                                const active = selected[question.id] === option.id;

                                return (
                                    <button
                                        key={option.id}
                                        type="button"
                                        onClick={() => setSelected((prev) => ({ ...prev, [question.id]: option.id }))}
                                        className={`flex items-center gap-3 rounded-xl border px-4 py-2.5 text-left text-sm transition ${
                                            active
                                                ? 'border-violet-400 bg-violet-50 text-violet-900'
                                                : 'border-slate-200 bg-white text-slate-700 hover:border-violet-200 hover:bg-violet-50/40'
                                        }`}
                                    >
                                        <span
                                            className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border ${
                                                active ? 'border-violet-500 bg-violet-500 text-white' : 'border-slate-300'
                                            }`}
                                        >
                                            {active ? <Icon name="check" className="h-3 w-3" /> : null}
                                        </span>
                                        {option.option_text}
                                    </button>
                                );
                            })}
                        </div>
                    </li>
                ))}
            </ul>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4">
                <p className="text-xs text-slate-400">Answering {Object.keys(selected).length} of {challenge.total_questions} so far.</p>
                <Button onClick={handleSubmit} loading={submitting} disabled={Object.keys(selected).length < challenge.total_questions} icon="checkCircle">
                    Submit challenge
                </Button>
            </div>
        </Section>
    );
}

export default function ChallengePage() {
    const [challenge, setChallenge] = useState(null);
    const [nonce, setNonce] = useState(0);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setLoading(true);
        api.get('/api/learning/challenge')
            .then(({ data }) => setChallenge(data.data))
            .catch(() => setFailed(true))
            .finally(() => setLoading(false));
    }, [nonce]);

    if (loading) return <PageLoader label="Preparing your challenge…" />;

    if (failed) {
        return (
            <EmptyState
                icon="cpu"
                title="Challenge unavailable"
                message="We couldn't prepare a challenge right now. Please try again in a moment."
            />
        );
    }

    return (
        <div className="space-y-6">
            <PageHeader
                icon="cpu"
                title="Challenge mode"
                subtitle="A short, fast practice session drawn from a concept you've already mastered. No marks, no pressure — just reps."
            />

            {challenge === null ? (
                <EmptyState
                    icon="trophy"
                    title="No challenge available yet"
                    message="Master at least one concept first — complete its lessons and earn a strong quiz result — then challenges will appear here."
                    action={<ButtonLink to="/learning-map" icon="compass">Open learning map</ButtonLink>}
                />
            ) : (
                <ChallengeQuiz key={nonce} challenge={challenge} onReset={() => setNonce((prev) => prev + 1)} />
            )}

            <Alert tone="info" icon="shieldCheck" title="Practice only">
                This challenge is generated from the platform's real quiz questions and is graded in memory. It never creates an
                attempt, never changes a grade, and never affects your quiz history — it's a purely optional warm-up.
            </Alert>
        </div>
    );
}