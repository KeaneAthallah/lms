import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../auth';
import { useTheme } from '../theme';
import { Avatar, Badge, cx, Icon } from './ui';
import FooterSection from './FooterSection';

function Brand({ className, subtitle }) {
    return (
        <Link to="/" className={cx('group flex items-center gap-2.5', className)}>
            <span className="relative flex h-10 w-10 items-center justify-center rounded-md border border-brand-200 bg-white text-brand-700 shadow-sm transition group-hover:border-brand-300">
                <Icon name="book" className="h-5 w-5" strokeWidth={2} />
                <span className="absolute -bottom-0.5 -right-0.5 h-2 w-2 rounded-full border border-white bg-brand-600" aria-hidden="true" />
            </span>
            <span className="flex min-w-0 flex-col leading-tight">
                <span className="truncate text-base font-bold tracking-tight text-slate-900">
                    {window.__LMS_CONFIG__?.institutionName ?? 'LMS'}
                </span>
                {subtitle ? (
                    <span className="truncate text-[10px] font-semibold uppercase tracking-wider text-slate-500">{subtitle}</span>
                ) : null}
            </span>
        </Link>
    );
}

function NotificationBell() {
    const [count, setCount] = useState(0);
    const location = useLocation();

    useEffect(() => {
        let active = true;
        api.get('/api/notifications')
            .then(({ data }) => {
                if (active) setCount(data.unread_count ?? 0);
            })
            .catch(() => {});
        return () => {
            active = false;
        };
    }, [location.pathname]);

    useEffect(() => {
        const listener = () => {
            api.get('/api/notifications')
                .then(({ data }) => setCount(data.unread_count ?? 0))
                .catch(() => {});
        };
        window.addEventListener('lms:notifications', listener);
        window.addEventListener('focus', listener);
        return () => {
            window.removeEventListener('lms:notifications', listener);
            window.removeEventListener('focus', listener);
        };
    }, []);

    return (
        <Link
            to="/notifications"
            className="relative flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"
            aria-label="Notifications"
        >
            <Icon name="bell" className="h-5 w-5" />
            {count > 0 ? (
                <span className="absolute right-1.5 top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-white">
                    {count > 9 ? '9+' : count}
                </span>
            ) : null}
        </Link>
    );
}

function ThemeToggle() {
    const { preference, resolved, setTheme } = useTheme();
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        const onClick = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    const options = [
        { value: 'light', label: 'Light', description: 'Selalu terang', icon: 'sun' },
        { value: 'dark', label: 'Dark', description: 'Selalu gelap', icon: 'moon' },
        { value: 'system', label: 'System', description: 'Ikuti perangkat', icon: 'monitor' },
    ];
    const buttonIcon = preference === 'dark' ? 'moon' : preference === 'light' ? 'sun' : 'monitor';

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"
                aria-label="Ubah tema"
                aria-haspopup="listbox"
                aria-expanded={open}
                title="Ubah tema"
            >
                <Icon name={buttonIcon} className="h-4.5 w-4.5" />
            </button>

            {open ? (
                <div
                    role="listbox"
                    aria-label="Tema tampilan"
                    className="absolute right-0 z-50 mt-2 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-popover"
                >
                    {options.map((option) => {
                        const active = preference === option.value;
                        return (
                            <button
                                key={option.value}
                                type="button"
                                role="option"
                                aria-selected={active}
                                onClick={() => {
                                    setTheme(option.value);
                                    setOpen(false);
                                }}
                                className="flex w-full items-center gap-3 px-4 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50"
                            >
                                <Icon name={option.icon} className={cx('h-4 w-4', active ? 'text-brand-600' : 'text-slate-400')} />
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium">{option.label}</span>
                                    <span className="block truncate text-xs text-slate-500">{option.description}</span>
                                </span>
                                {active ? <Icon name="check" className="h-4 w-4 text-brand-600" strokeWidth={2.5} /> : null}
                            </button>
                        );
                    })}
                    <p className="border-t border-slate-100 px-4 py-1.5 text-[10px] uppercase tracking-wide text-slate-400">
                        Saat ini: {resolved === 'dark' ? 'Dark' : 'Light'}
                    </p>
                </div>
            ) : null}
        </div>
    );
}

