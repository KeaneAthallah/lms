import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import api, { apiError } from '../../api';
import { Alert, Badge, Breadcrumbs, Button, ButtonLink, ConfirmDialog, EmptyState, Field, Icon, Input, Modal, PageHeader, PageLoader, Section, Select, StatusBadge, Textarea, useToast } from '../../components/ui';
import { blankIndexes, QUESTION_TYPES } from '../../components/quiz/questionTypes';

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
    const [banksOpen, setBanksOpen] = useState(false);
    const [bankManager, setBankManager] = useState(null); // bank to open for question authoring
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
                        <ButtonLink to={`/instructor/courses/${course.slug}/radar`} variant="secondary" icon="trendingUp">
                            Learning radar
                        </ButtonLink>
                        <ButtonLink to={`/instructor/courses/${course.slug}/gradebook`} variant="secondary" icon="listChecks">
                            Gradebook
                        </ButtonLink>
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
                    <>
                        <Button variant="secondary" size="sm" icon="layers" onClick={() => setBanksOpen(true)}>
                            Question banks
                        </Button>
                        <Button variant="secondary" size="sm" icon="plus" onClick={() => setSectionModal({ mode: 'create' })}>
                            Add section
                        </Button>
                    </>
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
                    confirm={setConfirm}
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

            {banksOpen ? (
                <QuestionBanksModal
                    open
                    onClose={() => setBanksOpen(false)}
                    courseSlug={course.slug}
                    run={run}
                    busy={busy}
                    onManage={(bank) => { setBanksOpen(false); setBankManager(bank); }}
                />
            ) : null}

            {bankManager ? (
                <QuestionBankModal
                    open
                    onClose={() => setBankManager(null)}
                    courseSlug={course.slug}
                    bank={bankManager}
                    run={run}
                    busy={busy}
                    confirm={setConfirm}
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

function QuizModal({ open, onClose, courseSlug, data, run, busy, toast, confirm }) {
    const [settings, setSettings] = useState(null);
    const [questions, setQuestions] = useState([]);
    const [banks, setBanks] = useState([]);
    const [errors, setErrors] = useState({});
    const [savingSettings, setSavingSettings] = useState(false);
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

    const loadBanks = useCallback(() => {
        api.get(`/api/instructor/courses/${courseSlug}/question-banks`)
            .then(({ data }) => setBanks(data.banks ?? []))
            .catch(() => toast('Could not load question banks.', 'error'));
    }, [courseSlug, toast]);

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
                    question_bank_id: q.question_bank_id ?? '',
                    draw_size: q.draw_size ?? '',
                    blueprint: q.blueprint ?? [],
                    available_from: q.available_from ?? '',
                    available_until: q.available_until ?? '',
                    late_grace_minutes: q.late_grace_minutes ?? '',
                });
                setQuestions(q.questions ?? []);
                setErrors({});
            })
            .catch(() => toast('Could not load quiz.', 'error'));
    }, [courseSlug, quizId, toast]);

    useEffect(() => {
        if (open) {
            loadBanks();
            loadQuiz();
        }
    }, [open, loadBanks, loadQuiz]);

    // Takes the merged settings rather than reading `settings` directly, because
    // in create mode the defaults live in `effectiveSettings`, not in state.
    const settingsPayload = (base) => {
        const next = { ...(base ?? {}) };
        // The server treats a missing bank as "owns its own questions", so the
        // bank keys must be dropped rather than sent as empty strings. The
        // blueprint goes with them: quotas only mean something for a bank's draw.
        if (next.question_bank_id) {
            return {
                ...next,
                question_bank_id: Number(next.question_bank_id),
                draw_size: Number(next.draw_size) || undefined,
                blueprint: next.blueprint ?? [],
            };
        }
        delete next.question_bank_id;
        delete next.draw_size;
        delete next.blueprint;
        return next;
    };

    const saveSettings = async () => {
        if (!settings) return;
        setSavingSettings(true);
        setErrors({});
        try {
            await api.post(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}?_method=PUT`, settingsPayload(settings));
            toast('Quiz settings saved.', 'success');
            loadQuiz();
        } catch (err) {
            // Surfaced per-field rather than collapsed into a toast, so a
            // rejected draw size lands on the input that caused it.
            setErrors(err.response?.data?.errors ?? {});
            toast(apiError(err), 'error');
        } finally {
            setSavingSettings(false);
        }
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
        setSavingSettings(true);
        setErrors({});
        try {
            const { data } = await api.post(
                `/api/instructor/courses/${courseSlug}/lessons/${targetLessonId}/quiz`,
                settingsPayload(effectiveSettings),
            );
            toast(data.quiz?.question_bank_id ? 'Quiz created from a bank.' : 'Quiz created — add questions to finish it.', 'success');
            setIds((prev) => ({ ...prev, lessonId: targetLessonId, quizId: data.quiz.id }));
            loadQuiz();
        } catch (err) {
            setErrors(err.response?.data?.errors ?? {});
            toast(apiError(err), 'error');
        } finally {
            setSavingSettings(false);
        }
    };

    const saveQuestion = async (payload) => {
        const res = await run(
            () => (editing?.q
                ? api.post(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}/questions/${editing.q.id}?_method=PUT`, payload)
                : api.post(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}/questions`, payload)),
            null,
        );
        if (res) {
            toast(res.data?.message ?? 'Question saved.', 'success');
            setEditing(null);
            setCreating(false);
            loadQuiz();
        }
    };

    const deleteQuestion = async (q) => {
        const ok = await run(() => api.delete(`/api/instructor/courses/${courseSlug}/quizzes/${quizId}/questions/${q.id}`), 'Question deleted.');
        if (ok) loadQuiz();
    };

    const set = (key) => (e) => setSettings((f) => ({ ...f, [key]: ['time_limit_minutes', 'draw_size', 'available_from', 'available_until', 'late_grace_minutes'].includes(key) && e.target.value === '' ? '' : Number(e.target.value) || e.target.value }));

    const defaultNew = { title: '', instructions: '', passing_score: 70, attempts_allowed: 1, time_limit_minutes: '', question_bank_id: '', draw_size: '', blueprint: [], available_from: '', available_until: '', late_grace_minutes: '' };
    const effectiveSettings = quizId ? settings : { ...defaultNew, ...(settings ?? {}) };
    const bankId = effectiveSettings?.question_bank_id || '';
    const bank = banks.find((b) => String(b.id) === String(bankId)) ?? null;
    const usesBank = Boolean(bankId);
    // The server refuses to attach a bank while the quiz still owns questions,
    // so the switch is blocked here rather than left to fail on save.

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
                        <Button loading={savingSettings} icon="puzzle" onClick={createQuiz} disabled={!effectiveSettings.title?.trim()}>
                            Create quiz
                        </Button>
                    </>
                }
            >
                <QuizSettingsForm
                    settings={effectiveSettings}
                    setSettings={set}
                    submitLabel="Create"
                    showFooter={false}
                    banks={banks}
                    errors={errors}
                    questionCount={0}
                />
            </Modal>
        );
    }

    return (
        <Modal open={open} onClose={onClose} title="Manage quiz" size="lg" footer={<Button variant="secondary" onClick={onClose}>Done</Button>}>
            <div className="space-y-6">
                <QuizSettingsForm
                    settings={settings}
                    setSettings={set}
                    onSubmit={saveSettings}
                    saving={savingSettings}
                    submitLabel="Save quiz settings"
                    banks={banks}
                    errors={errors}
                    questionCount={questions.length}
                />

                {usesBank ? (
                    <div>
                        <h3 className="text-sm font-bold text-slate-900">Question source</h3>
                        <Alert tone="info" icon="layers" className="mt-3">
                            {bank ? (
                                <>
                                    Each attempt draws <strong>{Number(settings.draw_size) || 0}</strong> of the{' '}
                                    <strong>{bank.questions_count}</strong> questions in{' '}
                                    <strong>{bank.title}</strong>, so every student gets a different paper.
                                    Manage the pool under <strong>Curriculum → Question banks</strong>.
                                </>
                            ) : (
                                'Select a question bank to draw this quiz from.'
                            )}
                        </Alert>
                        {bank && Number(settings.draw_size) > bank.questions_count ? (
                            <Alert tone="warning" icon="info" className="mt-3">
                                The draw size is larger than the bank holds. Add more questions or lower the draw size.
                            </Alert>
                        ) : null}
                        {(settings.blueprint ?? []).length > 0 ? (
                            <Alert tone="info" icon="filter" className="mt-3">
                                Blueprint:{' '}
                                {(settings.blueprint ?? [])
                                    .map((rule) => `${rule.count} ${QUESTION_TYPES.find((t) => t.value === rule.type)?.label ?? rule.type}`)
                                    .join(', ')}
                                . The rest of the paper is filled from the other types.
                            </Alert>
                        ) : null}
                    </div>
                ) : (
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
                            <QuestionListItem
                                key={q.id}
                                question={q}
                                inUse={Boolean(q.in_use)}
                                editing={editing?.q?.id === q.id ? (
                                    <QuestionForm
                                        initial={q}
                                        onCancel={() => setEditing(null)}
                                        onSubmit={saveQuestion}
                                        saving={busy}
                                    />
                                ) : null}
                                onEdit={() => {
                                    if (q.in_use) {
                                        confirm({
                                            title: 'Create a new version?',
                                            message: `This question has already been served to students. Saving your changes creates v${(q.version ?? 1) + 1} for future attempts; students who already attempted it keep the current version.`,
                                            action: () => setEditing({ q }),
                                        });
                                        return;
                                    }
                                    setEditing({ q });
                                }}
                                onDelete={() => confirm({
                                    title: 'Delete this question?',
                                    message: 'Students who have already attempted this quiz keep the question they were served, but it will be removed from the quiz.',
                                    action: () => deleteQuestion(q),
                                })}
                            />
                        ))}

                        {questions.length === 0 && !creating ? <p className="text-sm text-slate-400">No questions yet — add the first one above.</p> : null}
                    </div>
                )}
            </div>
        </Modal>
    );
}

/**
 * One row in a question list. Shared by quiz and bank authoring; `inUse`
 * reflects that the question is already in a paper a student was served, which
 * locks deletion on the server. Editing stays allowed but forks a new version,
 * so callers confirm before opening the editor.
 */
function QuestionListItem({ question: q, editing, onEdit, onDelete, inUse = false }) {
    if (editing) return <div className="mb-2 rounded-lg border border-slate-200 p-3">{editing}</div>;

    return (
        <div className="mb-2 rounded-lg border border-slate-200 p-3">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium text-slate-800">
                    <span className="mr-1 text-brand-600">Q{q.type === 'short_answer' ? '·SA' : q.type === 'true_false' ? '·TF' : ''}.</span>
                    {q.question_text}
                </p>
                <div className="flex shrink-0 items-center gap-1">
                    {inUse ? <Badge color="amber">In use</Badge> : null}
                    {(q.version ?? 1) > 1 ? <Badge color="slate">v{q.version}</Badge> : null}
                    <Badge color="slate">{q.points} pt</Badge>
                    <button
                        type="button"
                        title={inUse ? 'Saving creates a new version for future attempts; students who already attempted it keep this one.' : undefined}
                        className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        onClick={onEdit}
                    >
                        <Icon name="pencil" className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        disabled={inUse}
                        title={inUse ? 'This question has already been served to a student and can no longer be deleted.' : undefined}
                        className="rounded-md p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
                        onClick={onDelete}
                    >
                        <Icon name="trash" className="h-4 w-4" />
                    </button>
                </div>
            </div>
            <div className="mt-2 flex flex-wrap gap-2">
                {(q.options ?? []).map((o) => (
                    <span key={o.id} className={o.is_correct ? 'rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200' : 'rounded-full bg-slate-50 px-2 py-0.5 text-xs text-slate-500'}>
                        {o.option_text}
                    </span>
                ))}
                {(q.options ?? []).length === 0 ? <span className="text-xs text-slate-400">No options</span> : null}
            </div>
        </div>
    );
}

function QuizSettingsForm({ settings, setSettings, onSubmit, saving, submitLabel, showFooter = true, banks = [], errors = {}, questionCount = 0 }) {
    if (!settings) return null;

    const bankId = settings.question_bank_id || '';
    const usesBank = Boolean(bankId);
    const bank = banks.find((b) => String(b.id) === String(bankId)) ?? null;

    const quotaFor = (type) => settings.blueprint?.find((rule) => rule.type === type)?.count ?? '';

    const setQuota = (type, raw) => {
        const others = (settings.blueprint ?? []).filter((rule) => rule.type !== type);
        const count = Number(raw);

        // A blank field removes the quota rather than storing a zero, so the
        // form does not send rules the server would only discard.
        setSettings((f) => ({
            ...f,
            blueprint: Number.isFinite(count) && count > 0 ? [...others, { type, count }] : others,
        }));
    };

    const quotaTotal = (settings.blueprint ?? []).reduce((sum, rule) => sum + (Number(rule.count) || 0), 0);
    const drawSize = Number(settings.draw_size) || 0;
    const oversubscribed = usesBank && drawSize > 0 && quotaTotal > drawSize;

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
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Field label="Passing %">
                    <Input type="number" min="0" max="100" value={settings.passing_score ?? 70} onChange={setSettings('passing_score')} />
                </Field>
                <Field label="Attempts">
                    <Input type="number" min="0" value={settings.attempts_allowed ?? 1} onChange={setSettings('attempts_allowed')} />
                </Field>
                <Field label="Time limit (min)" hint="0 = none.">
                    <Input type="number" min="0" value={settings.time_limit_minutes ?? ''} onChange={setSettings('time_limit_minutes')} />
                </Field>
                <Field label="Late grace (min)" hint="Minutes after the deadline a late submission is still accepted. Empty = none.">
                    <Input type="number" min="0" value={settings.late_grace_minutes ?? ''} onChange={setSettings('late_grace_minutes')} />
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Available from" hint="Empty = available immediately.">
                    <Input
                        type="datetime-local"
                        value={settings.available_from ?? ''}
                        onChange={setSettings('available_from')}
                    />
                </Field>
                <Field label="Available until" hint="Empty = no closing time." error={errors.available_until?.[0]}>
                    <Input
                        type="datetime-local"
                        min={settings.available_from || undefined}
                        value={settings.available_until ?? ''}
                        onChange={setSettings('available_until')}
                    />
                </Field>
            </div>

            <div className="rounded-lg border border-slate-200 p-4">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Questions from" hint="Pick a bank to draw a random paper per attempt.">
                        <Select
                            value={usesBank ? 'bank' : 'quiz'}
                            onChange={(e) => {
                                const next = e.target.value;
                                setSettings((f) => ({
                                    ...f,
                                    question_bank_id: next === 'bank' ? (f.question_bank_id || banks[0]?.id || '') : '',
                                    draw_size: next === 'bank' ? (f.draw_size || '') : '',
                                    // Quotas belong to the bank being drawn from, so
                                    // switching source starts the blueprint over
                                    // rather than carrying one bank's mix onto another.
                                    blueprint: next === 'bank' ? (f.blueprint ?? []) : [],
                                }));
                            }}
                        >
                            <option value="quiz">This quiz only</option>
                            <option value="bank">A question bank</option>
                        </Select>
                    </Field>

                    {usesBank ? (
                        <Field label="Bank" required error={errors.question_bank_id?.[0]}>
                            <Select
                                value={bankId}
                                disabled={questionCount > 0 || banks.length === 0}
                                onChange={(e) => setSettings((f) => ({ ...f, question_bank_id: e.target.value, draw_size: '', blueprint: [] }))}
                            >
                                {banks.length === 0 ? <option value="">No banks yet</option> : null}
                                {banks.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.title} ({b.questions_count})
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    ) : null}

                    {usesBank ? (
                        <Field
                            label="Questions per attempt"
                            required
                            hint={bank ? `The bank holds ${bank.questions_count}.` : undefined}
                            error={errors.draw_size?.[0]}
                        >
                            <Input
                                type="number"
                                min="1"
                                max={bank?.questions_count}
                                value={settings.draw_size ?? ''}
                                onChange={setSettings('draw_size')}
                            />
                        </Field>
                    ) : null}
                </div>

                {usesBank && banks.length === 0 ? (
                    <p className="mt-3 text-xs text-amber-700">
                        No question banks yet. Create one under <strong>Curriculum → Question banks</strong> first.
                    </p>
                ) : null}

                {questionCount > 0 ? (
                    <p className="mt-3 text-xs text-amber-700">
                        Delete this quiz&rsquo;s questions before switching to a bank — a quiz either owns its
                        questions or draws them from a bank.
                    </p>
                ) : null}

                {usesBank && questionCount > 0 ? (
                    <p className="mt-3 text-xs text-red-600">
                        This quiz still owns {questionCount} {questionCount === 1 ? 'question' : 'questions'} while
                        also drawing from a bank. Saving will be refused until they are removed.
                    </p>
                ) : null}

                {usesBank ? (
                    <div className="mt-4 border-t border-slate-200 pt-4">
                        <p className="text-sm font-medium text-slate-700">Blueprint</p>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Pin how many questions of each type the draw must serve. The remaining
                            {drawSize > quotaTotal && quotaTotal > 0 ? ` ${drawSize - quotaTotal}` : ''} of the paper is
                            filled from the types you leave blank.
                        </p>

                        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {QUESTION_TYPES.map((type) => {
                                const ruleIndex = (settings.blueprint ?? []).findIndex((rule) => rule.type === type.value);
                                const error = errors[`blueprint.${ruleIndex}.count`]?.[0];

                                return (
                                    <Field key={type.value} label={type.label} error={error}>
                                        <Input
                                            type="number"
                                            min="0"
                                            max={bank?.questions_count}
                                            placeholder="—"
                                            value={quotaFor(type.value)}
                                            onChange={(e) => setQuota(type.value, e.target.value)}
                                        />
                                    </Field>
                                );
                            })}
                        </div>

                        {oversubscribed ? (
                            <p className="mt-2 text-xs text-red-600">
                                The blueprint pins {quotaTotal} questions but each attempt only draws {drawSize}. There
                                is no room to serve them.
                            </p>
                        ) : null}

                        {errors.blueprint?.[0] ? <p className="mt-2 text-xs text-red-600">{errors.blueprint[0]}</p> : null}
                    </div>
                ) : null}
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

/* ------------------------------ Question banks ------------------------------ */

/**
 * Lists the course's banks and opens the authoring modal for one. A bank is a
 * reusable pool that quizzes draw from, so it is managed at course level rather
 * than inside any single quiz.
 */
function QuestionBanksModal({ open, onClose, courseSlug, run, busy, onManage }) {
    const [banks, setBanks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState(null); // { bank? }

    const base = `/api/instructor/courses/${courseSlug}/question-banks`;

    const load = useCallback(() => {
        api.get(base)
            .then(({ data }) => setBanks(data.banks ?? []))
            .catch(() => setBanks([]))
            .finally(() => setLoading(false));
    }, [base]);

    useEffect(() => {
        if (open) {
            setLoading(true);
            load();
        }
    }, [open, load]);

    const save = async (payload) => {
        const ok = await run(
            () => (editing?.bank
                ? api.post(`${base}/${editing.bank.id}?_method=PUT`, payload)
                : api.post(base, payload)),
            editing?.bank ? 'Question bank updated.' : 'Question bank created.',
        );
        if (ok) {
            setEditing(null);
            load();
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Question banks"
            size="lg"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Close</Button>
                    <Button icon="plus" onClick={() => setEditing({ bank: null })}>
                        New bank
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <p className="text-sm text-slate-500">
                    A bank is a pool of questions that a quiz can draw from, so every student gets a
                    different paper. Questions stay reusable across quizzes in this course.
                </p>

                {editing ? (
                    <form
                        className="space-y-4 rounded-lg border border-brand-200 bg-brand-50/50 p-4"
                        onSubmit={(e) => { e.preventDefault(); save({ title: editing.title, description: editing.description }); }}
                    >
                        <Field label="Bank title" required>
                            <Input autoFocus value={editing.title ?? ''} onChange={(e) => setEditing((s) => ({ ...s, title: e.target.value }))} />
                        </Field>
                        <Field label="Description" hint="Optional. Shown to instructors only.">
                            <Input value={editing.description ?? ''} onChange={(e) => setEditing((s) => ({ ...s, description: e.target.value }))} />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
                            <Button type="submit" icon="check" loading={busy} disabled={!editing.title?.trim()}>Save bank</Button>
                        </div>
                    </form>
                ) : null}

                {loading ? (
                    <PageLoader label="Loading banks." />
                ) : banks.length === 0 ? (
                    <EmptyState
                        icon="layers"
                        title="No question banks yet"
                        message="Create a bank to hold reusable questions, then point a quiz at it."
                        action={<Button icon="plus" onClick={() => setEditing({ bank: null, title: '' })}>Create a bank</Button>}
                    />
                ) : (
                    <ul className="space-y-2">
                        {banks.map((b) => (
                            <li key={b.id} className="rounded-lg border border-slate-200 p-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-slate-900">{b.title}</p>
                                        <p className="mt-0.5 text-xs text-slate-500">
                                            {b.questions_count} {b.questions_count === 1 ? 'question' : 'questions'}
                                            {b.questions_count > 0 ? ` · ${b.points_total} pt total` : ''}
                                            {b.quizzes_count > 0 ? ` · used by ${b.quizzes_count} ${b.quizzes_count === 1 ? 'quiz' : 'quizzes'}` : ' · not used yet'}
                                        </p>
                                        {b.questions_count === 0 ? (
                                            <p className="mt-1 text-xs text-amber-700">Add questions before a quiz can draw from it.</p>
                                        ) : null}
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1">
                                        <Button size="sm" variant="secondary" icon="listChecks" onClick={() => onManage(b)}>
                                            Questions
                                        </Button>
                                        <button
                                            type="button"
                                            className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                            onClick={() => setEditing({ bank: b, title: b.title, description: b.description ?? '' })}
                                            aria-label={`Edit ${b.title}`}
                                        >
                                            <Icon name="pencil" className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Modal>
    );
}

/** Authors the questions inside one bank, reusing the quiz question editor. */
function QuestionBankModal({ open, onClose, courseSlug, bank, run, busy, confirm }) {
    const [detail, setDetail] = useState(null);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null); // question being edited

    const base = `/api/instructor/courses/${courseSlug}/question-banks/${bank.id}`;

    const load = useCallback(() => {
        api.get(base)
            .then(({ data }) => setDetail(data.bank ?? null))
            .catch(() => setDetail(null));
    }, [base]);

    useEffect(() => {
        if (open) {
            setDetail(null);
            setCreating(false);
            setEditing(null);
            load();
        }
    }, [open, load]);

    const saveQuestion = async (payload) => {
        const res = await run(
            () => (editing
                ? api.post(`${base}/questions/${editing.id}?_method=PUT`, payload)
                : api.post(`${base}/questions`, payload)),
            null,
        );
        if (res) {
            toast(res.data?.message ?? 'Question saved.', 'success');
            setEditing(null);
            setCreating(false);
            load();
        }
    };

    const deleteQuestion = async (question) => {
        const ok = await run(() => api.delete(`${base}/questions/${question.id}`), 'Question deleted.');
        if (ok) load();
    };

    const questions = detail?.questions ?? [];
    const usedBy = detail?.quizzes ?? [];

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={`${bank.title} — questions`}
            size="lg"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>Close</Button>
                    {!creating && !editing ? (
                        <Button icon="plus" onClick={() => setCreating(true)}>Add question</Button>
                    ) : null}
                </>
            }
        >
            <div className="space-y-4">
                {usedBy.length > 0 ? (
                    <Alert tone="info" icon="info">
                        Used by {usedBy.map((q) => q.title).join(', ')}. Editing a question that has already been
                        served to a student creates a new version for future attempts; that student keeps the version
                        they answered.
                    </Alert>
                ) : null}

                {creating ? (
                    <QuestionForm onCancel={() => setCreating(false)} onSubmit={saveQuestion} saving={busy} />
                ) : null}

                {questions.map((q) => (
                    <QuestionListItem
                        key={q.id}
                        question={q}
                        inUse={Boolean(q.in_use)}
                        editing={editing?.id === q.id ? (
                            <QuestionForm initial={q} onCancel={() => setEditing(null)} onSubmit={saveQuestion} saving={busy} />
                        ) : null}
                        onEdit={() => {
                            if (q.in_use) {
                                confirm({
                                    title: 'Create a new version?',
                                    message: `This question has already been served to students. Saving your changes creates v${(q.version ?? 1) + 1} for future attempts; students who already attempted it keep the current version.`,
                                    action: () => setEditing(q),
                                });
                                return;
                            }
                            setEditing(q);
                        }}
                        onDelete={() => confirm({
                            title: 'Delete this question?',
                            message: 'It will be removed from the bank. Quizzes drawing from this bank will no longer be able to pick it.',
                            action: () => deleteQuestion(q),
                        })}
                    />
                ))}

                {!detail ? <PageLoader label="Loading questions." /> : null}

                {detail && questions.length === 0 && !creating ? (
                    <EmptyState
                        icon="listChecks"
                        title="No questions yet"
                        message="Add questions so a quiz can draw from this bank."
                        action={<Button icon="plus" onClick={() => setCreating(true)}>Add the first question</Button>}
                    />
                ) : null}
            </div>
        </Modal>
    );
}

function QuestionForm({ initial, onCancel, onSubmit, saving }) {
    const [form, setForm] = useState(() => ({
        type: initial?.type ?? 'multiple_choice',
        question_text: initial?.question_text ?? '',
        points: initial?.points ?? 1,
        explanation: initial?.explanation ?? '',
        settings: initial?.settings ?? {},
        options: initial?.options?.map((o) => ({ option_text: o.option_text, is_correct: Boolean(o.is_correct), explanation: o.explanation ?? '' })) ?? [
            { option_text: '', is_correct: false, explanation: '' },
            { option_text: '', is_correct: false, explanation: '' },
        ],
    }));

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const setSetting = (key, value) => setForm((f) => ({ ...f, settings: { ...f.settings, [key]: value } }));
    const setOption = (i, key, value) => setForm((f) => ({ ...f, options: f.options.map((o, idx) => (idx === i ? { ...o, [key]: value } : o)) }));

    /**
     * The accepted answers for a fill-in-the-blank, as a list of blank indexes so
     * the editor can render one row per placeholder found in the text.
     */
    const blanks = useMemo(() => blankIndexes(form.question_text), [form.question_text]);

    const setBlankAlternatives = (index, value) => {
        setSetting('blanks', {
            ...form.settings?.blanks,
            [index]: value.split('|').map((s) => s.trim()).filter(Boolean),
        });
    };

    const setRubricCriterion = (index, patch) =>
        setSetting('rubric', (form.settings?.rubric ?? []).map((c, i) => (i === index ? { ...c, ...patch } : c)));

    const addRubricCriterion = () =>
        setSetting('rubric', [...(form.settings?.rubric ?? []), { label: '', keywords: [], points: '' }]);

    const removeRubricCriterion = (index) =>
        setSetting('rubric', (form.settings?.rubric ?? []).filter((_, i) => i !== index));

    const rubricCriteria = () =>
        (form.settings?.rubric ?? []).filter(
            (c) => c.label?.trim() && Number(c.points) > 0 && (c.keywords ?? []).some((k) => k.trim()),
        );

    const submit = () => {
        const payload = { type: form.type, question_text: form.question_text, points: Number(form.points) || 1, explanation: form.explanation };

        if (form.type === 'short_answer') {
            // The grader reads the key from the first option flagged correct, so
            // the typed answer has to be sent as a real option row.
            payload.options = [{ option_text: form.options[0]?.option_text ?? '', is_correct: true, explanation: '' }];
            payload.settings = { negative_marking: Number(form.settings?.negative_marking ?? 0) };

            // A rubric swaps scoring from an exact match to criteria, and only
            // counts once it has at least one complete row -- an in-progress
            // editorial stub is the same as no rubric.
            const rubric = rubricCriteria().map((c) => ({
                label: c.label.trim(),
                keywords: c.keywords.map((k) => k.trim()).filter(Boolean),
                points: Number(c.points),
            }));

            if (rubric.length > 0) {
                payload.settings.rubric = rubric;
            }
        } else if (form.type === 'true_false') {
            payload.options = [
                { option_text: 'True', is_correct: form.options?.[0]?.is_correct === true, explanation: form.options?.[0]?.explanation },
                { option_text: 'False', is_correct: form.options?.[1]?.is_correct === true, explanation: form.options?.[1]?.explanation },
            ];
        } else if (form.type === 'numeric' || form.type === 'fill_in_blank') {
            // These keep their answer key in settings, never in options, because
            // every option row is rendered to the student and would show the key.
            payload.options = [];
            payload.settings = { ...form.settings };

            // The negative-marking field types into a number input, which hands
            // back a string. Normalise it so the stored JSON holds a number.
            if (payload.settings.negative_marking !== undefined) {
                payload.settings.negative_marking = Number(payload.settings.negative_marking ?? 0);
            }

            // A cleared tolerance arrives as an empty string, which the server
            // rejects as non-numeric. Drop it so the default of zero applies.
            if (form.type === 'numeric' && payload.settings.tolerance === '') {
                delete payload.settings.tolerance;
            }
        } else {
            payload.options = form.options.filter((o) => o.option_text.trim());

            // Negative marking is the one setting every type accepts, so it is
            // sent explicitly (rather than left to whatever the form holds):
            // the server rejects settings that do not apply to a type, and a
            // stale carry-over would break a save.
            if (form.type === 'multi_select') {
                payload.settings = {
                    partial_credit: Boolean(form.settings?.partial_credit),
                    negative_marking: Number(form.settings?.negative_marking ?? 0),
                };
            } else {
                payload.settings = { negative_marking: Number(form.settings?.negative_marking ?? 0) };
            }
        }

        onSubmit(payload);
    };

    const setCorrect = (i) => {
        if (form.type === 'true_false') {
            const next = form.options.map((o, idx) => ({ ...o, is_correct: idx === i }));
            set('options', next);
        } else if (form.type === 'multiple_choice') {
            set('options', form.options.map((o, idx) => ({ ...o, is_correct: idx === i })));
        } else {
            setOption(i, 'is_correct', !form.options[i].is_correct);
        }
    };

    const filledOptionCount = form.options.filter((o) => o.option_text.trim()).length;
    const correctOptionCount = form.options.filter((o) => o.is_correct).length;
    const numericAnswer = String(form.settings?.answer ?? '').trim();
    const numericTolerance = String(form.settings?.tolerance ?? '').trim();
    const blankAnswersPresent = blanks.length > 0 && blanks.every((b) => (form.settings?.blanks?.[b] ?? []).length > 0);

    /**
     * Mirrors the server's own rules so an author finds out what is missing
     * before submitting, rather than from a 422. The server remains the
     * authority; this only avoids the obviously-rejected cases.
     */
    const typeIsComplete = {
        short_answer: String(form.options[0]?.option_text ?? '').trim() !== '',
        // True/false option text is fixed, so counting filled rows here would
        // never pass even once the author has chosen the correct side.
        true_false: correctOptionCount >= 1,
        numeric: numericAnswer !== '' && Number.isFinite(Number(numericAnswer)) && (numericTolerance === '' || Number.isFinite(Number(numericTolerance))),
        fill_in_blank: blankAnswersPresent,
        multiple_choice: filledOptionCount >= 2 && correctOptionCount >= 1,
        multi_select: filledOptionCount >= 2 && correctOptionCount >= 1,
    }[form.type];

    const canSave = form.question_text.trim() !== '' && typeIsComplete;

    return (
        <div className="rounded-lg border border-brand-200 bg-brand-50/50 p-4">
            <div className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-[1fr_120px_120px]">
                    <Field label="Question text" required>
                        <Input value={form.question_text} onChange={(e) => set('question_text', e.target.value)} />
                    </Field>
                    <Field label="Type">
                        <Select
                            value={form.type}
                            onChange={(e) => {
                                set('type', e.target.value);
                                // Settings belong to the type they were written
                                // for, and the backend rejects the mismatch, so
                                // start clean rather than carrying them over.
                                set('settings', {});
                            }}
                        >
                            {QUESTION_TYPES.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Points">
                        <Input type="number" min="1" value={form.points} onChange={(e) => set('points', e.target.value)} />
                    </Field>
                </div>

                {form.type === 'short_answer' ? (
                    <>
                        <Field
                            label="Correct answer (exact, case-insensitive)"
                            required
                            hint={rubricCriteria().length > 0 ? 'Only used when the rubric has no complete criteria.' : null}
                        >
                            <Input
                                value={form.options[0]?.option_text ?? ''}
                                onChange={(e) => setOption(0, 'option_text', e.target.value)}
                            />
                        </Field>

                        <Field
                            label="Rubric (score by criteria instead of exact match)"
                            hint="An answer that is wrong overall but names the right ideas earns partial credit. Keywords stay hidden from students; whole-term matches, one hit per criterion."
                        >
                            <div className="space-y-2">
                                {(form.settings?.rubric ?? []).map((criterion, index) => (
                                    <div key={index} className="space-y-2 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <div className="grid gap-2 sm:grid-cols-[1fr_9rem]">
                                            <Input
                                                value={criterion.label ?? ''}
                                                onChange={(e) => setRubricCriterion(index, { label: e.target.value })}
                                                placeholder="Looks for the principle, e.g. States Newton's second law"
                                            />
                                            <Input
                                                type="number"
                                                step="0.25"
                                                min="0.1"
                                                value={criterion.points ?? ''}
                                                onChange={(e) => setRubricCriterion(index, { points: e.target.value })}
                                                placeholder="Points"
                                            />
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                value={(criterion.keywords ?? []).join(' | ')}
                                                onChange={(e) =>
                                                    setRubricCriterion(index, {
                                                        keywords: e.target.value.split('|').map((s) => s.trim()).filter(Boolean),
                                                    })
                                                }
                                                placeholder="Keywords, e.g. force equals mass times acceleration | F=ma"
                                            />
                                            <Button variant="secondary" size="sm" onClick={() => removeRubricCriterion(index)}>
                                                Remove
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                                <Button variant="secondary" size="sm" icon="plus" onClick={addRubricCriterion}>
                                    Add criterion
                                </Button>
                            </div>
                        </Field>
                    </>
                ) : form.type === 'true_false' ? (
                    <div className="grid gap-2 sm:grid-cols-2">
                        {[['True', 0], ['False', 1]].map(([label, idx]) => (
                            <label key={label} className="flex cursor-pointer items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 ring-1 ring-slate-200">
                                <span className="text-sm font-medium text-slate-700">{label}</span>
                                <input type="radio" name="tf" checked={form.options[idx]?.is_correct} onChange={() => setCorrect(idx)} className="h-4 w-4 accent-brand-600" />
                            </label>
                        ))}
                    </div>
                ) : form.type === 'numeric' ? (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Accepted answer" required hint="Never shown to students.">
                            <Input
                                type="number"
                                step="any"
                                value={form.settings?.answer ?? ''}
                                onChange={(e) => setSetting('answer', e.target.value)}
                            />
                        </Field>
                        <Field label="Tolerance" hint="Absolute margin that still counts, e.g. 0.05.">
                            <Input
                                type="number"
                                step="any"
                                min="0"
                                value={form.settings?.tolerance ?? 0}
                                onChange={(e) => setSetting('tolerance', e.target.value)}
                            />
                        </Field>
                    </div>
                ) : form.type === 'fill_in_blank' ? (
                    <div className="space-y-2">
                        <p className="text-xs text-slate-500">
                            Put <code className="rounded bg-slate-100 px-1">{'{{1}}'}</code> in the question text where each
                            blank goes. Accepted answers are hidden from students; separate alternatives with a pipe.
                        </p>
                        {blanks.length === 0 ? (
                            <p className="text-sm font-medium text-amber-700">Add a {'{{1}}'} placeholder to the question text.</p>
                        ) : (
                            blanks.map((index) => (
                                <Field key={index} label={`Accepted answers for blank ${index}`} required>
                                    <Input
                                        value={(form.settings?.blanks?.[index] ?? []).join(' | ')}
                                        onChange={(e) => setBlankAlternatives(index, e.target.value)}
                                        placeholder="Paris | City of Light"
                                    />
                                </Field>
                            ))
                        )}
                        <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                            <input
                                type="checkbox"
                                checked={Boolean(form.settings?.partial_credit)}
                                onChange={(e) => setSetting('partial_credit', e.target.checked)}
                                className="h-4 w-4 accent-brand-600"
                            />
                            Award partial credit per blank
                        </label>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {form.type === 'multi_select' ? (
                            <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.settings?.partial_credit)}
                                    onChange={(e) => setSetting('partial_credit', e.target.checked)}
                                    className="h-4 w-4 accent-brand-600"
                                />
                                Award partial credit ({'(correct − wrong) ÷ total correct'})
                            </label>
                        ) : null}
                        {form.options.map((option, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <input
                                    type={form.type === 'multi_select' ? 'checkbox' : 'radio'}
                                    name={form.type === 'multiple_choice' ? `mc-${initial?.id ?? 'new'}` : undefined}
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

                <Field label="Negative marking" hint="Fraction of the points a wrong, answered option deducts. 0.25 on a 2-point question costs 0.5. Blank questions are never penalised.">
                    <Input
                        type="number"
                        step="0.05"
                        min="0"
                        max="1"
                        value={form.settings?.negative_marking ?? 0}
                        onChange={(e) => setSetting('negative_marking', e.target.value)}
                    />
                </Field>

                <Field label="Explanation (shown to students after the quiz)">
                    <Textarea value={form.explanation ?? ''} onChange={(e) => set('explanation', e.target.value)} className="min-h-16" />
                </Field>

                <div className="flex justify-end gap-2">
                    <Button variant="secondary" size="sm" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button loading={saving} size="sm" icon="check" onClick={submit} disabled={!canSave}>
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