import { useCallback, useEffect, useState } from 'react';
import api, { apiError } from '../../api';
import { Badge, Button, Card, ConfirmDialog, EmptyState, Field, Icon, Input, Modal, PageHeader, PageLoader, useToast } from '../../components/ui';

export default function AdminRoles() {
    const toast = useToast();
    const [roles, setRoles] = useState(null);
    const [permissions, setPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(null);
    const [deleting, setDeleting] = useState(false);

    const fetchData = useCallback(() => {
        setLoading(true);
        api.get('/api/admin/roles')
            .then(({ data }) => {
                setRoles(data.roles);
                setPermissions(data.permissions);
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const openCreate = () => {
        setEditing(null);
        setModalOpen(true);
    };

    const openEdit = (role) => {
        setEditing(role);
        setModalOpen(true);
    };

    const handleSave = async (form) => {
        setSaving(true);
        try {
            if (editing) {
                const { data } = await api.put(`/api/admin/roles/${editing.id}`, form);
                toast(data.message || 'Role updated.', 'success');
            } else {
                const { data } = await api.post('/api/admin/roles', form);
                toast(data.message || 'Role created.', 'success');
            }
            setModalOpen(false);
            setEditing(null);
            fetchData();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async () => {
        if (!confirmDelete) return;
        setDeleting(true);
        try {
            const { data } = await api.delete(`/api/admin/roles/${confirmDelete.id}`);
            toast(data.message || 'Role deleted.', 'success');
            setConfirmDelete(null);
            fetchData();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setDeleting(false);
        }
    };

    if (loading) {
        return <PageLoader label="Loading roles…" />;
    }

    return (
        <div className="space-y-6">
            <PageHeader
                icon="shield"
                title="Roles & permissions"
                subtitle="Manage roles and their associated permissions."
                actions={
                    <Button icon="plus" onClick={openCreate}>
                        New role
                    </Button>
                }
            />

            {(roles ?? []).length === 0 ? (
                <EmptyState
                    icon="shield"
                    title="No roles yet"
                    message="Create your first role to get started."
                    action={
                        <Button icon="plus" onClick={openCreate}>
                            New role
                        </Button>
                    }
                />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {roles.map((role) => (
                        <Card key={role.id} className="flex flex-col p-5">
                            <div className="mb-3 flex items-start justify-between">
                                <div className="min-w-0">
                                    <h3 className="font-semibold text-slate-900">{role.label}</h3>
                                    <Badge color="violet" className="mt-1">{role.name}</Badge>
                                </div>
                                <span className="shrink-0 text-xs text-slate-500">
                                    {role.users_count ?? 0} users
                                </span>
                            </div>
                            <div className="mb-4 flex flex-1 flex-wrap gap-1">
                                {role.permissions.map((perm) => (
                                    <Badge key={perm} color="slate" className="text-[11px]">{perm}</Badge>
                                ))}
                                {role.permissions.length === 0 && (
                                    <span className="text-xs text-slate-400">No permissions</span>
                                )}
                            </div>
                            <div className="flex items-center gap-2 border-t border-slate-100 pt-3">
                                <Button variant="ghost" size="sm" icon="pencil" onClick={() => openEdit(role)}>
                                    Edit
                                </Button>
                                <Button variant="ghost" size="sm" icon="trash" className="text-red-500 hover:bg-red-50 hover:text-red-600" onClick={() => setConfirmDelete(role)}>
                                    Delete
                                </Button>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <RoleModal
                open={modalOpen}
                onClose={() => { setModalOpen(false); setEditing(null); }}
                saving={saving}
                editing={editing}
                permissions={permissions}
                onSubmit={handleSave}
            />

            <ConfirmDialog
                open={!!confirmDelete}
                onClose={() => setConfirmDelete(null)}
                title="Delete role"
                message={`Are you sure you want to delete ${confirmDelete?.label}? This action cannot be undone.`}
                confirmLabel="Delete role"
                icon="trash"
                tone="danger"
                loading={deleting}
                onConfirm={handleDelete}
            />
        </div>
    );
}

function RoleModal({ open, onClose, saving, editing, permissions, onSubmit }) {
    const [form, setForm] = useState({ name: '', label: '', permissions: [] });

    useEffect(() => {
        if (editing) {
            setForm({
                name: editing.name,
                label: editing.label,
                permissions: editing.permissions ?? [],
            });
        } else {
            setForm({ name: '', label: '', permissions: [] });
        }
    }, [editing, open]);

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    const togglePermission = (permName) => {
        setForm((f) => ({
            ...f,
            permissions: f.permissions.includes(permName)
                ? f.permissions.filter((p) => p !== permName)
                : [...f.permissions, permName],
        }));
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={editing ? 'Edit role' : 'Create a new role'}
            size="lg"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button loading={saving} icon="check" onClick={() => onSubmit(form)}>
                        {editing ? 'Save changes' : 'Create role'}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Name" required hint="Unique machine-readable identifier.">
                        <Input value={form.name} onChange={set('name')} placeholder="e.g. editor" />
                    </Field>
                    <Field label="Label" required hint="Human-friendly display name.">
                        <Input value={form.label} onChange={set('label')} placeholder="e.g. Editor" />
                    </Field>
                </div>
                <div>
                    <span className="mb-2 block text-sm font-medium text-slate-700">Permissions</span>
                    <div className="max-h-64 overflow-y-auto rounded-lg border border-slate-200 p-3">
                        {permissions.length === 0 ? (
                            <p className="text-xs text-slate-400">No permissions available.</p>
                        ) : (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {permissions.map((perm) => (
                                    <label key={perm.id} className="flex items-center gap-2 text-sm text-slate-700 hover:text-slate-900">
                                        <input
                                            type="checkbox"
                                            checked={form.permissions.includes(perm.name)}
                                            onChange={() => togglePermission(perm.name)}
                                            className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                                        />
                                        {perm.name}
                                    </label>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </Modal>
    );
}
