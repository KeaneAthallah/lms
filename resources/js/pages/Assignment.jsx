import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { apiError } from '../api';
import { Badge, Button, Card, cx, EmptyState, formatBytes, formatDate, Icon, PageLoader, Textarea, useToast } from '../components/ui';

export default function AssignmentPage() {
    const { id } = useParams();
    const toast = useToast();

    const [assignment, setAssignment] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [content, setContent] = useState('');
    const [files, setFiles] = useState([]);
    const [busy, setBusy] = useState(false);
    const [submission, setSubmission] = useState(null);
    const [fileError, setFileError] = useState('');
    const inputRef = useRef(null);

    const load = useCallback(() => {
        setLoading(true);
        api.get(`/api/assignments/${id}`)
            .then(({ data }) => {
                setAssignment(data.data);
                setSubmission(data.data.my_submission ?? null);
                setContent(data.data.my_submission?.content ?? '');
            })
            .catch((err) => setError(apiError(err)))
            .finally(() => setLoading(false));
    }, [id]);

    useEffect(() => {
        load();
    }, [load]);

    const addFiles = (list) => {
        setFileError('');
        const allowed = assignment?.allowed_file_types ?? [];
        if (allowed.length && [...list].some((f) => !allowed.includes(f.name.split('.').pop().toLowerCase()))) {
            setFileError(`Allowed file types: ${allowed.join(', ')}`);
            return;
        }
        setFiles((prev) => [...prev, ...list].slice(0, 5));
    };

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setFileError('');
        const formData = new FormData();
        formData.append('content', content);
        files.forEach((file) => formData.append('files[]', file));

        try {
            await api.post(`/api/assignments/${id}/submit`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            toast('Submission saved.', 'success');
            setFiles([]);
            load();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setBusy(false);
        }
    };

    if (loading) {
        return <PageLoader label="Loading assignment…" />;
    }

    if (error || !assignment) {
        return <EmptyState icon="alert" title="Assignment unavailable" message={error || 'This assignment could not be loaded.'} />;
    }

    const isGraded = submission?.status === 'graded';
    const duePassed = assignment.due_at && new Date(assignment.due_at).getTime() < Date.now();

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            {assignment.course?.slug ? (
                <Link to={`/learn/${assignment.course.slug}`} className="text-sm font-medium text-brand-600 hover:text-brand-700">
                    ← Back to course
                </Link>
            ) : null}

            <Card className="p-6">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge color="amber">Assignment</Badge>
                    {duePassed ? <Badge color="red">Overdue</Badge> : null}
                    {assignment.max_score ? <Badge color="slate">Max {assignment.max_score} pts</Badge> : null}
                </div>
                <h1 className="mt-3 text-2xl font-bold text-slate-900">{assignment.title}</h1>
                {assignment.due_at ? (
                    <p className="mt-1 flex items-center gap-1.5 text-sm text-slate-500">
                        <Icon name="clock" className="h-4 w-4" />
                        Due {formatDate(assignment.due_at)}
                    </p>
                ) : null}

                <div className="mt-4 space-y-3 text-sm leading-relaxed text-slate-600">
                    {assignment.instructions ? <p>{assignment.instructions}</p> : null}
                    {assignment.description ? <p className="text-slate-500">{assignment.description}</p> : null}
                </div>

                {assignment.allowed_file_types?.length ? (
                    <p className="mt-4 text-xs text-slate-400">
                        Allowed file types: <span className="font-medium text-slate-600">{assignment.allowed_file_types.join(', ')}</span>
                    </p>
                ) : null}
            </Card>

            {isGraded ? (
                <Card className="p-6">
                    <h2 className="mb-3 text-lg font-bold text-slate-900">Submission result</h2>
                    <div className="flex items-center gap-4">
                        <div className="flex h-16 w-16 flex-col items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <span className="text-xl font-extrabold">
                                {submission.grade !== null ? submission.grade : '—'}
                            </span>
                            <span className="text-[10px] uppercase">/ {assignment.max_score}</span>
                        </div>
                        <div className="flex-1">
                            {submission.feedback ? (
                                <p className="text-sm leading-relaxed text-slate-600">
                                    <span className="font-semibold text-slate-900">Feedback: </span>
                                    {submission.feedback}
                                </p>
                            ) : (
                                <p className="text-sm text-slate-500">No written feedback provided.</p>
                            )}
                            <p className="mt-1 text-xs text-slate-400">Graded at {formatDate(submission.graded_at)}</p>
                        </div>
                    </div>
                </Card>
            ) : (
                <>
                    <Card className="p-6">
                        <h2 className="mb-1 text-lg font-bold text-slate-900">
                            {submission ? `Resubmit (version ${submission.version + 1})` : 'Submit your work'}
                        </h2>
                        <p className="mb-4 text-sm text-slate-500">
                            {submission
                                ? 'You can update your submission until it is graded.'
                                : 'Attach files, write a short answer, or both.'}
                        </p>

                        <form onSubmit={submit} className="space-y-4">
                            <label className="block">
                                <span className="mb-1.5 block text-sm font-medium text-slate-700">Response</span>
                                <Textarea
                                    value={content}
                                    onChange={(e) => setContent(e.target.value)}
                                    rows={6}
                                    placeholder="Write your answer here…"
                                />
                            </label>

                            <div>
                                <button
                                    type="button"
                                    onClick={() => inputRef.current?.click()}
                                    className="flex w-full flex-col items-center gap-2 rounded-lg border-2 border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500 transition hover:border-brand-400 hover:bg-brand-50"
                                >
                                    <Icon name="upload" className="h-6 w-6" />
                                    Click to attach files (max 5, size limit applies)
                                </button>
                                <input
                                    ref={inputRef}
                                    type="file"
                                    multiple
                                    hidden
                                    onChange={(e) => addFiles(e.target.files)}
                                />
                            </div>

                            {fileError ? <p className="text-xs font-medium text-red-600">{fileError}</p> : null}

                            {files.length ? (
                                <ul className="space-y-2">
                                    {files.map((file, i) => (
                                        <li
                                            key={`${file.name}-${i}`}
                                            className="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"
                                        >
                                            <Icon name="file" className="h-4 w-4 text-brand-500" />
                                            <span className="min-w-0 flex-1 truncate text-sm text-slate-700">{file.name}</span>
                                            <span className="text-xs text-slate-400">{formatBytes(file.size)}</span>
                                            <button
                                                type="button"
                                                onClick={() => setFiles((prev) => prev.filter((_, idx) => idx !== i))}
                                                className="text-slate-400 hover:text-red-600"
                                                aria-label="Remove file"
                                            >
                                                <Icon name="trash" className="h-4 w-4" />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}

                            <Button type="submit" loading={busy} icon="check" disabled={!content.trim() && !files.length}>
                                {submission ? 'Update submission' : 'Submit assignment'}
                            </Button>
                        </form>
                    </Card>

                    {submission ? (
                        <Card className="p-6">
                            <h2 className="mb-3 text-lg font-bold text-slate-900">Current submission</h2>
                            <p className="text-sm text-slate-500">
                                Submitted {formatDate(submission.submitted_at)} · version {submission.version}
                            </p>
                            {submission.content ? (
                                <p className="mt-3 whitespace-pre-wrap rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">{submission.content}</p>
                            ) : null}
                            {submission.files?.length ? (
                                <ul className="mt-3 space-y-2">
                                    {submission.files.map((file) => (
                                        <li key={`${file.name}-${file.index}`} className="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                            <Icon name="file" className="h-4 w-4 text-slate-400" />
                                            <span className="flex-1 text-slate-600">{file.name}</span>
                                            <span className="text-xs text-slate-400">{formatBytes(file.size)}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </Card>
                    ) : null}
                </>
            )}
        </div>
    );
}