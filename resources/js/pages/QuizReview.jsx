import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api, { apiError } from '../api';
import { Button, EmptyState, Icon, PageLoader } from '../components/ui';
import { QuizResult } from './Quiz';

/**
 * A durable copy of one attempt's review.
 *
 * The review on the quiz page only exists in memory: leave after submitting and
 * it is gone, so there was no way to go back and read an explanation a week
 * later. This page renders the same report for any graded attempt, loaded on
 * demand, and is where the overview's attempt history points.
 */
export default function QuizReview() {
    const { attemptId } = useParams();
    const navigate = useNavigate();

    const [result, setResult] = useState(null);
    const [quizId, setQuizId] = useState(null);
    const [quizTitle, setQuizTitle] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get(`/api/quiz-attempts/${attemptId}`)
            .then(({ data }) => {
                setResult(data);
                setQuizId(data.attempt?.quiz?.id ?? null);
                setQuizTitle(data.attempt?.quiz?.title ?? '');
            })
            .catch((err) => setError(apiError(err)))
            .finally(() => setLoading(false));
    }, [attemptId]);

    if (loading) return <PageLoader label="Loading review…" />;

    if (error || !result) {
        return (
            <div className="mx-auto max-w-2xl space-y-6">
                <EmptyState
                    icon="alert"
                    title="Review unavailable"
                    message={error || 'This attempt could not be found.'}
                />
                {quizId ? (
                    <div className="flex justify-center">
                        <Button variant="secondary" onClick={() => navigate(`/quiz/${quizId}`)}>
                            Back to quiz overview
                        </Button>
                    </div>
                ) : (
                    <div className="flex justify-center gap-1.5 text-sm text-slate-500">
                        <Icon name="alert" className="h-4 w-4" />
                        <span>You may still have an attempt in progress — go back to the quiz to resume it.</span>
                    </div>
                )}
            </div>
        );
    }

    const summary = useMemo(() => {
        if (!result?.attempt || !result?.questions?.length) return null;
        const correct = result.questions.filter((q) => q.is_correct).length;
        return { correct, total: result.questions.length };
    }, [result]);

    return (
        <QuizResult
            result={result}
            quizId={quizId}
            quizTitle={quizTitle}
            summary={summary}
            overviewHref={quizId ? `/quiz/${quizId}` : null}
            showRetake={false}
        />
    );
}