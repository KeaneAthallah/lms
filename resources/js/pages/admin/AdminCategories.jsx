import { useCallback, useEffect, useState } from 'react';
import api, { apiError } from '../../api';
import { Badge, Button, Card, ConfirmDialog, EmptyState, Field, Icon, Input, Modal, PageHeader, PageLoader, Paginator, Select, Textarea, useToast } from '../../components/ui';

const iconOptions = ['book', 'code', 'cpu', 'camera', 'music', 'tools', 'heart', 'globe', 'briefcase', 'chart'];

export default function AdminCategories() {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');

    const [editorOpen, setEditorOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(false);

    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(null);
    const [deleteInProgress, setDeleteInProgress] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (page > 1) params.set('page', page);
        api.get(`/api/admin/categories?${params.toString()}`)
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [page, search]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        const timer = setTimeout(() => {
            setSearch(searchInput);
            setPage(1);
        }, 350);
        return () => clearTimeout(timer);
    }, [searchInput]);

    const openCreate = () => {
        setEditing(null);
        setEditorOpen(true);
    };

    const openEdit = (category) => {
        setEditing(category);
        setEditorOpen(true);
    };

    const save = async (form) => {
        setSaving(true);
        try {
            const payload = { name: form.name, description: form.description, icon: form.icon, is_active: form.is_active };
            const { data: result } = editing
                ? await api.put(`/api/admin/categories/${editing.id}`, payload)
                : await api.post('/api/admin/categories', payload);
            toast(result.message ?? (editing ? 'Category updated.' : 'Category created.'), 'success');
            setEditorOpen(false);
            load();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setSaving(false);
        }
    };

    const confirmDelete = async () => {
        setDeleteInProgress(true);
        try {
            const { data: result } = await api.delete(`/api/admin/categories/${deleting.id}`);
            toast(result.message ?? 'Category deleted.', 'success');
            setDeleteOpen(false);
            if ((data?.categories ?? []).length === 1 && page > 1) {
                setPage(page - 1);
            } else {
                load();
            }
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setDeleteInProgress(false);
        }
    };

    const categories = data?.categories ?? [];

    return (
        <div className="space-y-6">
            <PageHeader
                title="Categories"
                subtitle="Organize your catalog into categories so students can browse."
                actions={
                    <Button icon="plus" onClick={openCreate}>
                        New category
                    </Button>
                }
            />

            <Input
                placeholder="Search categories…"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                className="max-w-sm"
            />

            {loading ? (
                <PageLoader label="Loading categories…" />
            ) : categories.length === 0 ? (
                <EmptyState
                    icon="grid"
                    title="No categories yet"
                    message="Create a category to organize your courses."
                    action={
                        <Button icon="plus" onClick={openCreate}>
                            New category
                        </Button>
                    }
                />
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {categories.map((category) => (
                            <Card key={category.id} className="flex flex-col p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                            <Icon name={category.icon || 'book'} className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0">
                                            <h3 className="truncate font-semibold text-slate-900">{category.name}</h3>
                                            <p className="truncate text-xs text-slate-500">{category.slug}</p>
                                        </div>
                                    </div>
                                    <Badge color={category.is_active ? 'green' : 'slate'} dot>
                                        {category.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </div>
                                <p className="mt-3 line-clamp-2 flex-1 text-sm text-slate-600">{category.description}</p>
                                <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-3">
                                    <Badge color="slate">
                                        <Icon name="video" className="h-3 w-3" />
                                        {category.courses_count ?? 0} courses
                                    </Badge>
                                    <div className="flex gap-1">
                                        <Button variant="secondary" size="sm" icon="pencil" onClick={() => openEdit(category)}>
                                            Edit
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            onClick={() => {
                                                setDeleting(category);
                                                setDeleteOpen(true);
                                            }}
                                            className="text-red-600 hover:bg-red-50"
                                        >
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                    <Paginator meta={data?.meta} onPage={setPage} />
                </>
            )}

            <CategoryModal
                open={editorOpen}
                onClose={() => setEditorOpen(false)}
                category={editing}
                saving={saving}
                onSubmit={save}
            />

            <ConfirmDialog
                open={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                title="Delete category"
                message={`Are you sure you want to delete ${deleting?.name}? This action cannot be undone.`}
                confirmLabel="Delete category"
                icon="trash"
                tone="danger"
                loading={deleteInProgress}
                onConfirm={confirmDelete}
            />
        </div>
    );
}

function CategoryModal({ open, onClose, category, saving, onSubmit }) {
    const [form, setForm] = useState({ name: '', description: '', icon: 'book', is_active: true });

    useEffect(() => {
        if (open) {
            setForm({
                name: category?.name ?? '',
                description: category?.description ?? '',
                icon: category?.icon || 'book',
                is_active: category?.is_active ?? true,
            });
        }
    }, [open, category]);

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={category ? 'Edit category' : 'New category'}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button loading={saving} icon={category ? 'check' : 'plus'} onClick={() => onSubmit(form)}>
                        {category ? 'Save changes' : 'Create category'}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Name" required>
                    <Input value={form.name} onChange={set('name')} placeholder="e.g. Web Development" />
                </Field>
                <Field label="Description">
                    <Textarea value={form.description} onChange={set('description')} placeholder="Short description of this category." />
                </Field>
                <Field label="Icon">
                    <Select value={form.icon} onChange={set('icon')}>
                        {iconOptions.map((icon) => (
                            <option key={icon} value={icon}>
                                {icon}
                            </option>
                        ))}
                    </Select>
                </Field>
                <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                    <input
                        type="checkbox"
                        checked={form.is_active}
                        onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
                        className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                    />
                    Active category
                </label>
            </div>
        </Modal>
    );
}