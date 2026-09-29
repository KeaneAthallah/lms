import { useEffect, useState } from 'react';
import api, { apiError } from '../../api';
import { Badge, Button, EmptyState, Modal, PageLoader, formatDateTime, useToast } from '../../components/ui';

const actionLabels = {
    override: 'Score changed',
    clear_override: 'Score restored',
    drop: 'Excluded from grade',
    restore: 'Included again',
    annotate: 'Note added',
};

const actionColors = {
    override: 'amber',
    clear_override: 'slate',
    drop: 'red',
    restore: 'green',
    annotate: 'blue',
};

/**
 * Who changed which grade, and when.
 *
 * Adjustments are stored rather than applied in place, so this list is the record
 * a disputed mark is settled from: it has to name the assessment, the student, the
 * instructor who decided, and what the grade became.
 */
export default function GradeAdjustmentHistoryModal({ open, onClose, courseSlug }) {
    const toast = useToast();
    const [entries, setEntries] = useState(null);

    useEffect(() => {
        if (!open) return;
        setEntries(null);
        api
            .get(`/api/instructor/courses/${courseSlug}/gradebook/adjustments`)
            .then(({ data }) => setEntries(data.adjustments ?? []))
            .catch(() => {
                setEntries([]);
                toast('Could not load the adjustment history.', 'error');
            });
    }, [open, courseSlug, toast]);

    return (
        <Modal open={open} onClose={onClose} title="Adjustment history" size="lg" footer={<Button variant="secondary" onClick={onClose}>Close</Button>}>
            {entries === null ? (
                <PageLoader label="Loading history…" />
            ) : !entries.length ? (
                <EmptyState
                    icon="clock"
                    title="No adjustments yet"
                    message="Grades change on their own as students submit. Adjustments are the changes an instructor makes on top of them."
                />
            ) : (
                <ul className="space-y-2">
                    {entries.map((entry) => (
                        <li key={entry.id} className="rounded-lg border border-slate-200 px-4 py-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge color={actionColors[entry.action] ?? 'slate'}>{actionLabels[entry.action] ?? entry.action}</Badge>
                                <span className="text-sm font-medium text-slate-900">{entry.student?.name}</span>
                                <span className="text-sm text-slate-500">{entry.assessment?.title}</span>
                            </div>
                            <p className="mt-1.5 text-sm text-slate-600">{entry.summary}</p>
                            {entry.note ? <p className="mt-1 text-sm italic text-slate-500">“{entry.note}”</p> : null}
                            <p className="mt-1.5 text-xs text-slate-400">
                                {entry.adjuster?.name ?? 'Unknown'} · {formatDateTime(entry.adjusted_at)}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </Modal>
    );
}
