import { useCallback, useEffect, useRef, useState } from 'react';
import api, { apiError } from '../../api';
import { Badge, Breadcrumbs, Button, ConfirmDialog, EmptyState, Field, Icon, Input, Modal, PageHeader, PageLoader, Section, Select, StatusBadge, Textarea, useToast } from '../../components/ui';

const lessonMeta = {
    text: { label: 'Text lesson', icon: 'doc' },
    video: { label: 'Video lesson', icon: 'video' },
    external_link: { label: 'External link', icon: 'link' },
    quiz: { label: 'Quiz', icon: 'puzzle' },
    assignment: { label: 'Assignment', icon: 'clipboard' },
};

export default function InstructorCourseBuilder() {
    const slug = location.pathname.split('/').pop();
    const toast = useToast();

    const [course, setCourse] = useState(null);
    const [settings, setSettings] = useState(null);
    const [loading, setLoading] = useState(true);
    const [savingSettings, setSavingSettings] = useState(false);
    const [busy, setBusy] = useState(false);
    const [categories, setCategories] = useState([]);

    const [sectionModal, setSectionModal] = useState(null); // { mode, section? }
    const [lessonModal, setLessonModal] = useState(null); // { mode, sectionId, lesson? }
    const [quizModal, setQuizModal] = useState(null); // { lessonId, quizId?, quiz? }
    const [assignmentModal, setAssignmentModal] = useState(null); // { sectionId, assignment? }
    const [confirm, setConfirm] = useState(null);
    const [materialFor, setMaterialFor] = useState(null);
    const materialInput = useRef(null);

    const load = useCallback(() => {
        setLoading(true);
        api.get(`/api/instructor/courses/${slug}`)
            .then(({ data }) => {
                setCourse(data.course);
                setSettings(data.course);
            })
            .catch(() => toast('Could not load course.', 'error'))
            .finally(() => setLoading(false));
    }, [slug, toast]);

    useEffect(() => {
        api.get('/api/courses/categories').then(({ data }) => setCategories(data ?? [])).catch(() => {});
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        setSettings(course);
    }, [course]);

    const sections = course?.sections ?? [];

    const run = async (fn, message) => {
        setBusy(true);
        try {
            const res = await fn();
            if (message) toast(message, 'success');
            await load();
            return res;
        } catch (err) {
            toast(apiError(err), 'error');
            return null;
        } finally {
            setBusy(false);
        }
    };

    const saveSettings = async (e) => {
        e.preventDefault();
        const formData = new FormData();
        Object.entries(settings || {}).forEach(([key, value]) => {
            if (['instructor', 'category', 'sections', 'thumbnail_url', 'enrollments_count', 'students_count', 'lessons_count', 'id', 'slug', 'status', 'published_at', 'is_owned', 'enrollment', 'price'].includes(key)) return;
            if (Array.isArray(value)) formData.append(key, JSON.stringify(value));
            else if (value !== null && value !== undefined) formData.append(key, value);
        });
        if (settingsThumb.current.files[0]) formData.append('thumbnail', settingsThumb.current.files[0]);

        setSavingSettings(true);
        try {
            await api.post(`/api/instructor/courses/${course.slug}?_method=PUT`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            toast('Course settings saved.', 'success');
            load();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setSavingSettings(false);
        }
    };
    const settingsThumb = useRef(null);

    const setStatus = (status) => run(() => api.patch(`/api/instructor/courses/${course.slug}/status/${status}`), `Course is now ${status}.`);

    if (loading) {
        return <PageLoader label="Loading course builder…" />;
    }

    if (!course) {
        return <EmptyState icon="alert" title="Course not found" message="This course could not be loaded." />;
    }

    const set = (key) => (e) => setSettings((f) => {
        let value = e.target.value;
        if (key === 'learning_objectives' || key === 'requirements') {
            value = e.target.value.split('\n').map((s) => s.trim()).filter(Boolean);
        }
        return { ...f, [key]: value };
    });

    const saveSection = async (payload) => {
        let res;
        if (sectionModal.mode === 'edit') {
            res = await run(() => api.put(`/api/instructor/courses/${course.slug}/sections/${sectionModal.section.id}`, payload), 'Section updated.');
        } else {
            res = await run(() => api.post(`/api/instructor/courses/${course.slug}/sections`, payload), 'Section created.');
        }
        if (res) setSectionModal(null);
    };

    const deleteSection = async (section) => {
        const ok = await run(() => api.delete(`/api/instructor/courses/${course.slug}/sections/${section.id}`), 'Section deleted.');
        if (ok) setConfirm(null);
    };

    return (
        <div className="space-y-6">
            <Breadcrumbs
                items={[{ label: 'My courses', to: '/instructor/courses' }, { label: course.title }]}
            />

            <PageHeader
                title={course.title}
                subtitle={`${course.lessons_count ?? 0} lessons · ${course.enrollments_count ?? 0} students`}
                actions={
                    <>
                        <StatusBadge status={course.status} />
                        {course.status === 'draft' ? (
                            <Button variant="success" icon="check" onClick={() => setStatus('published')}>
                                Publish
                            </Button>
                        ) : (
                            <Button variant="secondary" icon="chevronDown" onClick={() => setStatus('draft')}>
                                Unpublish
                            </Button>
                        )}
                        <Button variant="secondary" icon="eye" onClick={() => window.open(`/courses/${course.slug}`, '_blank')}>
                            Preview
                        </Button>
                    </>
                }
            />

            <Section
                title="Course settings"
                icon="settings"
                actions={<span className="text-xs text-slate-400">changes apply when you save</span>}
                bodyClassName="p-5"
            >
                <form onSubmit={saveSettings} className="grid gap-4 lg:grid-cols-2">
                    <Field label="Title" required>
                        <Input value={settings?.title ?? ''} onChange={set('title')} />
                    </Field>
                    <Field label="Category">
                        <Select value={settings?.category_id ?? ''} onChange={set('category_id')}>
                            <option value="">General</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>{c.name}</option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Short description" required>
                        <Textarea value={settings?.short_description ?? ''} onChange={set('short_description')} className="min-h-20" />
                    </Field>
                    <Field label="Full description">
                        <Textarea value={settings?.description ?? ''} onChange={set('description')} className="min-h-20" />
                    </Field>
                    <Field label="Level">
                        <Select value={settings?.level ?? 'beginner'} onChange={set('level')}>
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                        </Select>
                    </Field>
                    <Field label="Thumbnail" hint="Recommended 16:9 (e.g. 1280×720).">
                        <input ref={settingsThumb} type="file" accept="image/*" className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200" />
                    </Field>
                    <Field label="Learning objectives" hint="One per line.">
                        <Textarea value={(settings?.learning_objectives ?? []).join('\n')} onChange={set('learning_objectives')} className="min-h-24" />
                    </Field>
                    <Field label="Requirements" hint="One per line.">
                        <Textarea value={(settings?.requirements ?? []).join('\n')} onChange={set('requirements')} className="min-h-24" />
                    </Field>
                    <div className="lg:col-span-2 flex justify-end">
                        <Button type="submit" loading={savingSettings} icon="check" disabled={busy}>
                            Save settings
                        </Button>
                    </div>
                </form>
            </Section>

            <Section
                title="Curriculum"
                icon="grid"
                actions={
                    <Button variant="secondary" size="sm" icon="plus" onClick={() => setSectionModal({ mode: 'create' })}>
                        Add section
                    </Button>
                }
            >

                {sections.length === 0 ? (
                    <EmptyState icon="grid" title="No sections yet" message="Add a section to start building your curriculum." />
                ) : (
                    <div className="space-y-4">
                        {sections.map((section, index) => (
                            <div key={section.id} className="overflow-hidden rounded-xl border border-slate-200">
                                <div className="flex items-center gap-3 bg-slate-50 px-4 py-3">
                                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-white text-xs font-bold text-brand-600 ring-1 ring-slate-200">
                                        {index + 1}
                                    </span>
                                    <h3 className="min-w-0 flex-1 truncate font-semibold text-slate-900">{section.title}</h3>
                                    <span className="hidden text-xs text-slate-400 sm:block">{(section.lessons ?? []).length} items</span>
                                    <button type="button" className="rounded-md p-1.5 text-slate-400 hover:bg-slate-200/60 hover:text-slate-600" onClick={() => setSectionModal({ mode: 'edit', section })} aria-label="Edit section">
                                        <Icon name="pencil" className="h-4 w-4" />
                                    </button>
                                    <button type="button" className="rounded-md p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" onClick={() => setConfirm({ title: 'Delete this section?', message: `Section "${section.title}" and its ${(section.lessons ?? []).length} lessons will be permanently removed.`, action: () => deleteSection(section) })} aria-label="Delete section">
                                        <Icon name="trash" className="h-4 w-4" />
                                    </button>
                                </div>

                                <ul className="divide-y divide-slate-100">
                                    {(section.lessons ?? []).map((lesson) => (
                                        <LessonRow
                                            key={lesson.id}
                                            courseSlug={course.slug}
                                            lesson={lesson}
                                            run={run}
                                            broken={busy}
                                            onEdit={() =>
                                                lesson.type === 'quiz'
                                                    ? setQuizModal({ lessonId: lesson.id, quizId: lesson.quiz?.id, quiz: lesson.quiz })
                                                    : lesson.type === 'assignment'
                                                        ? setAssignmentModal({ sectionId: section.id, assignment: lesson.assignment })
                                                        : setLessonModal({ mode: 'edit', sectionId: section.id, lesson })
                                            }
                                            onAddMaterial={(id) => { setMaterialFor(id); requestAnimationFrame(() => materialInput.current?.click()); }}
                                            confirm={setConfirm}
                                            toast={toast}
                                            runDelete={(fn, msg) => run(fn, msg)}
                                        />
                                    ))}
                                </ul>

                                <div className="flex flex-wrap items-center gap-2 bg-slate-50/60 px-4 py-2.5">
                                    <Button variant="secondary" size="sm" icon="plus" onClick={() => setLessonModal({ mode: 'create', sectionId: section.id })}>
                                        Add lesson
                                    </Button>
                                    <Button variant="secondary" size="sm" icon="puzzle" onClick={() => setQuizModal({ lessonId: null, sectionId: section.id })}>
                                        Add quiz
                                    </Button>
                                    <Button variant="secondary" size="sm" icon="clipboard" onClick={() => setAssignmentModal({ sectionId: section.id })}>
                                        Add assignment
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                <input
                    ref={materialInput}
                    type="file"
                    hidden
                    onChange={(e) => {
                        const lessonId = materialFor;
                        const file = e.target.files?.[0];
                        e.target.value = '';
                        if (!file || !lessonId) return;
                        const formData = new FormData();
                        formData.append('file', file);
                        run(() => api.post(`/api/instructor/courses/${course.slug}/lessons/${lessonId}/materials`, formData), 'Material uploaded.');
                    }}
                />
            </Section>

            {sectionModal ? (
                <SectionModal
                    open
                    onClose={() => setSectionModal(null)}
                    mode={sectionModal.mode}
                    section={sectionModal.section}
                    saving={busy}
                    onSubmit={(payload) => saveSection(payload)}
                />
            ) : null}

            {lessonModal ? (
                <LessonModal
                    open
                    onClose={() => setLessonModal(null)}
                    mode={lessonModal.mode}
                    lesson={lessonModal.lesson}
                    saving={busy}
                    onSubmit={async (payload) => {
                        let res;
                        if (lessonModal.mode === 'edit') {
                            res = await run(() => api.put(`/api/instructor/courses/${course.slug}/lessons/${lessonModal.lesson.id}`, payload), 'Lesson updated.');
                        } else {
                            res = await run(() => api.post(`/api/instructor/courses/${course.slug}/sections/${lessonModal.sectionId}/lessons`, payload), 'Lesson created.');
                        }
                        if (res) setLessonModal(null);
                    }}
                    toast={toast}
                />
            ) : null}

            {quizModal ? (
                <QuizModal
                    open
                    onClose={() => setQuizModal(null)}
                    courseSlug={course.slug}
                    data={quizModal}
                    run={run}
                    busy={busy}
                    toast={toast}
                />
            ) : null}

            {assignmentModal ? (
                <AssignmentModal
                    open
                    onClose={() => setAssignmentModal(null)}
                    courseSlug={course.slug}
                    data={assignmentModal}
                    run={run}
                    busy={busy}
                    sections={sections}
                    toast={toast}
                />
            ) : null}

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
        </div>
    );
}

function LessonRow({ courseSlug, lesson, onEdit, onAddMaterial, runDelete, confirm, toast }) {
    const meta = lessonMeta[lesson.type] ?? lessonMeta.text;
    const quizProps = lesson.quiz?.questions_count;
    const hasQuiz = lesson.type === 'quiz';
    const isAssignment = lesson.type === 'assignment';

    return (
        <li className="group flex items-center gap-3 px-4 py-2.5">
            <Icon name={meta.icon} className={isAssignment ? 'h-4 w-4 text-amber-500' : hasQuiz ? 'h-4 w-4 text-violet-500' : 'h-4 w-4 text-slate-400'} />
            <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-sm font-medium text-slate-800">
                    <span className="truncate">{lesson.title}</span>
                    {hasQuiz ? (
                        <Badge color="violet">Quiz · {lesson.quiz?.questions_count ?? 0} q</Badge>
                    ) : isAssignment ? (
                        <Badge color="amber">Assignment</Badge>
                    ) : null}
                    {lesson.is_published ? null : <Badge color="slate">Hidden</Badge>}
                </p>
                <p className="text-xs text-slate-400">
                    {meta.label}
                    {lesson.materials?.length ? ` · ${lesson.materials.length} material(s)` : ''}
                </p>
            </div>
            <div className="flex items-center gap-1 opacity-75 transition group-hover:opacity-100 sm:opacity-100">
                <button type="button" className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Add material" onClick={() => onAddMaterial(lesson.id)}>
                    <Icon name="upload" className="h-4 w-4" />
                </button>
                <button type="button" className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Edit" onClick={onEdit}>
                    <Icon name="pencil" className="h-4 w-4" />
                </button>
                <button
                    type="button"
                    className="rounded-md p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                    title="Delete"
                    onClick={() =>
                        confirm({
                            title: 'Delete this lesson?',
                            message: `"${lesson.title}" and its content will be removed.`,
                            action: async () => {
                                const ok = await runDelete(() => api.delete(`/api/instructor/courses/${courseSlug}/lessons/${lesson.id}`), 'Lesson deleted.');
                                if (ok) confirm(null);
                            },
                        })
                    }
                >
                    <Icon name="trash" className="h-4 w-4" />
                </button>
            </div>
        </li>
    );
}

/* ------------------------------- Section modal ------------------------------ */

function SectionModal({ open, onClose, mode, section, saving, onSubmit }) {
    const [title, setTitle] = useState(section?.title ?? '');
    const [description, setDescription] = useState(section?.description ?? '');

    useEffect(() => {
        setTitle(section?.title ?? '');
        setDescription(section?.description ?? '');
    }, [section]);

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={mode === 'edit' ? 'Edit section' : 'Add section'}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button loading={saving} onClick={() => onSubmit({ title, description })} disabled={!title.trim()}>
                        {mode === 'edit' ? 'Save' : 'Create'}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Section title" required>
                    <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Getting started" />
                </Field>
                <Field label="Description">
                    <Textarea value={description} onChange={(e) => setDescription(e.target.value)} />
                </Field>
            </div>
        </Modal>
    );
}

/* ------------------------------- Lesson modal ------------------------------ */

function LessonModal({ open, onClose, mode, lesson, saving, onSubmit, toast }) {
    const [form, setForm] = useState(null);
    const hasStoredVideo = Boolean(lesson?.video_url) && lesson.video_url.includes('/storage/lessons/videos/');

    useEffect(() => {
        setForm({
            title: lesson?.title ?? '',
            type: lesson?.type ?? 'text',
            content: lesson?.content ?? '',
            video_url: hasStoredVideo ? '' : (lesson?.video_url ?? ''),
            external_url: lesson?.external_url ?? '',
            duration_seconds: lesson?.duration_seconds ?? '',
            is_published: lesson?.is_published ?? true,
        });
    }, [lesson]);

    const set = (key) => (e) => setForm((f) => (key === 'is_published' ? { ...f, [key]: e.target.checked } : { ...f, [key]: e.target.value }));
    const [videoFile, setVideoFile] = useState(null);

    const submit = () => {
        const payload = { ...form };
        const f = new FormData();
        for (const [key, value] of Object.entries(payload)) {
            if (key === 'is_published') f.append(key, value ? '1' : '0');
            else if (value !== '' && value !== null) f.append(key, value);
        }
        if (videoFile) f.append('video', videoFile);
        onSubmit(f);
        setVideoFile(null);
    };

    if (!form) return null;

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={mode === 'edit' ? 'Edit lesson' : 'Add lesson'}
            size="lg"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button loading={saving} onClick={submit} disabled={!form.title.trim()}>
                        {mode === 'edit' ? 'Save lesson' : 'Create lesson'}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Lesson title" required>
                        <Input value={form.title} onChange={set('title')} />
                    </Field>
                    <Field label="Type">
                        <Select value={form.type} onChange={set('type')}>
                            <option value="text">Text lesson</option>
                            <option value="video">Video lesson</option>
                            <option value="external_link">External link</option>
                        </Select>
                    </Field>
                </div>

                {form.type === 'text' ? (
                    <Field label="Content">
                        <Textarea value={form.content} onChange={set('content')} className="min-h-40" />
                    </Field>
                ) : null}

                {form.type === 'video' ? (
                    <div className="space-y-4">
                        <Field label="Upload video file" hint="MP4, WebM, MOV, M4V or OGG (max 200 MB).">
                            <input
                                type="file"
                                accept="video/*"
                                onChange={(e) => setVideoFile(e.target.files[0])}
                                className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                            />
                            {hasStoredVideo ? (
                                <p className="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 ring-1 ring-emerald-200">
                                    A video file is attached to this lesson. Saving keeps it — upload a new file or enter a URL to replace it.
                                </p>
                            ) : form.video_url && !videoFile ? (
                                <p className="mt-1 text-xs text-slate-500">Current external video will be replaced if you upload a file.</p>
                            ) : null}
                        </Field>
                        <div className="flex items-center gap-3 text-xs text-slate-400">
                            <span className="h-px flex-1 bg-slate-200" />
                            or use an external video URL
                            <span className="h-px flex-1 bg-slate-200" />
                        </div>
                        <Field label="External video URL" hint="YouTube, Vimeo or any direct .mp4 link." error={form.type === 'video' && !videoFile && !form.video_url ? 'Provide a file or a URL.' : undefined}>
                            <Input value={form.video_url} onChange={set('video_url')} placeholder="https://www.youtube.com/watch?v=…" />
                        </Field>
                    </div>
                ) : null}

                {form.type === 'external_link' ? (
                    <Field label="External URL" required>
                        <Input value={form.external_url} onChange={set('external_url')} placeholder="https://…" />
                    </Field>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Duration (seconds)" hint="Optional, shown in the curriculum.">
                        <Input type="number" min="1" value={form.duration_seconds} onChange={set('duration_seconds')} />
                    </Field>
                    <label className="flex items-center gap-2 pt-6 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={form.is_published} onChange={set('is_published')} className="h-4 w-4 rounded accent-brand-600" />
                        Published (visible to students)
                    </label>
                </div>
            </div>
        </Modal>
    );
}

/* -------------------------------- Quiz modal ------------------------------- */

function QuizModal({ open, onClose, courseSlug, data, run, busy, toast }) {
    const [settings, setSettings] = useState(null);
    const [questions, setQuestions] = useState([]);
    const [editing, setEditing] = useState(null); // null | { q?, form }
    const [creating, setCreating] = useState(false);
    const [ids, setIds] = useState(() => ({
        lessonId: data.lessonId ?? null,
        quizId: data.quizId ?? null,
        sectionId: data.sectionId ?? null,
    }));

    const lessonId = ids.lessonId;
    const quizId = ids.quizId;
    const quiz = data.quiz;
    const sectionId = ids.sectionId;

    const loadQuiz = useCallback(() => {
        if (!quizId) {
            setSettings(null);
            setQuestions([]);
            return;
        }
        api.get(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}`)
            .then(({ data }) => {
                const q = data.quiz;
                setSettings({
                    title: q.title,
                    instructions: q.instructions ?? '',
                    passing_score: q.passing_score,
                    attempts_allowed: q.attempts_allowed ?? 1,
                    time_limit_minutes: q.time_limit_minutes ?? '',
                });
                setQuestions(q.questions ?? []);
            })
            .catch(() => toast('Could not load quiz.', 'error'));
    }, [courseSlug, quizId, toast]);

    useEffect(() => {
        if (open) loadQuiz();
    }, [open, loadQuiz]);

    const saveSettings = async () => {
        if (!settings) return;
        const ok = await run(
            () => api.post(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}?_method=PUT`, settings),
            'Quiz settings saved.',
        );
        if (ok) loadQuiz();
    };

    const createQuiz = async () => {
        if (!effectiveSettings) return;
        let targetLessonId = lessonId;
        if (!targetLessonId && sectionId) {
            const lessonRes = await run(
                () => api.post(`/api/instructor/courses/${courseSlug}/sections/${sectionId}/lessons`, {
                    title: effectiveSettings.title,
                    type: 'quiz',
                    is_published: true,
                }),
                null,
            );
            targetLessonId = lessonRes?.data?.lesson?.id;
            if (!targetLessonId) return;
        }
        if (!targetLessonId) return;
        const ok = await run(
            () => api.post(`/api/instructor/courses/${courseSlug}/lessons/${targetLessonId}/quiz`, effectiveSettings),
            'Quiz created — add questions to finish it.',
        );
        if (ok?.data?.quiz?.id) {
            setIds((prev) => ({ ...prev, lessonId: targetLessonId, quizId: ok.data.quiz.id }));
            setSettings({
                title: ok.data.quiz.title,
                instructions: ok.data.quiz.instructions ?? '',
                passing_score: ok.data.quiz.passing_score,
                attempts_allowed: effectiveSettings.attempts_allowed ?? 1,
                time_limit_minutes: ok.data.quiz.time_limit_minutes ?? '',
            });
        }
    };

    const saveQuestion = async (payload) => {
        const ok = await run(() =>
            editing?.q
                ? api.post(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}/questions/${editing.q.id}?_method=PUT`, payload)
                : api.post(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}/questions`, payload),
            editing?.q ? 'Question updated.' : 'Question added.',
        );
        if (ok) {
            setEditing(null);
            setCreating(false);
            loadQuiz();
        }
    };

    const deleteQuestion = async (q) => {
        const ok = await run(() => api.delete(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}/questions/${q.id}`), 'Question deleted.');
        if (ok) loadQuiz();
    };

    const set = (key) => (e) => setSettings((f) => ({ ...f, [key]: key === 'time_limit_minutes' && e.target.value === '' ? null : Number(e.target.value) || e.target.value }));

    const defaultNew = { title: '', instructions: '', passing_score: 70, attempts_allowed: 1, time_limit_minutes: '' };
    const effectiveSettings = quizId ? settings : { ...defaultNew, ...(settings ?? {}) };

    if (!quizId) {
        // Creating a brand new quiz
        return (
            <Modal
                open={open}
                onClose={onClose}
                title="Create a quiz"
                footer={
                    <>
                        <Button variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button loading={busy} icon="puzzle" onClick={createQuiz} disabled={!effectiveSettings.title?.trim()}>
                            Create quiz
                        </Button>
                    </>
                }
            >
                <QuizSettingsForm settings={effectiveSettings} setSettings={set} submitLabel="Create" showFooter={false} />
            </Modal>
        );
    }

    return (
        <Modal open={open} onClose={onClose} title="Manage quiz" size="lg" footer={<Button variant="secondary" onClick={onClose}>Done</Button>}>
            <div className="space-y-6">
                <QuizSettingsForm settings={settings} setSettings={set} onSubmit={saveSettings} saving={busy} submitLabel="Save quiz settings" />

                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-bold text-slate-900">
                            Questions ({questions.length})
                        </h3>
                        {!creating && !editing ? (
                            <Button variant="secondary" size="sm" icon="plus" onClick={() => { setCreating(true); setEditing({ q: null, defaults: true }); }}>
                                Add question
                            </Button>
                        ) : null}
                    </div>

                    {(creating || editing?.q) && !editing?.q?.id ? (
                        <QuestionForm
                            onCancel={() => { setCreating(false); setEditing(null); }}
                            onSubmit={saveQuestion}
                            saving={busy}
                        />
                    ) : null}

                    {questions.map((q) => (
                        <div key={q.id} className="mb-2 rounded-lg border border-slate-200 p-3">
                            {editing?.q?.id === q.id ? (
                                <QuestionForm
                                    initial={q}
                                    onCancel={() => setEditing(null)}
                                    onSubmit={saveQuestion}
                                    saving={busy}
                                />
                            ) : (
                                <div>
                                    <div className="flex items-start justify-between gap-3">
                                        <p className="text-sm font-medium text-slate-800">
                                            <span className="mr-1 text-brand-600">Q{q.type === 'short_answer' ? '·SA' : q.type === 'true_false' ? '·TF' : ''}.</span>
                                            {q.question_text}
                                        </p>
                                        <div className="flex shrink-0 items-center gap-1">
                                            <Badge color="slate">{q.points} pt</Badge>
                                            <button type="button" className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" onClick={() => setEditing({ q })}>
                                                <Icon name="pencil" className="h-4 w-4" />
                                            </button>
                                            <button type="button" className="rounded-md p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" onClick={() => deleteQuestion(q)}>
                                                <Icon name="trash" className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {q.options.map((o, i) => (
                                            <span key={o.id} className={o.is_correct ? 'rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200' : 'rounded-full bg-slate-50 px-2 py-0.5 text-xs text-slate-500'}>
                                                {o.option_text}
                                            </span>
                                        ))}
                                        {q.options.length === 0 ? <span className="text-xs text-slate-400">No options</span> : null}
                                    </div>
                                </div>
                            )}
                        </div>
                    ))}

                    {questions.length === 0 && !creating ? <p className="text-sm text-slate-400">No questions yet — add the first one above.</p> : null}
                </div>
            </div>
        </Modal>
    );
}

function QuizSettingsForm({ settings, setSettings, onSubmit, saving, submitLabel, showFooter = true }) {
    if (!settings) return null;
    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Quiz title" required>
                    <Input value={settings.title ?? ''} onChange={setSettings('title')} />
                </Field>
                <Field label="Instructions">
                    <Input value={settings.instructions ?? ''} onChange={setSettings('instructions')} />
                </Field>
            </div>
            <div className="grid grid-cols-3 gap-4">
                <Field label="Passing %">
                    <Input type="number" min="0" max="100" value={settings.passing_score ?? 70} onChange={setSettings('passing_score')} />
                </Field>
                <Field label="Attempts">
                    <Input type="number" min="0" value={settings.attempts_allowed ?? 1} onChange={setSettings('attempts_allowed')} />
                </Field>
                <Field label="Time limit (min)" hint="0 = none.">
                    <Input type="number" min="0" value={settings.time_limit_minutes ?? ''} onChange={setSettings('time_limit_minutes')} />
                </Field>
            </div>
            {showFooter ? (
                <div className="flex justify-end">
                    <Button loading={saving} onClick={onSubmit} icon="check" disabled={!settings.title?.trim()}>
                        {submitLabel}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

function QuestionForm({ initial, onCancel, onSubmit, saving }) {
    const [form, setForm] = useState(() => ({
        type: initial?.type ?? 'multiple_choice',
        question_text: initial?.question_text ?? '',
        points: initial?.points ?? 1,
        explanation: initial?.explanation ?? '',
        options: initial?.options?.map((o) => ({ option_text: o.option_text, is_correct: Boolean(o.is_correct), explanation: o.explanation ?? '' })) ?? [
            { option_text: '', is_correct: false, explanation: '' },
            { option_text: '', is_correct: false, explanation: '' },
        ],
    }));

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const setOption = (i, key, value) => setForm((f) => ({ ...f, options: f.options.map((o, idx) => (idx === i ? { ...o, [key]: value } : o)) }));

    const submit = () => {
        const payload = { type: form.type, question_text: form.question_text, points: Number(form.points) || 1, explanation: form.explanation };
        if (form.type === 'short_answer') {
            payload.options = [{ option_text: initial?.options?.[0]?.option_text ?? '', is_correct: true, explanation: '' }];
        } else if (form.type === 'true_false') {
            payload.options = [
                { option_text: 'True', is_correct: form.options?.[0]?.is_correct === true, explanation: form.options?.[0]?.explanation },
                { option_text: 'False', is_correct: form.options?.[1]?.is_correct === true, explanation: form.options?.[1]?.explanation },
            ];
        } else {
            payload.options = form.options.filter((o) => o.option_text.trim());
        }
        onSubmit(payload);
    };

    const setCorrect = (i) => {
        if (form.type === 'true_false') {
            const next = form.options.map((o, idx) => ({ ...o, is_correct: idx === i }));
            set('options', next);
        } else {
            setOption(i, 'is_correct', !form.options[i].is_correct);
        }
    };

    return (
        <div className="rounded-lg border border-brand-200 bg-brand-50/50 p-4">
            <div className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-[1fr_120px_120px]">
                    <Field label="Question text" required>
                        <Input value={form.question_text} onChange={(e) => set('question_text', e.target.value)} />
                    </Field>
                    <Field label="Type">
                        <Select value={form.type} onChange={(e) => set('type', e.target.value)}>
                            <option value="multiple_choice">Multiple choice</option>
                            <option value="true_false">True / False</option>
                            <option value="short_answer">Short answer</option>
                        </Select>
                    </Field>
                    <Field label="Points">
                        <Input type="number" min="1" value={form.points} onChange={(e) => set('points', e.target.value)} />
                    </Field>
                </div>

                {form.type === 'short_answer' ? (
                    <Field label="Correct answer (exact, case-insensitive)" required>
                        <Input
                            value={form.options[0]?.option_text ?? ''}
                            onChange={(e) => setOption(0, 'option_text', e.target.value)}
                        />
                    </Field>
                ) : form.type === 'true_false' ? (
                    <div className="grid gap-2 sm:grid-cols-2">
                        {[['True', 0], ['False', 1]].map(([label, idx]) => (
                            <label key={label} className="flex cursor-pointer items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 ring-1 ring-slate-200">
                                <span className="text-sm font-medium text-slate-700">{label}</span>
                                <input type="radio" name="tf" checked={form.options[idx]?.is_correct} onChange={() => setCorrect(idx)} className="h-4 w-4 accent-brand-600" />
                            </label>
                        ))}
                    </div>
                ) : (
                    <div className="space-y-2">
                        {form.options.map((option, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={option.is_correct}
                                    onChange={() => setCorrect(i)}
                                    className="h-4 w-4 shrink-0 accent-brand-600"
                                    title="Correct answer"
                                />
                                <Input value={option.option_text} onChange={(e) => setOption(i, 'option_text', e.target.value)} placeholder={`Option ${String.fromCharCode(65 + i)}`} />
                                <button type="button" className="text-slate-400 hover:text-red-600" onClick={() => set('options', form.options.filter((_, idx) => idx !== i))} aria-label="Remove option">
                                    <Icon name="trash" className="h-4 w-4" />
                                </button>
                            </div>
                        ))}
                        <Button type="button" variant="secondary" size="sm" icon="plus" onClick={() => set('options', [...form.options, { option_text: '', is_correct: false, explanation: '' }])}>
                            Add option
                        </Button>
                    </div>
                )}

                <Field label="Explanation (shown to students after the quiz)">
                    <Textarea value={form.explanation ?? ''} onChange={(e) => set('explanation', e.target.value)} className="min-h-16" />
                </Field>

                <div className="flex justify-end gap-2">
                    <Button variant="secondary" size="sm" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button loading={saving} size="sm" icon="check" onClick={submit} disabled={!form.question_text.trim() || form.type !== 'short_answer' && form.options.filter((o) => o.option_text.trim()).length < 2}>
                        {initial?.id ? 'Save question' : 'Add question'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

/* ---------------------------- Assignment modal ---------------------------- */

function AssignmentModal({ open, onClose, courseSlug, data, run, busy, sections, toast }) {
    const assignment = data.assignment;
    const [form, setForm] = useState(null);

    useEffect(() => {
        setForm({
            title: assignment?.title ?? '',
            instructions: assignment?.instructions ?? '',
            description: assignment?.description ?? '',
            max_score: assignment?.max_score ?? 100,
            due_at: assignment?.due_at?.slice(0, 16) ?? '',
            allowed_file_types: (assignment?.allowed_file_types ?? []).join(', '),
            status: assignment?.status ?? 'active',
            section_id: assignment?.course_id ? (data.sectionId ?? '') : (data.sectionId ?? ''),
        });
    }, [assignment, data.sectionId]);

    if (!form) return null;

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    const submit = () => {
        const payload = {
            ...form,
            max_score: Number(form.max_score) || 100,
            due_at: form.due_at || null,
            allowed_file_types: form.allowed_file_types ? form.allowed_file_types.split(',').map((s) => s.trim().toLowerCase()).filter(Boolean) : [],
        };

        if (assignment?.id) {
            run(() => api.post(`/api/instructor/courses/${courseSlug}/assignments/${assignment.id}?_method=PUT`, payload), 'Assignment updated.');
        } else {
            run(() => api.post(`/api/instructor/courses/${courseSlug}/assignments`, payload), 'Assignment added to the curriculum.');
        }
        onClose();
    };

    const remove = async () => {
        if (!assignment?.id) {
            onClose();
            return;
        }
        const ok = await run(() => api.delete(`/api/instructor/courses/${courseSlug}/assignments/${assignment.id}`), 'Assignment removed.');
        if (ok) onClose();
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={assignment?.id ? 'Edit assignment' : 'Add assignment'}
            footer={
                <>
                    {assignment?.id ? (
                        <Button variant="danger" icon="trash" onClick={remove}>
                            Remove
                        </Button>
                    ) : null}
                    <div className="flex-1" />
                    <Button variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button loading={busy} onClick={submit} disabled={!form.title?.trim()}>
                        {assignment?.id ? 'Save' : 'Add assignment'}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Assignment title" required>
                    <Input value={form.title} onChange={set('title')} placeholder="e.g. Build a Portfolio Project" />
                </Field>
                <Field label="Instructions">
                    <Textarea value={form.instructions} onChange={set('instructions')} className="min-h-24" />
                </Field>
                <Field label="Description (optional)">
                    <Input value={form.description} onChange={set('description')} />
                </Field>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Max score">
                        <Input type="number" min="1" value={form.max_score} onChange={set('max_score')} />
                    </Field>
                    <Field label="Due date">
                        <Input type="datetime-local" value={form.due_at} onChange={set('due_at')} />
                    </Field>
                    <Field label="Status">
                        <Select value={form.status} onChange={set('status')}>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </Select>
                    </Field>
                </div>
                <Field label="Allowed file types" hint="Comma separated extensions. Blank = any file type.">
                    <Input value={form.allowed_file_types} onChange={set('allowed_file_types')} placeholder="pdf,zip,docx,jpg" />
                </Field>
                {!assignment?.id ? (
                    <Field label="Place in section">
                        <Select value={form.section_id} onChange={set('section_id')}>
                            {sections.map((s) => (
                                <option key={s.id} value={s.id}>{s.title}</option>
                            ))}
                        </Select>
                    </Field>
                ) : null}
            </div>
        </Modal>
    );
}