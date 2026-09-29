import { useEffect, useMemo, useState } from 'react';
import api, { apiError } from '../../api';
import { Button, ConfirmDialog, Field, Input, Modal, Select, useToast } from '../../components/ui';

/**
 * Gradebook settings: the course's weighted buckets and which assessment sits
 * in which one.
 *
 * Categories save on their own button, so an assessment's dropdown only ever
 * lists buckets that already exist — adding a bucket first is the natural order
 * and never leaves a select pointed at an id the app has not got back yet.
 */
export default function GradebookCategoriesModal({ open, onClose, onSaved, courseSlug, categories = [], assessments = [] }) {
    const toast = useToast();
    const [rows, setRows] = useState([]);
    const [selections, setSelections] = useState({});
    const [adding, setAdding] = useState({ name: '', weight: '1' });
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState(null);

    useEffect(() => {
        if (!open) return;
        setRows((categories ?? []).map((c) => ({ ...c, name: c.name, weight: String(c.weight), dirty: false })));
        setSelections(Object.fromEntries((assessments ?? []).map((a) => [a.key, a.category_key ?? 'uncategorized'])));
        setAdding({ name: '', weight: '1' });
        setConfirm(null);
    }, [open, categories, assessments]);

    const setRow = (index, patch) =>
        setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch, dirty: true } : row)));

    const addNew = () => {
        const name = adding.name.trim();
        if (!name) return;
        setRows((prev) => [...prev, { key: null, id: null, name, weight: adding.weight, dirty: true }]);
        setAdding({ name: '', weight: '1' });
    };

    const created = useMemo(() => rows.filter((row) => row.id === null && row.name.trim()), [rows]);
    const edited = useMemo(() => rows.filter((row) => row.id !== null && row.dirty), [rows]);

    const loadWith = async () => {
        await onSaved();
    };

    const saveCategories = async () => {
        setBusy(true);
        try {
            for (const row of created) {
                await api.post(`/api/instructor/courses/${courseSlug}/gradebook-categories`, {
                    name: row.name.trim(),
                    weight: row.weight,
                });
            }
            for (const row of edited) {
                await api.put(`/api/instructor/courses/${courseSlug}/gradebook-categories/${row.id}`, {
                    name: row.name.trim(),
                    weight: row.weight,
                });
            }
            toast(created.length || edited.length ? 'Categories saved.' : 'No category changes to save.', 'success');
            await loadWith();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setBusy(false);
        }
    };

    const saveAssignments = async () => {
        setBusy(true);
        try {
            const payload = Object.entries(selections).map(([key, categoryKey]) => ({
                key,
                category_id: categoryKey && categoryKey !== 'uncategorized' ? Number(categoryKey.replace('category-', '')) : null,
            }));
            await api.patch(`/api/instructor/courses/${courseSlug}/gradebook-categories/assign`, { selections: payload });
            toast('Assessments assigned.', 'success');
            await loadWith();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setBusy(false);
        }
    };

    const deleteCategory = async (row) => {
        setBusy(true);
        try {
            await api.delete(`/api/instructor/courses/${courseSlug}/gradebook-categories/${row.id}`);
            toast('Category deleted.', 'success');
            setConfirm(null);
            await loadWith();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Gradebook settings"
            size="lg"
            footer={<Button variant="secondary" onClick={onClose}>Done</Button>}
        >
            <div className="space-y-6">
                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-bold text-slate-900">Categories &amp; weights</h3>
                        <Button variant="secondary" size="sm" onClick={saveCategories} loading={busy} disabled={!created.length && !edited.length}>
                            Save categories
                        </Button>
                    </div>

                    <div className="space-y-2">
                        {rows.map((row, index) => (
                            <div key={row.id ?? `new-${index}`} className="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2">
                                <Input value={row.name} onChange={(e) => setRow(index, { name: e.target.value })} className="flex-1" placeholder="Category name" />
                                <Field label="" className="w-24">
                                    <Input type="number" min="0" max="100" value={row.weight} onChange={(e) => setRow(index, { weight: e.target.value })} />
                                </Field>
                                <span className="text-xs text-slate-400">%</span>
                                {/* New rows are not real buckets yet; deleting them just drops the draft. */}
                                {row.id !== null ? (
                                    <button
                                        type="button"
                                        className="rounded-md p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                        aria-label="Delete category"
                                        onClick={() => setConfirm({ title: 'Delete this category?', message: `"${row.name}" will be removed from the gradebook.`, action: () => deleteCategory(row) })}
                                    >
                                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /><path d="M10 11v6" /><path d="M14 11v6" /></svg>
                                        </button>
                                    ) : null}
                            </div>
                        ))}

                        <div className="flex items-center gap-3 rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-3 py-2">
                            <Input value={adding.name} onChange={(e) => setAdding((f) => ({ ...f, name: e.target.value }))} className="flex-1" placeholder="New category name" onKeyDown={(e) => e.key === 'Enter' && addNew()} />
                            <Input type="number" min="0" max="100" value={adding.weight} onChange={(e) => setAdding((f) => ({ ...f, weight: e.target.value }))} className="w-24" />
                            <span className="text-xs text-slate-400">%</span>
                            <Button variant="secondary" size="sm" icon="plus" onClick={addNew} disabled={!adding.name.trim()}>
                                Add
                            </Button>
                        </div>
                    </div>
                </div>

                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-bold text-slate-900">Where each assessment sits</h3>
                        <Button variant="secondary" size="sm" onClick={saveAssignments} loading={busy}>
                            Save assignments
                        </Button>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-slate-200">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Assessment</th>
                                    <th className="px-3 py-2 font-semibold">Category</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {(assessments ?? []).map((assessment) => (
                                    <tr key={assessment.key}>
                                        <td className="px-3 py-2">
                                            <span className="mr-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-500">
                                                {assessment.type}
                                            </span>
                                            {assessment.title}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Select value={selections[assessment.key] ?? 'uncategorized'} onChange={(e) => setSelections((prev) => ({ ...prev, [assessment.key]: e.target.value }))}>
                                                {categories.map((cat) => (
                                                    <option key={cat.key} value={cat.key}>{cat.name}</option>
                                                ))}
                                                <option value="uncategorized">Uncategorized</option>
                                            </Select>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {confirm ? (
                <ConfirmDialog
                    open
                    onClose={() => setConfirm(null)}
                    title={confirm.title}
                    message={confirm.message}
                    confirmLabel="Delete"
                    icon="trash"
                    tone="danger"
                    loading={busy}
                    onConfirm={() => confirm.action()}
                />
            ) : null}
        </Modal>
    );
}