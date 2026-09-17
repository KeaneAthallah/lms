# Lumen Academy — Learning Management System

An institutional Learning Management System built with **Laravel 13** and a
**React 19 SPA** (React Router). The public Welcome page is presented as an
Indonesian digital-learning portal, while authenticated students, instructors,
and administrators get role-based dashboards and workflows.

```
┌────────────────────────────────────────────────────────────────────┐
│  Public portal (Indonesian)                                        │
│  Welcome / Browse / Course detail / Certificate verification       │
│                                                                    │
│  Authenticated app (English)                                       │
│  Student: dashboard, learn, quizzes, assignments, grades, certs    │
│  Instructor: course builder, submissions, analytics, students      │
│  Admin: users, roles, courses, categories, enrollments, certs      │
└────────────────────────────────────────────────────────────────────┘
```

## Features

- **Learning**: courses organized into sections and lessons (text, video,
  document, external link, quiz, assignment), with enrollment and student
  progress tracking per lesson and per course.
- **Evaluation**: timed quizzes with auto-grading (multiple choice, true/false,
  short answer), assignments with file uploads and instructor grading +
  feedback.
- **Certification**: numbered, verifiable certificates issued on completion;
  public verification via `GET /certificates/verify/{identifier}`.
- **Roles**: Student, Instructor, and Admin experiences with server-side role
  guards (`EnsureUserHasRole`) and client-side `ProtectedRoute`.
- **Administration**: users, roles/permissions, courses, categories,
  enrollments, and certificates.
- **Institutional public portal**: numbered services, categories, learning
  flow, FAQ, and an official footer — no commercial/pricing UI, no fabricated
  statistics (numbers come from the real API).

## Tech stack

| Area     | Choice                                                        |
| -------- | ------------------------------------------------------------- |
| Backend  | PHP 8.5, Laravel Framework 13.32, SQLite                       |
| Frontend | React 19, react-router-dom 7, Tailwind CSS v4 (CSS-first)      |
| Build    | Vite 8 (`laravel-vite-plugin` + `@vitejs/plugin-react`)        |
| Testing  | PHPUnit 12 (feature tests, `sqlite :memory:` + RefreshDatabase)|

There is **no Inertia** – the SPA is mounted from a single Blade shell
(`resources/views/app.blade.php`) that injects `window.__LMS_USER__` and
`window.__LMS_CONFIG__` (including `institutionName`).

## Project structure

```
app/
  Enums, Helpers, Models, Policies, Services
  Http/Controllers       JSON API controllers
  Http/Requests          FormRequest validation
  Http/Resources         Eloquent API Resources (JSON shapes)
database/                migrations, seeders, factories
routes/web.php           all routes (web + JSON API + certificate verify)
resources/js/
  app.jsx                React entry (React Router tree)
  auth.jsx, api.js       auth context + axios client
  components/            ui.jsx (design system), icons.jsx, Layout.jsx,
                         CourseCard.jsx, FooterSection.jsx, landing.jsx,
                         ProtectedRoute.jsx
  pages/                 routed pages organized by role
resources/css/app.css    Tailwind entry + @theme design tokens
tests/Feature/           PHPUnit feature tests (one file per domain)
```

## Getting started

Requirements: PHP 8.5, Composer, Node.js (npm), SQLite.

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database (SQLite)
#    .env: DB_CONNECTION=sqlite, DB_DATABASE=<absolute path>/database/database.sqlite
#    (the file is gitignored and not shipped with the repo)
touch database/database.sqlite       # or: php artisan db:create (Laravel 11+)
php artisan migrate --seed

# 4. Run
composer run dev                     # starts Laravel + Vite together
# or separately:
php artisan serve
npm run dev
```

Create an administrator via the console or a seeder, then manage users and
roles from `/admin`.

## Testing

```bash
php artisan test --compact           # full suite
php artisan test --filter=CourseTest # narrowest set while developing
```

Frontend has no Jest/Vitest runner; it is verified with `npm run build` (green)
and browser console logs.

## Code style

```bash
vendor/bin/pint --format agent       # runs on dirty PHP files
```

## Branding

The institution name is driven by configuration, not hardcoded:

```
config/lms.php → 'institution_name' => env('LMS_INSTITUTION_NAME', 'Lumen Academy')
```

It surfaces in the header, footer, Home hero, and the favicon
(`public/favicon.svg` + `public/favicon.ico`, a book mark on the brand
gradient `#3f6ee9 → #273fc4`). See `codebase_explanation.txt` for detailed
architecture notes and gotchas.

## License

Proprietary/internal project.