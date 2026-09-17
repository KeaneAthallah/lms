import { Link } from 'react-router-dom';
import { useAuth } from '../auth';
import { Icon } from './ui';

const portalLinks = [
    { to: '/', label: 'Beranda' },
    { to: '/browse', label: 'Pembelajaran' },
    { to: '/#kategori', label: 'Kategori Pembelajaran' },
    { to: '/#tentang', label: 'Tentang Platform' },
    { to: '/verify-certificate', label: 'Verifikasi Sertifikat' },
];

const infoLinks = [
    { to: '/#pembelajaran', label: 'Katalog Pembelajaran' },
    { to: '/#alur', label: 'Alur Pembelajaran' },
    { to: '/#informasi', label: 'Informasi Pembelajaran' },
    { to: '/#faq', label: 'Pertanyaan yang Sering Diajukan' },
];

export default function FooterSection() {
    const { user, isStudent, isInstructor, isAdmin } = useAuth();
    const name = window.__LMS_CONFIG__?.institutionName ?? 'LMS';

    const accountLinks = user
        ? [
              ...(isStudent ? [{ to: '/dashboard', label: 'Dasbor Pembelajaran' }] : []),
              { to: '/my-courses', label: 'Kursus Saya' },
              ...(isStudent ? [{ to: '/grades', label: 'Nilai & Laporan' }] : []),
              ...(isStudent ? [{ to: '/certificates', label: 'Sertifikat Saya' }] : []),
              ...(isInstructor || isAdmin ? [{ to: '/instructor/courses', label: 'Kursus (Instruktur)' }] : []),
              ...(isAdmin ? [{ to: '/admin/dashboard', label: 'Dasbor Administrasi' }] : []),
              { to: '/profile', label: 'Profil & Pengaturan' },
          ]
        : [
              { to: '/login', label: 'Masuk' },
              { to: '/register', label: 'Daftar Akun' },
              { to: '/browse', label: 'Jelajahi Pembelajaran' },
          ];

    return (
        <footer className="border-t border-slate-200 bg-white">
            <div className="mx-auto max-w-7xl px-4 pt-12 pb-8 sm:px-6">
                <div className="grid gap-10 md:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
                    <div>
                        <Link to="/" className="flex items-center gap-2.5">
                            <span className="relative flex h-10 w-10 items-center justify-center rounded-md border border-brand-200 bg-white text-brand-700 shadow-sm">
                                <Icon name="book" className="h-5 w-5" strokeWidth={2} />
                                <span className="absolute -bottom-0.5 -right-0.5 h-2 w-2 rounded-full border border-white bg-brand-600" aria-hidden="true" />
                            </span>
                            <span className="flex min-w-0 flex-col leading-tight">
                                <span className="truncate text-base font-bold tracking-tight text-slate-900">{name}</span>
                                <span className="truncate text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                                    Platform Pembelajaran Digital
                                </span>
                            </span>
                        </Link>
                        <p className="mt-4 max-w-sm text-sm leading-relaxed text-slate-500">
                            Sistem manajemen pembelajaran terstruktur yang menyelenggarakan kursus, materi digital, evaluasi,
                            pemantauan kemajuan, dan penerbitan sertifikat yang dapat diverifikasi.
                        </p>
                        <Link
                            to="/verify-certificate"
                            className="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-brand-600 transition hover:text-brand-700"
                        >
                            <Icon name="badgeCheck" className="h-4 w-4" />
                            Verifikasi Sertifikat
                        </Link>
                    </div>

                    <FooterColumn title="Tautan" links={portalLinks} />
                    <FooterColumn title="Akun" links={accountLinks} />
                    <FooterColumn title="Informasi" links={infoLinks} />
                </div>
            </div>
            <div className="border-t border-slate-100">
                <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 py-6 text-xs text-slate-500 sm:flex-row sm:px-6">
                    <p>
                        © {new Date().getFullYear()} {name} — Platform Pembelajaran Digital.
                    </p>
                    <p>Sistem Manajemen Pembelajaran</p>
                </div>
            </div>
        </footer>
    );
}

function FooterColumn({ title, links }) {
    return (
        <nav aria-label={title}>
            <h2 className="text-xs font-bold uppercase tracking-wider text-slate-400">{title}</h2>
            <ul className="mt-3 space-y-2">
                {links.map((link) => (
                    <li key={link.to}>
                        <Link to={link.to} className="text-sm text-slate-600 transition hover:text-brand-600">
                            {link.label}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}