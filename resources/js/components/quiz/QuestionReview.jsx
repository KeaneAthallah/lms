import { Badge, cx, Icon } from '../ui';
import { blankIndexes } from './questionTypes';

/**
 * The post-submit review for one question.
 *
 * The backend decides everything here: which options were chosen, which were
 * correct, and how many points were earned. This only renders what it is given,
 * so a type that grades partially shows a partial badge rather than a
 * wrong/correct binary it had to guess at.
 */

function OptionReview({ option }) {
    return (
        <div
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
    );
}

function TextReview({ label, value }) {
    const shown = value === null || value === undefined || value === '' ? '—' : String(value);

    return (
        <p className="mt-3 text-sm text-slate-600">
            {label}: <span className="font-semibold">{shown}</span>
        </p>
    );
}

function FillInBlankReview({ question }) {
    const submitted = question.submitted_answer ?? {};
    const blanks = blankIndexes(question.question_text);
    const filled = blanks.filter((index) => String(submitted[index] ?? '').trim() !== '');

    // The header already shows each blank inline with the student's answer, so
    // this only reports how complete the attempt was.
    return (
        <p className="mt-3 text-xs text-slate-500">
            {filled.length} of {blanks.length} filled
            {question.settings?.partial_credit ? ' · partial credit applies' : ''}
        </p>
    );
}

export default function QuestionReview({ question }) {
    if (question.type === 'multi_select' || question.type === 'multiple_choice' || question.type === 'true_false') {
        return (
            <div className="mt-3 space-y-1.5">
                {question.options.map((option) => (
                    <OptionReview key={option.id} option={option} />
                ))}
                {question.settings?.partial_credit && question.type === 'multi_select' ? (
                    <p className="text-xs text-slate-500">Partial credit applied for this question.</p>
                ) : null}
            </div>
        );
    }

    if (question.type === 'fill_in_blank') {
        return <FillInBlankReview question={question} />;
    }

    if (question.type === 'numeric') {
        return (
            <div className="mt-3 space-y-1">
                <TextReview label="Your answer" value={question.submitted_answer} />
            </div>
        );
    }

    return <TextReview label="Your answer" value={question.submitted_answer} />;
}
