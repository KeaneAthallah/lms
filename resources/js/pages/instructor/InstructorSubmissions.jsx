import { useCallback, useEffect, useState } from 'react';
import api, { apiError } from '../../api';
import { Avatar, Button, Card, EmptyState, Field, Icon, Input, Modal, PageHeader, PageLoader, Paginator, Select, StatusBadge, Textarea, useToast } from '../../components/ui';

export default function InstructorSubmissions() {
    const slug = location.pathname.split('/')[3];
    const toast = useToast();

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [grading, setGrading] = useState(null);

    const load = useCallback(() => {
        setLoading(true);
        api.get(`/api/instructor/courses/${slug}/submissions`, { params: { search: debounced, status, page } })
            .then(({ data }) => setData(data))
            .catch(() => toast('Could not load submissions.', 'error'))
            .finally(() => setLoading(false));
    }, [slug, debounced, status, page, toast]);

    useEffect(() => {
        const t = setTimeout(() => setDebounced(search), 350);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        load();
    }, [load]);

    const submitted = data?.submissions ?? [];

    return (
        <div className="space-y-6">
            <PageHeader
                title="Submissions"
                subtitle={`Grade student work — ${data?.pending_count ?? 0} awaiting grading.`}
                actions={
                    <div className="flex items-center gap-2">
                        <Input
                            value={search}
                            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                            placeholder="Search student…"
                            className="w-56"
                        />
                        <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="w-44">
                            <option value="">All submissions</option>
                            <option value="pending">Pending ({data?.pending_count ?? 0})</option>
                            <option value="graded">Graded ({data?.graded_count ?? 0})</option>
                        </Select>
                    </div>
                }
            />

            {loading ? (
                <PageLoader label="Loading submissions…" />
            ) : !submitted.length ? (
                <EmptyState icon="clipboard" title="No submissions" message="Submitted work will appear here for grading." />
            ) : (
                <Card className="divide-y divide-slate-100 p-0">
                    {submitted.map((s) => (
                        <SubmissionRow key={s.id} submission={s} onGrade={() => setGrading(s)} />
                    ))}
                </Card>
            )}

            <Paginator meta={data?.meta} onPage={setPage} />

            {grading ? (
                <GradeModal
                    submission={grading}
                    courseSlug={slug}
                    onClose={() => setGrading(null)}
                    onGraded={() => { setGrading(null); load(); }}
                />
            ) : null}
        </div>
    );
}

function SubmissionRow({ submission, onGrade }) {
    const graded = submission.status === 'graded';

    return (
        <div className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-center gap-3">
                <Avatar name={submission.student?.name} />
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="truncate font-medium text-slate-900">{submission.student?.name}</span>
                        <StatusBadge status={graded ? 'graded' : 'pending'} />
                        <span className="text-xs text-slate-400">
                            v{submission.version}
                        </span>
                    </div>
                    <p className="truncate text-sm text-slate-500">
                        {submission.assignment?.title}
                        {graded ? ` · ${submission.grade}/${submission.assignment?.max_score}` : ''}
                    </p>
                </div>
            </div>
            <div className="flex items-center gap-3">
                <span className="hidden text-xs text-slate-400 sm:block">
                    {submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : ''}
                </span>
                <Button size="sm" icon={graded ? 'eye' : 'clipboard'} onClick={onGrade}>
                    {graded ? 'Review' : 'Grade'}
                </Button>
            </div>
        </div>
    );
}

function GradeModal({ submission, courseSlug, onClose, onGraded }) {
    const toast = useToast();
    const [grade, setGrade] = useState(submission.grade ?? '');
    const [feedback, setFeedback] = useState(submission.feedback ?? '');
    const [saving, setSaving] = useState(false);
    const maxScore = submission.assignment?.max_score ?? 100;

    const submit = async () => {
        setSaving(true);
        try {
            await api.post(`/api/instructor/courses/${courseSlug}/submissions/${submission.id}/grade`, {
                grade: Number(grade),
                feedback,
            });
            toast('Submission graded.', 'success');
            onGraded();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title="Grade submission"
            size="lg"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button loading={saving} icon="check" onClick={submit} disabled={grade === '' || Number(grade) < 0}>
                        {submission.status === 'graded' ? 'Save changes' : 'Submit grade'}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="flex items-center gap-3 rounded-lg bg-slate-50 p-4">
                    <Avatar name={submission.student?.name} />
                    <div>
                        <p className="font-semibold text-slate-900">{submission.student?.name}</p>
                        <p className="text-sm text-slate-500">
                            {submission.assignment?.title} · max {maxScore} pts
                        </p>
                    </div>
                    <a
                        className="ml-auto flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
                        href={`/api/instructor/courses/${courseSlug}/submissions/${submission.id}`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <Icon name="upload" className="h-4 w-4" />
                        Detail
                    </a>
                </div>

                {submission.content ? (
                    <div className="rounded-lg border border-slate-200 p-4">
                        <p className="mb-1 text-xs font-semibold uppercase text-slate-400">Student notes</p>
                        <p className="whitespace-pre-wrap text-sm text-slate-700">{submission.content}</p>
                    </div>
                ) : null}

                {submission.files?.length ? (
                    <div>
                        <p className="mb-2 text-xs font-semibold uppercase text-slate-400">Attached files</p>
                        <ul className="space-y-1.5">
                            {submission.files.map((file) => (
                                <li key={file.index}>
                                    <a
                                        href={`/api/instructor/courses/${courseSlug}/submissions/${submission.id}/files/${file.index}`}
                                        className="flex items-center gap-2 text-sm font-medium text-brand-600 hover:underline"
                                    >
                                        <Icon name="upload" className="h-4 w-4" />
                                        {file.name}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-[120px_1fr]">
                    <Field label={`Grade / ${maxScore}`} required hint="Out of the max score.">
                        <Input
                            type="number"
                            min="0"
                            max={maxScore}
                            step="0.1"
                            value={grade}
                            onChange={(e) => setGrade(e.target.value)}
                        />
                    </Field>
                    <Field label="Feedback">
                        <Textarea value={feedback} onChange={(e) => setFeedback(e.target.value)} className="min-h-24" />
                    </Field>
                </div>
            </div>
        </Modal>
    );
}