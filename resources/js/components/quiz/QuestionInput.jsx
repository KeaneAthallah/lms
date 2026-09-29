import { Input, cx } from '../ui';
import { blankIndexes } from './questionTypes';
import RubricGuide from './RubricGuide';

/**
 * Renders the answer control for one question.
 *
 * The switch lives here rather than in the page so that adding a question type
 * is one case in one file, instead of a further branch through the running-quiz
 * view, the review view, and the "x of y answered" counter.
 *
 * Answer state is keyed by question id and holds whatever shape the type needs:
 * an option id, a string, an array of option ids, or a blank-index map. The
 * backend grader is the authority on shape; this file only has to collect it.
 */

function OptionRow({ option, selected, onSelect, multiple }) {
    return (
        <label
            className={cx(
                'flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-2.5 text-sm transition',
                selected
                    ? 'border-brand-500 bg-brand-50 text-brand-900'
                    : 'border-slate-200 text-slate-700 hover:border-brand-300',
            )}
        >
            <input
                type={multiple ? 'checkbox' : 'radio'}
                name={`q-${option.questionId}`}
                checked={selected}
                onChange={() => onSelect(option.id)}
                className="h-4 w-4 accent-brand-600"
            />
            {option.option_text}
        </label>
    );
}

function ChoiceInput({ question, value, onChange, multiple }) {
    const selectedIds = multiple ? (Array.isArray(value) ? value : []) : [value].filter((v) => v !== undefined);

    return (
        <div className="space-y-2">
            {question.options.map((option) => (
                <OptionRow
                    key={option.id}
                    questionId={question.id}
                    option={option}
                    multiple={multiple}
                    selected={selectedIds.map(Number).includes(Number(option.id))}
                    onSelect={(optionId) => {
                        if (!multiple) {
                            onChange(optionId);
                            return;
                        }

                        const id = Number(optionId);
                        const current = Array.isArray(value) ? value.map(Number) : [];
                        onChange(current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);
                    }}
                />
            ))}
        </div>
    );
}

function FillInBlankInput({ question, value, onChange }) {
    const blanks = blankIndexes(question.question_text);
    const answers = value ?? {};

    // The sentence itself is rendered once, in the question header, with each
    // blank shown as an underline. Repeating it here would print the author's
    // `{{1}}` markers twice.
    return (
        <div className="space-y-2">
            {blanks.map((blank) => (
                <div key={blank} className="flex items-center gap-3">
                    <span className="w-24 shrink-0 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Blank {blank}
                    </span>
                    <Input
                        value={answers[blank] ?? ''}
                        onChange={(e) => onChange({ ...answers, [blank]: e.target.value })}
                        placeholder="Your answer…"
                        aria-label={`Answer for blank ${blank}`}
                    />
                </div>
            ))}
        </div>
    );
}

export default function QuestionInput({ question, value, onChange }) {
    switch (question.type) {
        case 'short_answer':
            return (
                <div>
                    <Input
                        value={value ?? ''}
                        onChange={(e) => onChange(e.target.value)}
                        placeholder="Type your answer…"
                    />
                    <RubricGuide rubric={question.settings?.rubric} />
                </div>
            );

        case 'numeric':
            return (
                <Input
                    type="number"
                    step="any"
                    inputMode="decimal"
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="Enter a number…"
                />
            );

        case 'multi_select':
            return <ChoiceInput question={question} value={value} onChange={onChange} multiple />;

        case 'fill_in_blank':
            return <FillInBlankInput question={question} value={value} onChange={onChange} />;

        default:
            return <ChoiceInput question={question} value={value} onChange={onChange} multiple={false} />;
    }
}