function UserMenu() {
    const { user, logout } = useAuth();
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        const onClick = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-slate-100"
                aria-haspopup="menu"
            >
                <Avatar src={user.avatar_url ?? user.avatar} name={user.name} size="h-9 w-9" />
                <span className="hidden text-sm font-semibold text-slate-800 xl:block">{user.name.split(' ')[0]}</span>
                <Icon name="chevronDown" className="hidden h-4 w-4 text-slate-400 xl:block" />
            </button>

            {open ? (
                <div className="absolute right-0 z-50 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-popover">
                    <div className="border-b border-slate-100 px-4 py-3">
                        <p className="truncate text-sm font-semibold text-slate-900">{user.name}</p>
                        <p className="truncate text-xs text-slate-500">{user.email}</p>
                        <div className="mt-1.5 flex flex-wrap gap-1">
                            {user.roles?.map((role) => (
                                <Badge key={role} color={role === 'admin' ? 'violet' : role === 'instructor' ? 'amber' : 'blue'}>
                                    {role}
                                </Badge>
                            ))}
                        </div>
                    </div>
                    <MenuItem to="/profile" icon="user" onClick={() => setOpen(false)}>
                        Profile & Settings
                    </MenuItem>
                    <MenuItem to="/browse" icon="book" onClick={() => setOpen(false)}>
                        Browse Courses
                    </MenuItem>
                    <button
                        type="button"
                        onClick={() => {
                            setOpen(false);
                            logout();
                        }}
                        className="flex w-full items-center gap-3 px-4 py-2 text-sm text-slate-600 transition hover:bg-red-50 hover:text-red-700"
                    >
                        <Icon name="logout" className="h-4 w-4" />
                        Sign out
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function MenuItem({ to, icon, children, onClick }) {
    return (
        <Link
            to={to}
            onClick={onClick}
            className="flex items-center gap-3 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
        >
            <Icon name={icon} className="h-4 w-4 text-slate-400" />
            {children}
        </Link>
    );
}

function SidebarItem({ to, label, icon, end }) {
    return (
        <NavLink
            to={to}
            end={end}
            className={({ isActive }) =>
                cx(
                    'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                    isActive
                        ? 'bg-brand-50 text-brand-700'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900',
                )
            }
        >
            {({ isActive }) => (
                <>
                    <span
                        className={cx(
                            'absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-brand-600 transition-opacity',
                            isActive ? 'opacity-100' : 'opacity-0',
                        )}
                    />
                    <Icon
                        name={icon}
                        className={cx('h-4.5 w-4.5 shrink-0', isActive ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-600')}
                    />
                    <span className="truncate">{label}</span>
                </>
            )}
        </NavLink>
    );
}

function SidebarSection({ label, items }) {
    if (!items.length) return null;
    return (
        <div>
            <p className="px-3 pb-1.5 pt-5 text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <ul className="space-y-0.5">
                {items.map((item) => (
                    <li key={item.to}>
                        <SidebarItem to={item.to} label={item.label} icon={item.icon} end={item.end} />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function buildGroups({ user, isStudent, isInstructor, isAdmin }) {
    return [
        {
            label: 'Learning',
            items: [
                ...(isStudent ? [{ to: '/dashboard', label: 'Dashboard', icon: 'home', end: true }] : []),
                { to: '/browse', label: 'Browse courses', icon: 'book', end: true },
                ...(user ? [{ to: '/my-courses', label: 'My courses', icon: 'grid', end: true }] : []),
            ],
        },
        {
            label: 'Progress',
            items: user
                ? [
                      ...(isStudent
                          ? [
                                { to: '/learning-insights', label: 'Learning insights', icon: 'target', end: true },
                                { to: '/learning-map', label: 'Learning map', icon: 'compass', end: true },
                                { to: '/portfolio', label: 'My portfolio', icon: 'award', end: true },
                                { to: '/challenge', label: 'Challenge', icon: 'cpu', end: true },
                            ]
                          : []),
                      { to: '/grades', label: 'Grades', icon: 'chart', end: true },
                      { to: '/certificates', label: 'Certificates', icon: 'certificate', end: true },
                      { to: '/notifications', label: 'Notifications', icon: 'bell', end: true },
                  ]
                : [],
        },
        {
            label: 'Instructor studio',
            items:
                isInstructor || isAdmin
                    ? [
                          { to: '/instructor/dashboard', label: 'Dashboard', icon: 'home', end: true },
                          { to: '/instructor/courses', label: 'Courses', icon: 'book', end: true },
                      ]
                    : [],
        },
        {
            label: 'Administration',
            items: isAdmin
                ? [
                      { to: '/admin/dashboard', label: 'Overview', icon: 'chart', end: true },
                      { to: '/admin/courses', label: 'Courses', icon: 'book', end: true },
                      { to: '/admin/users', label: 'Users', icon: 'users', end: true },
                      { to: '/admin/roles', label: 'Roles', icon: 'shield', end: true },
                      { to: '/admin/categories', label: 'Categories', icon: 'grid', end: true },
                      { to: '/admin/enrollments', label: 'Enrollments', icon: 'clipboard', end: true },
                      { to: '/admin/certificates', label: 'Certificates', icon: 'certificate', end: true },
                  ]
                : [],
        },
        {
            label: 'Account',
            items: user ? [{ to: '/profile', label: 'Profile & settings', icon: 'user', end: true }] : [],
        },
    ];
}

function SidebarContent({ user, isStudent, isInstructor, isAdmin, onNavigate }) {
    const groups = buildGroups({ user, isStudent, isInstructor, isAdmin });

    return (
        <div className="flex h-full flex-col">
            <div className="flex h-16 items-center border-b border-slate-200 px-5">
                <Brand />
            </div>

            <nav className="flex-1 overflow-y-auto px-3 pb-4 scrollbar-slim">
                {groups.map((group) => (
                    <SidebarSection key={group.label} label={group.label} items={group.items} />
                ))}
            </nav>

            <div className="border-t border-slate-200 p-3">
                <Link
                    to="/profile"
                    onClick={onNavigate}
                    className="flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-slate-50"
                >
                    <Avatar src={user.avatar_url ?? user.avatar} name={user.name} size="h-9 w-9" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900">{user.name}</p>
                        <p className="truncate text-xs text-slate-500">{user.email}</p>
                    </div>
                    <Icon name="chevronRight" className="h-4 w-4 text-slate-300" />
                </Link>
            </div>
        </div>
    );
}

function GuestMenuButton({ to, label, onClick }) {
    if (String(to).includes('#')) {
        return (
            <Link to={to} onClick={onClick} className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-brand-50 hover:text-brand-700">
                {label}
            </Link>
        );
    }
    return (
        <NavLink
            to={to}
            end
            onClick={onClick}
            className={({ isActive }) =>
                cx(
                    'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                    isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-brand-50 hover:text-brand-700',
                )
            }
        >
            {label}
        </NavLink>
    );
}

const guestNavItems = [
    { to: '/', label: 'Beranda' },
    { to: '/browse', label: 'Pembelajaran' },
    { to: '/#kategori', label: 'Kategori' },
    { to: '/#tentang', label: 'Tentang' },
    { to: '/verify-certificate', label: 'Verifikasi Sertifikat' },
];

function GuestNav() {
    const [open, setOpen] = useState(false);
    const location = useLocation();
    const drawerCloseRef = useRef(null);
    const name = window.__LMS_CONFIG__?.institutionName ?? 'LMS';

    useEffect(() => setOpen(false), [location.pathname, location.hash]);

    useEffect(() => {
        if (!open) return;

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        drawerCloseRef.current?.focus();

        const onKeyDown = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div>
            <div className="border-b border-brand-800 bg-brand-900">
                <div className="mx-auto flex h-9 max-w-7xl items-center justify-between gap-3 px-4 sm:px-6">
                    <p className="flex items-center gap-1.5 truncate text-xs font-medium tracking-wide text-brand-100">
                        <Icon name="building" className="h-3.5 w-3.5 shrink-0 text-brand-300" />
                        <span className="truncate">{name} · Platform Pembelajaran Digital</span>
                    </p>
                    <nav className="flex shrink-0 items-center gap-4 text-xs font-medium text-brand-100" aria-label="Layanan">
                        <Link to="/verify-certificate" className="flex items-center gap-1.5 transition hover:text-gray-100">
                            <Icon name="badgeCheck" className="h-3.5 w-3.5" />
                            Verifikasi Sertifikat
                        </Link>
                        <span className="h-3 w-px bg-brand-700" aria-hidden="true" />
                        <Link to="/login" className="flex items-center gap-1.5 transition hover:text-gray-100">
                            <Icon name="user" className="h-3.5 w-3.5" />
                            Masuk
                        </Link>
                    </nav>
                </div>
            </div>

            <header className="sticky top-0 z-40 border-b border-slate-200 bg-white">
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
                    <Brand subtitle="Platform Pembelajaran Digital" />
                    <nav className="hidden items-center gap-0.5 lg:flex" aria-label="Utama">
                        {guestNavItems.map((item) =>
                            String(item.to).includes('#') ? (
                                <Link
                                    key={item.label}
                                    to={item.to}
                                    className="rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-brand-50 hover:text-brand-700"
                                >
                                    {item.label}
                                </Link>
                            ) : (
                                <NavLink
                                    key={item.label}
                                    to={item.to}
                                    end
                                    className={({ isActive }) =>
                                        cx(
                                            'rounded-md px-3 py-2 text-sm font-medium transition',
                                            isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-700 hover:bg-brand-50 hover:text-brand-700',
                                        )
                                    }
                                >
                                    {item.label}
                                </NavLink>
                            ),
                        )}
                    </nav>
                    <div className="flex items-center gap-2">
                        <ThemeToggle />
                        <Link
                            to="/login"
                            className="hidden rounded-md px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 md:block"
                        >
                            Masuk
                        </Link>
                        <Link
                            to="/register"
                            className="hidden rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 sm:block"
                        >
                            Daftar
                        </Link>
                        <button
                            type="button"
                            onClick={() => setOpen((v) => !v)}
                            className="rounded-md p-2 text-slate-500 transition hover:bg-brand-50 hover:text-slate-800 lg:hidden"
                            aria-label="Buka menu"
                            aria-expanded={open}
                        >
                            <Icon name="menu" className="h-5 w-5" />
                        </button>
                    </div>
                </div>
            </header>

            {open ? (
                <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu utama">
                    <div className="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 right-0 flex w-72 max-w-[85vw] flex-col bg-white shadow-lift">
                        <div className="flex h-16 items-center justify-between border-b border-slate-200 px-5">
                            <Brand />
                            <button
                                type="button"
                                ref={drawerCloseRef}
                                onClick={() => setOpen(false)}
                                className="rounded-md p-2 text-slate-500 hover:bg-slate-100"
                                aria-label="Tutup menu"
                            >
                                <Icon name="x" className="h-5 w-5" />
                            </button>
                        </div>
                        <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 py-4" aria-label="Navigasi utama">
                            {guestNavItems.map((item) => (
                                <GuestMenuButton key={item.label} to={item.to} label={item.label} onClick={() => setOpen(false)} />
                            ))}
                        </nav>
                        <div className="border-t border-slate-200 p-4">
                            <div className="mb-3 flex items-center justify-between">
                                <span className="text-sm font-semibold text-slate-600">Tampilan</span>
                                <ThemeToggle />
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                            <Link
                                to="/login"
                                onClick={() => setOpen(false)}
                                className="rounded-md px-4 py-2.5 text-center text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50"
                            >
                                Masuk
                            </Link>
                            <Link
                                to="/register"
                                onClick={() => setOpen(false)}
                                className="rounded-md bg-brand-600 px-4 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-brand-700 dark:text-gray-50"
                            >
                                Daftar
                            </Link>
                        </div>
                        </div>
                    </aside>
                </div>
            ) : null}
        </div>
    );
}

function currentLabel(pathname, groups) {
    let best = null;
    for (const group of groups) {
        for (const item of group.items) {
            if (item.to === '/' || !pathname.startsWith(item.to)) continue;
            if (item.end && pathname !== item.to) continue;
            if (!best || item.to.length > best.to.length) best = item;
        }
    }
    return best?.label ?? null;
}

export default function Layout({ children }) {
    const { user, isInstructor, isAdmin, isStudent } = useAuth();
    const [mobileOpen, setMobileOpen] = useState(false);
    const location = useLocation();
    const drawerCloseRef = useRef(null);

    useEffect(() => setMobileOpen(false), [location.pathname]);

    useEffect(() => {
        let cancelled = false;
        const timer = window.setTimeout(() => {
            if (cancelled) return;
            if (location.hash) {
                const el = document.getElementById(location.hash.slice(1));
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                window.scrollTo({ top: 0 });
            }
        }, 0);
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [location.pathname, location.hash]);

    useEffect(() => {
        if (!mobileOpen) return;

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        drawerCloseRef.current?.focus();

        const onKeyDown = (e) => {
            if (e.key === 'Escape') setMobileOpen(false);
        };
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [mobileOpen]);

    const groups = buildGroups({ user, isStudent, isInstructor, isAdmin });
    const label = useMemo(() => currentLabel(location.pathname, groups), [location.pathname, groups]);

    if (!user) {
        return (
            <div className="flex min-h-screen flex-col bg-slate-50">
                <a href="#main-content" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[100] focus:rounded-lg focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white dark:focus:text-gray-50">
                    Lewati ke konten
                </a>
                <GuestNav />
                <main id="main-content" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 sm:py-8">{children}</main>
                <FooterSection />
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50">
            <a href="#main-content" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[100] focus:rounded-lg focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white dark:focus:text-gray-50">
                Lewati ke konten
            </a>
            <aside className="fixed inset-y-0 left-0 z-40 hidden w-64 border-r border-slate-200 bg-white lg:block">
                <SidebarContent user={user} isStudent={isStudent} isInstructor={isInstructor} isAdmin={isAdmin} />
            </aside>

            {mobileOpen ? (
                <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true">
                    <div className="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" onClick={() => setMobileOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 flex w-72 flex-col bg-white shadow-lift">
                        <div className="flex h-16 items-center justify-between border-b border-slate-200 px-5">
                            <Brand />
                            <button
                                type="button"
                                ref={drawerCloseRef}
                                onClick={() => setMobileOpen(false)}
                                className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                                aria-label="Close menu"
                            >
                                <Icon name="x" className="h-5 w-5" />
                            </button>
                        </div>
                        <SidebarContent
                            user={user}
                            isStudent={isStudent}
                            isInstructor={isInstructor}
                            isAdmin={isAdmin}
                            onNavigate={() => setMobileOpen(false)}
                        />
                    </aside>
                </div>
            ) : null}

            <div className="flex min-h-screen flex-col lg:pl-64">
                <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/80 backdrop-blur">
                    <div className="flex h-16 items-center gap-3 px-4 sm:px-6">
                        <button
                            type="button"
                            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
                            onClick={() => setMobileOpen(true)}
                            aria-label="Menu"
                        >
                            <Icon name="menu" className="h-5 w-5" />
                        </button>
                        <Brand className="lg:hidden" />
                        <span className="hidden text-sm font-semibold text-slate-500 lg:block">{label}</span>
                        <div className="ml-auto flex items-center gap-1 sm:gap-2">
                            <ThemeToggle />
                            <NotificationBell />
                            <UserMenu />
                        </div>
                    </div>
                </header>

                <main id="main-content" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 sm:py-8">{children}</main>

                <FooterSection />
            </div>
        </div>
    );
}