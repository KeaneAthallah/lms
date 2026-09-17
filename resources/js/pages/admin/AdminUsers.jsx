import { useCallback, useEffect, useState } from 'react';
import api, { apiError } from '../../api';
import {
    Badge,
    Button,
    Card,
    ConfirmDialog,
    EmptyState,
    Field,
    Icon,
    Input,
    Modal,
    PageHeader,
    PageLoader,
    Paginator,
    Select,
    Avatar,
    StatusBadge,
    useToast,
    formatDate,
} from '../../components/ui';

const roleColor = { student: 'blue', instructor: 'violet', admin: 'amber' };

export default function AdminUsers() {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [role, setRole] = useState('');
    const [inactive, setInactive] = useState('');

    const [createOpen, setCreateOpen] = useState(false);
    const [saving, setSaving] = useState(false);

    const [editUser, setEditUser] = useState(null);
    const [editOpen, setEditOpen] = useState(false);

    const [deleteUser, setDeleteUser] = useState(null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (role) params.set('role', role);
        if (inactive) params.set('inactive', inactive);
        if (page > 1) params.set('page', page);
        api.get(`/api/admin/users?${params.toString()}`)
            .then(({ data }) => setData(data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [search, role, inactive, page]);

    useEffect(() => {
        load();
    }, [load]);

    const create = async (form) => {
        setSaving(true);
        try {
            await api.post('/api/admin/users', form);
            toast('User created.', 'success');
            setCreateOpen(false);
            setPage(1);
            setSearch('');
            setRole('');
            setInactive('');
            setData(null);
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setSaving(false);
        }
    };

    const update = async (form) => {
        setSaving(true);
        try {
            await api.put(`/api/admin/users/${editUser.id}`, form);
            toast('User updated.', 'success');
            setEditOpen(false);
            setEditUser(null);
            load();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setSaving(false);
        }
    };

    const destroy = async () => {
        setDeleting(true);
        try {
            await api.delete(`/api/admin/users/${deleteUser.id}`);
            toast('User deleted.', 'success');
            setDeleteOpen(false);
            setDeleteUser(null);
            load();
        } catch (err) {
            toast(apiError(err), 'error');
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                title="Users"
                subtitle="Manage student, instructor, and admin accounts."
                actions={
                    <Button icon="plus" onClick={() => setCreateOpen(true)}>
                        Add user
                    </Button>
                }
            />

            <div className="flex flex-wrap gap-3">
                <Input
                    placeholder="Search users…"
                    defaultValue={search}
                    onChange={(e) => {
                        setPage(1);
                        clearTimeout(window.__userSearch);
                        window.__userSearch = setTimeout(() => setSearch(e.target.value), 350);
                    }}
                    className="max-w-sm"
                />
                <Select
                    value={role}
                    onChange={(e) => { setPage(1); setRole(e.target.value); }}
                    className="w-44"
                >
                    <option value="">All roles</option>
                    <option value="student">Student</option>
                    <option value="instructor">Instructor</option>
                    <option value="admin">Admin</option>
                </Select>
                <Select
                    value={inactive}
                    onChange={(e) => { setPage(1); setInactive(e.target.value); }}
                    className="w-44"
                >
                    <option value="">All</option>
                    <option value="0">Active</option>
                    <option value="1">Inactive</option>
                </Select>
            </div>

            {loading ? (
                <PageLoader label="Loading users…" />
            ) : (data?.data ?? []).length === 0 ? (
                <EmptyState
                    icon="users"
                    title="No users found"
                    message="Try adjusting your search or filters, or add a new user."
                    action={
                        <Button icon="plus" onClick={() => setCreateOpen(true)}>
                            Add user
                        </Button>
                    }
                />
            ) : (
                <>
                    <Card>
                        <div className="divide-y divide-slate-100">
                            {(data?.data ?? []).map((user) => (
                                <div key={user.id} className="flex items-center gap-4 px-5 py-4">
                                    <Avatar src={user.avatar_url} name={user.name} size="h-10 w-10" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold text-slate-900">{user.name}</p>
                                        <p className="truncate text-xs text-slate-500">{user.email}</p>
                                    </div>
                                    <div className="hidden flex-wrap gap-1 sm:flex">
                                        {(user.role_labels ?? []).map((label, i) => (
                                            <Badge key={user.roles?.[i] ?? i} color={roleColor[user.roles?.[i]] ?? 'slate'}>
                                                {label}
                                            </Badge>
                                        ))}
                                    </div>
                                    <StatusBadge status={user.is_active ? 'active' : 'inactive'} />
                                    <span className="hidden text-xs text-slate-500 sm:block">
                                        {formatDate(user.created_at)}
                                    </span>
                                    <div className="flex shrink-0 gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil"
                                            onClick={() => { setEditUser(user); setEditOpen(true); }}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            onClick={() => { setDeleteUser(user); setDeleteOpen(true); }}
                                        >
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>
                    <Paginator meta={data?.meta} onPage={setPage} />
                </>
            )}

            <CreateUserModal open={createOpen} onClose={() => setCreateOpen(false)} saving={saving} onSubmit={create} />
            <EditUserModal open={editOpen} onClose={() => { setEditOpen(false); setEditUser(null); }} saving={saving} onSubmit={update} user={editUser} />
            <ConfirmDialog
                open={deleteOpen}
                onClose={() => { setDeleteOpen(false); setDeleteUser(null); }}
                title="Delete user"
                message={`Are you sure you want to delete ${deleteUser?.name}? This action cannot be undone.`}
                confirmLabel="Delete user"
                icon="trash"
                tone="danger"
                loading={deleting}
                onConfirm={destroy}
            />
        </div>
    );
}

function CreateUserModal({ open, onClose, saving, onSubmit }) {
    const [form, setForm] = useState({ name: '', email: '', password: '', role: 'student' });

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Add a new user"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button loading={saving} icon="plus" onClick={() => onSubmit(form)}>
                        Create user
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Name" required>
                    <Input value={form.name} onChange={set('name')} placeholder="Full name" />
                </Field>
                <Field label="Email" required>
                    <Input type="email" value={form.email} onChange={set('email')} placeholder="user@example.com" />
                </Field>
                <Field label="Password" required>
                    <Input type="password" value={form.password} onChange={set('password')} placeholder="Minimum 8 characters" />
                </Field>
                <Field label="Role">
                    <Select value={form.role} onChange={set('role')}>
                        <option value="student">Student</option>
                        <option value="instructor">Instructor</option>
                        <option value="admin">Admin</option>
                    </Select>
                </Field>
            </div>
        </Modal>
    );
}

function EditUserModal({ open, onClose, saving, onSubmit, user }) {
    const [form, setForm] = useState({ name: '', email: '', role: 'student', is_active: true, password: '' });

    useEffect(() => {
        if (user) {
            setForm({ name: user.name ?? '', email: user.email ?? '', role: user.roles?.[0] ?? 'student', is_active: user.is_active ?? true, password: '' });
        }
    }, [user]);

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    const toggleActive = () => setForm((f) => ({ ...f, is_active: !f.is_active }));

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Edit user"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button loading={saving} icon="check" onClick={() => onSubmit(form)}>
                        Save changes
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Name" required>
                    <Input value={form.name} onChange={set('name')} />
                </Field>
                <Field label="Email" required>
                    <Input type="email" value={form.email} onChange={set('email')} />
                </Field>
                <Field label="Role">
                    <Select value={form.role} onChange={set('role')}>
                        <option value="student">Student</option>
                        <option value="instructor">Instructor</option>
                        <option value="admin">Admin</option>
                    </Select>
                </Field>
                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input
                        type="checkbox"
                        checked={form.is_active}
                        onChange={toggleActive}
                        className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                    />
                    Active
                </label>
                <Field label="Password" hint="Leave blank to keep current password.">
                    <Input type="password" value={form.password} onChange={set('password')} placeholder="New password (optional)" />
                </Field>
            </div>
        </Modal>
    );
}
