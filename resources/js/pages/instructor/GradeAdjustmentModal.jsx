import { useEffect, useMemo, useState } from 'react';
import api, { apiError } from '../../api';
import { Alert, Button, Field, Input, Modal, Textarea, useToast } from '../../components/ui';

/**
 * One instructor decision about one grade: enter a different score, exclude the
 * grade from the averages, or leave a note explaining either.
 *
 * The recorded score is shown next to the inputs and is never an editable field.
 * A grade an instructor has changed is a grade whose report and its evidence
 * disagree, and a student contesting the mark needs the graded result to still be
 * there — so the adjustment sits on top of the ledger rather than replacing it.
 * Empty inputs therefore mean "use the recorded score", not "no score".
 */
export default function GradeAdjustmentModal({ open, onClose, onSaved, courseSlug, student, assessment, cell }) {
    const toast = useToast();
    const [score, setScore] = useState('');
    const [maxScore, setMaxScore] = useState('');
    const [dropped, setDropped] = useState(false);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!open) return;
        setScore(cell?.overridden ? String(cell.score) : '');
        setMaxScore('');
        setDropped(Boolean(cell?.dropped));
        setNote(cell?.note ?? '');
        setBusy(false);
        setError(null);
    }, [open, cell]);

    const isDirty = useMemo(() => {
        if (score.trim() === '') return dropped !== Boolean(cell?.dropped) || note.trim() !== (cell?.note ?? '').trim();
        return (
            Number(score) !== Number(cell?.score) ||
            (maxScore.trim() !== '' && Number(maxScore) !== Number(cell?.max_score)) ||
            dropped !== Boolean(cell?.dropped) ||
            note.trim() !== (cell?.note ?? '').trim()
        );
    }, [score, maxScore, dropped, note, cell]);

    const preview = useMemo(() => {
        if (dropped) return null;
        const effectiveMax = maxScore.trim() === '' ? Number(cell?.max_score) : Number(maxScore);
        const effectiveScore = score.trim() === '' ? Number(cell?.recorded_score) : Number(score);
        if (!Number.isFinite(effectiveMax) || !Number.isFinite(effectiveScore) || effectiveMax <= 0) return null;
        return Math.round((effectiveScore / effectiveMax) * 1000) / 10;
    }, [dropped, score, maxScore, cell]);

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            await api.put(`/api/instructor/courses/${courseSlug}/gradebook/grades/${cell.id}`, {
                score: score.trim() === '' ? null : Number(score),
                max_score: maxScore.trim() === '' ? null : Number(maxScore),
                dropped,
                note: note.trim() === '' ? null : note.trim(),
            });
            toast('Grade updated.', 'success');
            await onSaved();
            onClose();
        } catch (err) {
            setError(apiError(err));
        } finally {
            setBusy(false);
        }
    };

    if (!open) return null;

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Adjust grade"
            size="md"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save} loading={busy} disabled={!isDirty}>
                        Save
                    </Button>
                </>
            }
        >
            <div className="space-y-5">
                <div className="rounded-lg bg-slate-50 px-4 py-3">
                    <p className="text-sm font-semibold text-slate-900">{student?.name}</p>
                    <p className="text-xs text-slate-500">
                        {assessment?.title} · recorded {cell?.recorded_score} of {cell?.recorded_max_score} ({cell?.recorded_percentage}%)
                    </p>
                </div>

                {error ? <Alert tone="danger">{error}</Alert> : null}

                <div className="grid grid-cols-2 gap-3">
                    <Field
                        label="Score"
                        hint={preview !== null ? `Counts as ${preview}%` : 'Leave empty to use the recorded score.'}
                    >
                        <Input
                            type="number"
                            step="any"
                            min="0"
                            value={score}
                            onChange={(e) => setScore(e.target.value)}
                            placeholder={String(cell?.recorded_score ?? '')}
                            disabled={dropped}
                        />
                    </Field>
                    <Field label="Out of" hint={maxScore.trim() === '' ? `Defaults to ${cell?.max_score}` : undefined}>
                        <Input
                            type="number"
                            step="any"
                            min="0"
                            value={maxScore}
                            onChange={(e) => setMaxScore(e.target.value)}
                            placeholder={String(cell?.max_score ?? '')}
                            disabled={dropped}
                        />
                    </Field>
                </div>

                <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 px-4 py-3">
                    <input
                        type="checkbox"
                        checked={dropped}
                        onChange={(e) => setDropped(e.target.checked)}
                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-700 focus:ring-slate-400"
                    />
                    <span>
                        <span className="block text-sm font-medium text-slate-800">Exclude from course grade</span>
                        <span className="block text-xs text-slate-500">
                            The grade stays visible here, but stops counting towards the course grade, the class average, and its
                            category. Use this for a grade that should not count rather than changing the score.
                        </span>
                    </span>
                </label>

                <Field label="Note" hint="Shown in the adjustment history, and kept with the decision.">
                    <Textarea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder="Why this change was made."
                        className="min-h-20"
                    />
                </Field>

                {cell?.overridden ? (
                    <Alert tone="info">
                        This grade is currently reported as {cell.score} of {cell.max_score} ({cell.percentage}%), replacing the
                        recorded {cell.recorded_score}. Clear the score above to go back to the recorded result.
                    </Alert>
                ) : null}
            </div>
        </Modal>
    );
}
