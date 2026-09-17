import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../auth';
import CourseCard from '../components/CourseCard';
import { Icon } from '../components/icons';
import { ButtonLink, EmptyState } from '../components/ui';
import {
    BenefitStrip,
    CategoryDirectory,
    FAQItem,
    IntroStrip,
    NoticeList,
    ProcessSteps,
    ResourceList,
    RolePanel,
    SectionHeading,
    ServicesGrid,
} from '../components/landing';

function useFeatured() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        Promise.all([api.get('/api/courses?sort=popular&page=1'), api.get('/api/courses/categories')])
            .then(([courses, categories]) =>
                setData({
                    courses: courses.data.data ?? [],
                    categories: categories.data ?? [],
                    meta: courses.data.meta ?? null,
                }),
            )
            .catch(() => setError(true));
    }, []);

    return { data, error };
}

const institutionName = () => window.__LMS_CONFIG__?.institutionName ?? 'Platform Pembelajaran Digital';

const capabilityItems = ['Pembelajaran Terstruktur', 'Materi Digital', 'Evaluasi Pembelajaran', 'Sertifikat Digital'];

function HeroVisual({ data }) {
    const courses = data?.courses?.slice(0, 3) ?? [];

    return (
        <div className="relative mx-auto w-full max-w-md" aria-hidden="true">
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-card">
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-2.5">
                    <span className="flex items-center gap-1.5 text-xs font-bold text-slate-700">
                        <Icon name="book" className="h-3.5 w-3.5 text-brand-600" />
                        {institutionName()}
                    </span>
                    <span className="flex h-6 w-6 items-center justify-center rounded-md bg-brand-50 text-brand-600">
                        <Icon name="search" className="h-3 w-3" />
                    </span>
                </div>
                <div className="px-4 py-3">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Pembelajaran Tersedia</p>
                    {courses.length ? (
                        <ul className="mt-2 space-y-2">
                            {courses.map((course) => (
                                <li key={course.id} className="flex items-center gap-2.5 rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
                                    <Icon name="layers" className="h-3.5 w-3.5 shrink-0 text-brand-600" />
                                    <span className="min-w-0 flex-1 truncate text-xs font-semibold text-slate-700">{course.title}</span>
                                    <span className="shrink-0 text-[10px] font-semibold text-slate-400">
                                        {course.lessons_count ?? 0} pelajaran
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <ul className="mt-2 space-y-2">
                            {[64, 82, 58].map((width, i) => (
                                <li key={i} className="flex items-center gap-2.5 rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
                                    <span className="h-3.5 w-3.5 shrink-0 rounded bg-slate-200" />
                                    <span className="h-2.5 rounded bg-slate-200" style={{ width: `${width}%` }} />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Progres pembelajaran</p>
                        <p className="mt-0.5 text-xs text-slate-600">Tercatat per pelajaran dan kursus.</p>
                    </div>
                    <span className="flex h-9 items-center justify-center rounded-lg bg-emerald-50 px-3 text-xs font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                        <Icon name="checkCircle" className="mr-1 h-3.5 w-3.5" /> Selesai
                    </span>
                </div>
            </div>
            <div className="absolute -right-4 -top-4 hidden rounded-md border border-slate-200 bg-white px-3 py-2 shadow-card sm:block">
                <p className="flex items-center gap-1.5 text-xs font-semibold text-brand-700">
                    <Icon name="badgeCheck" className="h-4 w-4" /> Sertifikat dapat diverifikasi
                </p>
            </div>
        </div>
    );
}

function Hero({ data, user, isAuthenticated, isStudent, isInstructor, isAdmin }) {
    const firstName = user?.name?.split(' ')[0] ?? '';
    const isMember = isAuthenticated && user;

    const primary = isAuthenticated
        ? isAdmin
            ? { to: '/admin/dashboard', label: 'Buka Dashboard', icon: 'chart' }
            : isInstructor
              ? { to: '/instructor/dashboard', label: 'Kelola Pembelajaran', icon: 'book' }
              : { to: '/my-courses', label: 'Melanjutkan Pembelajaran', icon: 'play' }
        : { to: '/browse', label: 'Mulai Pembelajaran', icon: 'compass' };

    const memberSub = isStudent
        ? 'Sesi belajar Anda telah disimpan. Lanjutkan pembelajaran dari tempat terakhir kali atau telusuri pembelajaran baru.'
        : isInstructor
          ? 'Lanjutkan pengelolaan kursus dan pantau kegiatan pembelajaran yang Anda selenggarakan.'
          : 'Pantau penyelenggaraan platform secara menyeluruh melalui dasbor administrasi.';

    return (
        <section
            aria-labelledby="hero-heading"
            className="-mx-4 -my-6 border-b border-slate-200 bg-white px-4 py-14 sm:-mx-6 sm:-my-8 sm:px-6 sm:py-16"
        >
            <div className="mx-auto grid max-w-7xl items-center gap-12 lg:grid-cols-2">
                <div>
                    <p className="flex items-center gap-2.5 text-xs font-bold uppercase tracking-[0.18em] text-brand-700">
                        <Icon name="building" className="h-4 w-4" aria-hidden="true" />
                        {institutionName()} · Platform Pembelajaran Digital
                    </p>
                    <h1
                        id="hero-heading"
                        className="mt-5 text-3xl font-bold leading-tight tracking-tight text-slate-900 sm:text-4xl lg:text-[2.6rem]"
                    >
                        {isMember ? (
                            <>
                                Selamat datang kembali, {firstName}.
                            </>
                        ) : (
                            'Pengembangan Kompetensi Melalui Pembelajaran Digital'
                        )}
                    </h1>
                    <p className="mt-4 max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg">
                        {isMember
                            ? memberSub
                            : 'Sistem manajemen pembelajaran yang menyelenggarakan kursus terstruktur, materi digital, evaluasi, pemantauan kemajuan, dan sertifikat yang dapat diverifikasi — untuk pembelajar, instruktur, dan administrator.'}
                    </p>

                    <div className="mt-8 flex flex-wrap items-center gap-3">
                        <ButtonLink to={primary.to} variant="primary" size="lg" icon={primary.icon}>
                            {primary.label}
                        </ButtonLink>
                        <ButtonLink to="/browse" variant="secondary" size="lg" icon="arrowRight">
                            Jelajahi Pembelajaran
                        </ButtonLink>
                    </div>

                    {!isMember ? (
                        <p className="mt-5 text-sm text-slate-500">
                            Sudah memiliki akun?{' '}
                            <Link to="/login" className="font-semibold text-brand-700 underline underline-offset-4 hover:text-brand-800">
                                Masuk
                            </Link>{' '}
                            atau{' '}
                            <Link to="/register" className="font-semibold text-brand-700 underline underline-offset-4 hover:text-brand-800">
                                daftarkan akun
                            </Link>{' '}
                            untuk mengikuti pembelajaran.
                        </p>
                    ) : null}
                </div>

                {!isMember ? (
                    <div className="hidden lg:block">
                        <HeroVisual data={data} />
                    </div>
                ) : null}
            </div>

            <div className="mx-auto mt-12 max-w-7xl">
                <ul className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 grid-cols-2 lg:grid-cols-4">
                    {capabilityItems.map((item, i) => (
                        <li key={item} className="flex items-center gap-2.5 bg-white px-4 py-3.5">
                            <span className="text-xs font-bold text-brand-700" aria-hidden="true">
                                {String(i + 1).padStart(2, '0')}
                            </span>
                            <span className="text-sm font-semibold text-slate-800">{item}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

const aboutItems = [
    'Kursus dan materi terstruktur',
    'Evaluasi dan penilaian terintegrasi',
    'Pemantauan kemajuan pembelajaran',
    'Sertifikat digital yang dapat diverifikasi',
];

const services = [
    { number: 1, icon: 'bookOpen', title: 'Pembelajaran', description: 'Akses kursus dengan materi terstruktur dalam bentuk teks, video, dokumen, dan tautan eksternal.' },
    { number: 2, icon: 'puzzle', title: 'Evaluasi', description: 'Ikuti kuis dan kumpulkan tugas sebagai bagian dari proses pembelajaran.' },
    { number: 3, icon: 'refresh', title: 'Pemantauan Kemajuan', description: 'Kemajuan per pelajaran dan per kursus tercatat serta dapat dipantau.' },
    { number: 4, icon: 'chart', title: 'Nilai & Umpan Balik', description: 'Lihat hasil penilaian dan umpan balik instruktur pada setiap kegiatan.' },
    { number: 5, icon: 'certificate', title: 'Sertifikat', description: 'Peroleh sertifikat digital setelah memenuhi persyaratan penyelesaian kursus.' },
    { number: 6, icon: 'shieldCheck', title: 'Pengelolaan', description: 'Instruktur dan administrator mengelola pembelajaran, pengguna, dan pendaftaran.' },
];

const benefits = [
    { title: 'Pembelajaran Terstruktur', description: 'Materi disusun dalam kursus, bagian, dan pelajaran yang saling terhubung.' },
    { title: 'Pemantauan Kemajuan', description: 'Tingkat penyelesaian pelajaran dan kursus tercatat secara berkelanjutan.' },
    { title: 'Evaluasi Terintegrasi', description: 'Kuis dan tugas menjadi bagian dari alur pembelajaran yang utuh.' },
    { title: 'Administrasi Terpusat', description: 'Kursus, pengguna, pendaftaran, dan sertifikat dikelola secara terpusat.' },
];

const processSteps = [
    { number: 1, title: 'Pilih Pembelajaran', description: 'Telusuri kursus berdasarkan kategori, tingkat, dan kata kunci untuk menemukan yang sesuai.' },
    { number: 2, title: 'Daftar pada Kursus', description: 'Masuk dengan akun Anda lalu daftar pada kursus yang dipilih untuk memulai.' },
    { number: 3, title: 'Pelajari Materi', description: 'Selesaikan pelajaran dalam bentuk teks, video, dokumen, dan tautan eksternal.' },
    { number: 4, title: 'Ikuti Evaluasi', description: 'Kerjakan kuis dan kumpulkan tugas sesuai ketentuan kursus.' },
    { number: 5, title: 'Selesaikan Pembelajaran', description: 'Penuhi seluruh kegiatan yang dipersyaratkan untuk menyelesaikan kursus.' },
    { number: 6, title: 'Dapatkan Sertifikat', description: 'Peroleh sertifikat digital dengan nomor identifikasi unik saat memenuhi syarat.' },
];

const learningTypes = [
    { icon: 'doc', title: 'Materi Teks', description: 'Bacaan terstruktur pada setiap pelajaran.' },
    { icon: 'video', title: 'Video', description: 'Pembelajaran melalui video dalam kursus.' },
    { icon: 'file', title: 'Dokumen', description: 'Materi yang dapat diunduh dan diarsipkan.' },
    { icon: 'link', title: 'Tautan Eksternal', description: 'Referensi dari sumber pembelajaran lain.' },
    { icon: 'puzzle', title: 'Kuis', description: 'Evaluasi pemahaman dengan hasil langsung.' },
    { icon: 'clipboard', title: 'Tugas', description: 'Mengumpulkan pekerjaan untuk dinilai instruktur.' },
];

const assessmentChecks = [
    'Kuis dengan penilaian dan penjelasan jawaban',
    'Tugas dengan pengumpulan dan penilaian',
    'Nilai serta umpan balik per kegiatan',
    'Kemajuan tercatat per pelajaran dan kursus',
];

const assessmentRows = [
    { icon: 'puzzle', title: 'Kuis', description: 'Evaluasi pemahaman terhadap materi pelajaran.' },
    { icon: 'clipboard', title: 'Tugas', description: 'Pengumpulan pekerjaan untuk dinilai instruktur.' },
    { icon: 'chart', title: 'Nilai & Umpan Balik', description: 'Hasil penilaian beserta umpan balik per kegiatan.' },
    { icon: 'refresh', title: 'Progres Pembelajaran', description: 'Kemajuan pelajaran dan kursus yang tercatat.' },
];

const certificateChecks = [
    'Diterbitkan saat persyaratan kursus terpenuhi',
    'Memuat nomor identifikasi unik',
    'Dapat diverifikasi melalui layanan publik',
    'Diakses melalui halaman sertifikat pengguna',
];

const roles = [
    {
        icon: 'graduation',
        title: 'Pembelajar',
        eyebrow: 'Peserta pembelajaran',
        items: [
            'Mengikuti kursus dan mempelajari materi',
            'Melacak kemajuan pembelajaran',
            'Mengerjakan kuis dan mengumpulkan tugas',
            'Melihat nilai serta umpan balik',
            'Memperoleh sertifikat saat memenuhi syarat',
        ],
    },
    {
        icon: 'messageCircle',
        title: 'Instruktur',
        eyebrow: 'Penyelenggara pembelajaran',
        items: [
            'Membuat dan mengelola kursus',
            'Menyusun bagian, pelajaran, kuis, dan tugas',
            'Meninjau pengumpulan tugas pembelajar',
            'Memberikan penilaian dan umpan balik',
            'Melihat ringkasan kegiatan pembelajaran',
        ],
    },
    {
        icon: 'shieldCheck',
        title: 'Administrator',
        eyebrow: 'Pengelola platform',
        items: [
            'Mengelola pengguna dan peran',
            'Mengelola kursus dan kategori',
            'Mengawasi pendaftaran pembelajaran',
            'Mengelola sertifikat',
            'Memantau penyelenggaraan platform',
        ],
    },
];

const notices = [
    { icon: 'user', title: 'Pendaftaran Akun', description: 'Buat akun untuk mengikuti pembelajaran dan mengakses layanan pengguna secara penuh.' },
    { icon: 'badgeCheck', title: 'Verifikasi Sertifikat', description: 'Periksa keaslian sertifikat melalui layanan verifikasi publik.' },
    { icon: 'shieldCheck', title: 'Peran Pengguna', description: 'Pembelajar, instruktur, dan administrator memiliki akses sesuai perannya masing-masing.' },
];

const faqs = [
    {
        question: 'Apa itu platform ini?',
        answer:
            'Platform ini adalah sistem manajemen pembelajaran (LMS) yang menyediakan kursus terstruktur, materi digital, evaluasi, pemantauan kemajuan, dan sertifikat yang dapat diverifikasi bagi pembelajar, instruktur, dan administrator dalam satu ekosistem.',
    },
    {
        question: 'Siapa yang dapat menggunakan platform ini?',
        answer:
            'Platform digunakan oleh tiga kelompok pengguna: pembelajar yang mengikuti kursus, instruktur yang menyusun dan mengelola kursus, serta administrator yang mengelola pengguna, peran, kursus, pendaftaran, dan sertifikat.',
    },
    {
        question: 'Bagaimana cara mengikuti kursus?',
        answer:
            'Daftarkan akun, masuk, lalu pilih kursus melalui halaman Pembelajaran. Setelah terdaftar, Anda dapat mengakses seluruh bagian dan pelajaran kursus melalui halaman belajar.',
    },
    {
        question: 'Bagaimana cara mencari kursus yang sesuai?',
        answer:
            'Gunakan penyaringan pada halaman Pembelajaran berdasarkan kata kunci, kategori, tingkat, dan bahasa untuk menemukan kursus yang sesuai dengan kebutuhan Anda.',
    },
    {
        question: 'Bagaimana progres pembelajaran dipantau?',
        answer:
            'Progres setiap pelajaran dan kursus tercatat otomatis. Pembelajar dapat melihat kemajuan masing-masing kursus serta ringkasan pembelajaran melalui Dasbor Pembelajaran.',
    },
    {
        question: 'Bagaimana bentuk evaluasi pembelajaran?',
        answer:
            'Evaluasi dilakukan melalui kuis pada materi pelajaran dan penugasan yang dikumpulkan untuk dinilai. Hasil penilaian dan umpan balik instruktur dapat dilihat pada halaman Nilai.',
    },
    {
        question: 'Bagaimana sertifikat diterbitkan?',
        answer:
            'Sertifikat diterbitkan ketika pembelajar menyelesaikan seluruh kegiatan pembelajaran yang dipersyaratkan, termasuk pelajaran dan evaluasi yang berlaku sesuai ketentuan kursus.',
    },
    {
        question: 'Bagaimana cara memverifikasi sertifikat?',
        answer:
            'Setiap sertifikat memuat nomor dan kode identifikasi unik. Keasliannya dapat diperiksa oleh siapa pun melalui tautan verifikasi publik pada halaman Verifikasi Sertifikat.',
    },
    {
        question: 'Apa yang dapat dilakukan instruktur?',
        answer:
            'Instruktur dapat membuat dan mengelola kursus, menyusun bagian dan pelajaran, membuat kuis dan tugas, meninjau pengumpulan tugas, memberikan nilai beserta umpan balik, serta melihat ringkasan kegiatan pembelajaran.',
    },
    {
        question: 'Apa yang dapat dilakukan administrator?',
        answer:
            'Administrator dapat mengelola pengguna dan peran, mengelola kursus dan kategori, mengawasi pendaftaran, mengelola sertifikat, serta memantau penyelenggaraan platform secara keseluruhan.',
    },
];

function FeaturedCourses({ data, error }) {
    const navigate = useNavigate();
    const [query, setQuery] = useState('');
    const total = data?.meta?.total;

    const onSubmit = (e) => {
        e.preventDefault();
        const q = query.trim();
        navigate(q ? `/browse?search=${encodeURIComponent(q)}` : '/browse');
    };

    return (
        <div id="pembelajaran" className="scroll-mt-24">
            <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <SectionHeading
                    align="left"
                    eyebrow="Katalog"
                    title="Pembelajaran Tersedia"
                    description={
                        total != null
                            ? `Terdapat ${total} pembelajaran yang tersedia untuk diikuti pada platform ini.`
                            : 'Telusuri pembelajaran yang tersedia dan tingkatkan kompetensi Anda.'
                    }
                />
                <div className="flex flex-col gap-3 sm:flex-row lg:w-auto">
                    <form onSubmit={onSubmit} className="w-full lg:w-72" role="search">
                        <label htmlFor="home-search" className="sr-only">
                            Cari pembelajaran
                        </label>
                        <div className="relative">
                            <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                                <Icon name="search" className="h-4 w-4" />
                            </span>
                            <input
                                id="home-search"
                                type="search"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Cari pembelajaran…"
                                className="w-full rounded-lg border-0 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-brand-600"
                            />
                        </div>
                    </form>
                    <ButtonLink to="/browse" variant="secondary" size="sm" icon="arrowRight" className="shrink-0">
                        Lihat Seluruh Pembelajaran
                    </ButtonLink>
                </div>
            </div>

            <div className="mt-8">
                {error ? (
                    <EmptyState
                        icon="alert"
                        title="Pembelajaran belum dapat dimuat"
                        message="Terjadi kendala saat memuat data pembelajaran. Silakan coba lagi sebentar lagi."
                    />
                ) : !data ? (
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" aria-label="Memuat pembelajaran">
                        {Array.from({ length: 6 }).map((_, i) => (
                            <div key={i} className="animate-shimmer overflow-hidden rounded-lg border border-slate-200 bg-white shadow-card">
                                <div className="aspect-video bg-slate-200" />
                                <div className="space-y-2.5 p-4">
                                    <div className="h-3 w-1/3 rounded bg-slate-200" />
                                    <div className="h-4 w-3/4 rounded bg-slate-200" />
                                    <div className="h-3 w-2/3 rounded bg-slate-200" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : data.courses.length === 0 ? (
                    <EmptyState
                        icon="book"
                        title="Belum ada pembelajaran yang diterbitkan"
                        message="Pembelajaran akan muncul di sini setelah instruktur menerbitkannya. Silakan kembali lagi nanti."
                    />
                ) : (
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {data.courses.slice(0, 6).map((course) => (
                            <CourseCard key={course.id} course={course} />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Home() {
    const { user, isAuthenticated, isStudent, isInstructor, isAdmin } = useAuth();
    const { data, error } = useFeatured();

    return (
        <div className="space-y-14 sm:space-y-16">
            <Hero data={data} user={user} isAuthenticated={isAuthenticated} isStudent={isStudent} isInstructor={isInstructor} isAdmin={isAdmin} />

            <section aria-label="Tentang Platform" id="tentang" className="scroll-mt-24">
                <IntroStrip
                    eyebrow="Informasi"
                    title="Tentang Platform"
                    description="Platform pembelajaran digital ini menyediakan akses terhadap materi pembelajaran, evaluasi, pemantauan kemajuan, dan sertifikasi dalam satu lingkungan pembelajaran yang terintegrasi. Penyelenggaraan pembelajaran dilakukan secara terstruktur melalui kursus yang disusun dalam bagian dan pelajaran, dilengkapi dengan kuis, tugas, penilaian, serta sertifikat yang dapat diverifikasi."
                    items={aboutItems}
                />
            </section>

            <section aria-label="Layanan Pembelajaran">
                <SectionHeading
                    eyebrow="Layanan"
                    title="Layanan Pembelajaran"
                    description="Fasilitas yang tersedia dalam platform untuk menyelenggarakan pembelajaran secara lengkap dan terstruktur."
                />
                <div className="mt-8">
                    <ServicesGrid services={services} />
                </div>
            </section>

            <section aria-label="Manfaat Platform">
                <SectionHeading
                    eyebrow="Manfaat"
                    title="Manfaat Platform"
                    description="Platform dirancang untuk mendukung penyelenggaraan pembelajaran yang tertib, terukur, dan dapat dipertanggungjawabkan."
                />
                <div className="mt-8">
                    <BenefitStrip benefits={benefits} />
                </div>
            </section>

            <section aria-label="Pembelajaran Tersedia" className="scroll-mt-24">
                <FeaturedCourses data={data} error={error} />
            </section>

            <section aria-label="Kategori Pembelajaran" id="kategori" className="scroll-mt-24">
                <SectionHeading
                    eyebrow="Kategori"
                    title="Kategori Pembelajaran"
                    description="Telusuri pembelajaran berdasarkan bidang yang tersedia pada platform."
                />
                <div className="mt-8">
                    {error ? (
                        <EmptyState
                            icon="grid"
                            title="Kategori belum dapat dimuat"
                            message="Silakan coba lagi sebentar lagi."
                        />
                    ) : !data ? (
                        <div className="grid animate-shimmer gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2">
                            {Array.from({ length: 4 }).map((_, i) => (
                                <div key={i} className="h-24 bg-white" />
                            ))}
                        </div>
                    ) : data.categories.length === 0 ? (
                        <EmptyState
                            icon="grid"
                            title="Belum ada kategori pembelajaran"
                            message="Kategori akan tampil di sini setelah ditambahkan."
                        />
                    ) : (
                        <CategoryDirectory categories={data.categories} />
                    )}
                </div>
            </section>

            <section aria-label="Alur Pembelajaran">
                <SectionHeading
                    eyebrow="Alur Pembelajaran"
                    title="Alur Pembelajaran"
                    description="Enam tahap pelaksanaan pembelajaran, dari pemilihan kursus hingga penerbitan sertifikat."
                />
                <div className="mt-8">
                    <ProcessSteps steps={processSteps} />
                </div>
            </section>

            <section className="grid items-start gap-10 lg:grid-cols-2" aria-label="Jenis Materi Pembelajaran">
                <div>
                    <SectionHeading
                        eyebrow="Jenis Materi"
                        title="Beragam Bentuk Materi Pembelajaran"
                        description="Pembelajaran tidak hanya berbentuk teks. Setiap kursus dapat memadukan berbagai jenis pelajaran sesuai kebutuhan."
                    />
                    <p className="mt-4 text-sm leading-relaxed text-slate-600">
                        Materi disusun oleh instruktur dalam bagian dan pelajaran, sehingga setiap jenis konten disajikan secara berurutan dan dapat diakses melalui halaman belajar.
                    </p>
                </div>
                <ResourceList items={learningTypes} />
            </section>

            <section className="grid items-start gap-10 lg:grid-cols-2" aria-label="Evaluasi dan Perkembangan Pembelajaran">
                <SectionHeading
                    align="left"
                    eyebrow="Evaluasi"
                    title="Evaluasi dan Perkembangan Pembelajaran"
                    description="Pencapaian belajar diukur melalui kuis dan tugas yang dinilai, disertai umpan balik dari instruktur serta pemantauan perkembangan pada setiap tahap."
                    className="lg:pt-2"
                />
                <div>
                    <ul className="space-y-2.5">
                        {assessmentChecks.map((item) => (
                            <li key={item} className="flex items-start gap-2.5 text-sm text-slate-700 sm:text-[15px]">
                                <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-600">
                                    <Icon name="check" className="h-3.5 w-3.5" strokeWidth={2.5} />
                                </span>
                                {item}
                            </li>
                        ))}
                    </ul>
                    <div className="mt-6">
                        <ResourceList items={assessmentRows} />
                    </div>
                </div>
            </section>

            <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-card" aria-label="Sertifikat Pembelajaran">
                <div className="grid lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <div className="border-b border-slate-200 px-6 py-8 sm:px-8 lg:border-b-0 lg:border-r">
                        <SectionHeading
                            eyebrow="Sertifikat"
                            title="Sertifikat Pembelajaran"
                            description="Setelah menyelesaikan seluruh kegiatan yang dipersyaratkan pada kursus, pembelajar dapat memperoleh sertifikat digital yang dapat diperiksa keasliannya."
                        />
                        <ul className="mt-6 grid gap-x-8 gap-y-2 sm:grid-cols-2">
                            {certificateChecks.map((item) => (
                                <li key={item} className="flex items-start gap-2 text-sm text-slate-700">
                                    <Icon name="check" className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" strokeWidth={2.5} />
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="flex flex-col justify-center bg-slate-50 px-6 py-8 sm:px-8">
                        <span className="flex h-11 w-11 items-center justify-center rounded-lg border border-brand-100 bg-white text-brand-700 shadow-sm" aria-hidden="true">
                            <Icon name="badgeCheck" className="h-5.5 w-5.5" />
                        </span>
                        <h3 className="mt-4 text-lg font-bold text-slate-900">Layanan Verifikasi Sertifikat</h3>
                        <p className="mt-2 text-sm leading-relaxed text-slate-600">
                            Periksa keaslian sertifikat menggunakan nomor identifikasi yang tertera pada sertifikat yang Anda terima.
                        </p>
                        <ButtonLink to="/verify-certificate" variant="primary" icon="badgeCheck" className="mt-5 self-start">
                            Verifikasi Sertifikat
                        </ButtonLink>
                    </div>
                </div>
            </section>

            <section aria-label="Peran Pengguna">
                <SectionHeading
                    align="center"
                    eyebrow="Peran Pengguna"
                    title="Untuk Setiap Peran"
                    description="Setiap pengguna memiliki pengalaman dan kemampuan yang disesuaikan dengan perannya dalam penyelenggaraan pembelajaran."
                />
                <div className="mt-8 grid gap-4 lg:grid-cols-3">
                    {roles.map((role) => (
                        <RolePanel key={role.title} {...role} />
                    ))}
                </div>
            </section>

            <section className="mx-auto max-w-3xl scroll-mt-24" aria-label="Informasi" id="informasi">
                <NoticeList items={notices} />
            </section>

            <section className="mx-auto max-w-3xl scroll-mt-24" aria-label="Pertanyaan yang sering diajukan">
                <SectionHeading
                    align="center"
                    eyebrow="Informasi"
                    title="Pertanyaan yang Sering Diajukan"
                    description="Jawaban ringkas mengenai penggunaan platform pembelajaran digital ini."
                />
                <div className="mt-8 space-y-3">
                    {faqs.map((faq) => (
                        <FAQItem key={faq.question} {...faq} />
                    ))}
                </div>
            </section>

            {(isAuthenticated && user ? (
                <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-card" aria-labelledby="cta-heading">
                    <div className="flex flex-col gap-6 px-6 py-8 sm:px-8 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 id="cta-heading" className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                                Lanjutkan aktivitas Anda di platform ini.
                            </h2>
                            <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                {isStudent
                                    ? 'Akses kursus, nilai, dan sertifikat Anda melalui dasbor pembelajaran.'
                                    : isInstructor
                                      ? 'Kelola kursus dan pantau kegiatan pembelajaran yang Anda selenggarakan.'
                                      : 'Pantau penyelenggaraan platform melalui dasbor administrasi.'}
                            </p>
                        </div>
                        <ButtonLink to={isAdmin ? '/admin/dashboard' : isInstructor ? '/instructor/dashboard' : '/my-courses'} variant="primary" size="lg" icon={isAdmin ? 'chart' : isInstructor ? 'book' : 'play'}>
                            {isAdmin ? 'Buka Dashboard' : isInstructor ? 'Kelola Pembelajaran' : 'Melanjutkan Pembelajaran'}
                        </ButtonLink>
                    </div>
                </section>
            ) : (
                <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-card" aria-labelledby="cta-heading">
                    <div className="flex flex-col gap-6 px-6 py-8 sm:px-8 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 id="cta-heading" className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                                Mulai pembelajaran melalui platform ini.
                            </h2>
                            <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                Daftarkan akun atau masuk untuk mengikuti kursus, mengerjakan evaluasi, dan memperoleh sertifikat pembelajaran.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <ButtonLink to="/register" variant="primary" size="lg" icon="user">
                                Daftarkan Akun
                            </ButtonLink>
                            <ButtonLink to="/browse" variant="secondary" size="lg" icon="arrowRight">
                                Jelajahi Pembelajaran
                            </ButtonLink>
                        </div>
                    </div>
                </section>
            ))}
        </div>
    );
}