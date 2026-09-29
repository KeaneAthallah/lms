# LMS Codebase Audit — Phase 0

Baseline established before any change: `php artisan test` → **96 passed / 376 assertions**;
`npm run build` → **OK** (600 kB JS / 83 kB CSS). Laravel 13.32, PHP 8.5, React 19.3,
React Router 7.18, Tailwind 4.3, Vite 8. No `.ai/rules` directory exists.

---

## 1. Architecture assessment

**What is already good — and must be preserved**

| Area | Evidence |
|---|---|
| Session auth + CSRF on all API routes | `routes/web.php:71-201`, all JSON inside the `web` group; Axios supplies `X-XSRF-TOKEN` from the framework cookie |
| Server-authoritative quiz scoring | `app/Services/QuizService.php:57-138` — frontend never posts a score |
| Per-source unique grade ledger | `grades` unique `(source_type, source_id)` |
| Deterministic, explainable intelligence | `method: 'deterministic_rules'` + `generated_at` on Learning Map / Roadmap / Radar; `evidence` strings on every radar flag; `assessment_score: null` + `status: 'unassessed'` is an explicit no-evidence signal (`MasteryCalculator.php:76-82`) |
| Correct ownership policies | `CoursePolicy`, `LessonPolicy`, `AssignmentPolicy`, `QuizPolicy` all resolve "owner OR enrolled" |
| Authorized file download | `MaterialController::download` authorizes then streams, `private` cache headers + `nosniff` |
| Real feature-test coverage of the hard parts | `QuizScoringTest`, `LearningInsightsTest`, `LearningMapTest`, `PortfolioTest`, `ChallengeTest`, `RoleAccessTest` |
| Contract-locked frontend↔backend shapes | No frontend file reads a key its Resource does not emit (verified key-by-key) |

**Weak structure**

- Controllers hold business logic: `DashboardController.php` (135 lines of queries), `InstructorSubmissionController::grade` (grading + ledger + progress + notification inline), `ChatController` (196 lines), `AssignmentStudentController::submit` (storage + versioning + notification inline).
- `LearningInsightService.php` is **810 lines** with two duplicated loaders that have already drifted (`LearningInsightService.php:86-117` vs `MasteryCalculator.php:193-223`).
- Services instantiate each other with `new` instead of DI (`LearningMapService.php:22-23`, `ChallengeService.php:24`, `PortfolioService.php:25`, `LearningInsightService.php:59`) — this is why the loader drift went unnoticed.
- 6 near-identical admin CRUD React pages; 2 near-identical support-chat pollers; ~25 pages with hand-rolled `useState/loading/error`.
- Role checks are stringly-typed at 24 query sites, bypassing the enums entirely (drift risk, not a live bug).

---

## 2. Feature gap analysis

Present: courses + sections + lessons + materials, enrollments, lesson progress with video
position, quizzes (MC/TF/short answer, attempts, time limit, pass score), assignments with
file upload + versioning + grading, a flat grade ledger, certificates with public verification,
deterministic intelligence (insights, map, challenge, roadmap, radar, portfolio), notifications
(database channel), support chat, role/permission tables, admin + instructor + student consoles.

Missing (by phase):

| Gap | Phase |
|---|---|
| Question bank, question types beyond MC/TF/SA, pools, blueprints, versioning | 2 (banks, pools and blueprints done; versioning left) |
| Assessment engine: autosave, resume, review mode, partial credit, negative marking, rubrics, exam windows, late rules | 2 (autosave, resume, enforced time limits, review mode, negative marking, rubrics, exam windows and late rules done) |
| Gradebook: per-course grid, categories, weights, dropped grades, overrides, CSV/XLSX, audit trail | 2 (course grid + course percentage done; categories, weights, dropped grades, overrides, export and audit trail left) |
| Notes, bookmarks, video timestamped notes, course reviews, tags, announcements, FAQ | 3 |
| Discussions/threads/mentions/moderation, live sessions, calendar, attendance | 4 |
| Reporting, bulk import/export, settings store, audit log, permission matrix | 5 |
| i18n layer, PWA, WCAG 2.2 AA sweep | 6 |
| `tenant_id` readiness | 5 (architected, not applied) |

**Not started, deliberately:** anything requiring a new dependency. No Spatie, no PDF lib, no
search engine, no component library. All of it is buildable on what is installed.

---

## 3. Database gap analysis

Present and correct: `enrollments` unique `(student_id, course_id)`; `lesson_progress` unique
`(student_id, lesson_id)` + index `(student_id, course_id)`; `assignment_submissions` unique
`(assignment_id, student_id)`; `quiz_answers` unique `(quiz_attempt_id, quiz_question_id)`;
`quiz_attempts` index `(quiz_id, student_id)`; every FK constrained with a delete rule.

Gaps:

| # | Gap | Impact |
|---|---|---|
| D1 | `lessons.quiz_id` / `lessons.assignment_id` have **no index** | `QuizService.php:126` does `Lesson::where('quiz_id',$id)->first()` → full scan of `lessons` on every quiz pass |
| D2 | `certificates` has **no unique `(student_id, course_id)`** | duplicate certificates are prevented only by application code → race permits duplicates |
| D3 | `quiz_attempts` has no index on `student_id` alone | every cross-quiz student metric scans |
| D4 | `course_prerequisites` has no index on `prerequisite_course_id` | `Course::prerequisiteFor()` scans |
| D5 | `grades` unique `(source_type, source_id)` — both columns **nullable** | MySQL treats NULLs as distinct, so manual grades (null source) are unconstrained |
| D6 | `assignment_submissions` no index on `student_id` | student submission history scans |
| D7 | `notifications.data` is `text`, not `json` | cross-driver divergence |
| D8 | `lessons` has no `video_disk` column | lesson video location is implicit, forcing it onto the public disk |
| D9 | `lesson_materials.disk` defaults to `'public'` while the controller always writes `'local'` | a landmine for any future row created without an explicit disk |

---

## 4. API gap analysis

- **No standard error envelope.** Validation errors, 403s, 404s and the catch-all
  `{"message":"Not found."}` (`routes/web.php:206`) have four different shapes. No `code`, no
  field-level `errors` guarantee.
- **No pagination metadata on most collections.** `CourseController::index` returns a raw
  `ResourceCollection` (meta only); `InstructorSubmissionController::index` hand-rolls
  `meta.current_page/last_page/total`; `NotificationController` has none; `DashboardController`
  embeds collections inside one giant object. Four pagination conventions.
- **Six pass-through Resources.** `LearningMapResource`, `PortfolioResource`,
  `InstructorRadarResource`, `CourseRoadmapResource`, `QuizReadinessResource`,
  `QuizRecoveryResource` are literally `return parent::toArray($request)`. The service array *is*
  the public API — renaming a service key silently changes the API.
- **`GET /api/me` exists; `/api/auth/me` is what the frontend calls** — the frontend 401s into
  `/login` for the rest of the app but its bootstrap call always fails. Latent bug.
- **No rate limiting** except `throttle:60,1` on course detail. No throttle on login, register,
  quiz submit, certificate verify, support chat.
- **No health/version endpoint.** `/up` exists but is Laravel's bare boot check.

---

## 5. Security findings

### CRITICAL

**S1 — Unauthenticated full lesson-content disclosure.**
`GET /api/courses/{course:slug}` is outside the `auth` group (`routes/web.php:74`).
`CourseController::show` loads `sections.lessons.materials` for anonymous visitors, and
`LessonResource` emits `content` and `video_url` unconditionally. **Proven**: a guest request
returns `"content":"Secret lesson body copy."`. Any paywall is bypassed by reading the public
catalogue endpoint.

**S2 — Lesson videos are world-readable static files.**
`InstructorLessonController.php:47,88` store on the **`public`** disk, and
`LessonResource` returns `asset('storage/'.$path)`. `public/storage` is a junction to
`storage/app/public`. Videos are therefore served with no authentication, no expiry and no
revocation — including videos for **unpublished** lessons. Directly violates "never expose
private files directly".

**S3 — Stored XSS in the learning view.**
`resources/js/pages/Learn.jsx:271` renders `dangerouslySetInnerHTML={{ __html: l.content }}`.
An instructor (a lower-privileged role than admin) can persist script that executes in every
enrolled student's session *and in admin sessions*. No sanitization anywhere on the write path
(`StoreLessonRequest` accepts any string).

**S4 — Unpublished lessons readable by ID.**
`LearningController::showLesson` (`routes/web.php:89`) route-binds any lesson with no
`is_published` filter and only checks `CoursePolicy::learn`. An enrolled student can walk
lesson IDs and read unpublished content.

### HIGH

**S5 — No brute-force protection on authentication.** `routes/web.php:64-65` — `POST
/api/login` and `POST /api/register` have no throttle. `LoginRequest` has no rate rules.

**S6 — User enumeration via the disabled-account message.** `AuthController.php:44-48` returns
`"Your account has been disabled"` for an existing inactive account vs `"These credentials do
not match"` for a wrong password.

**S7 — Unrestricted file upload on lesson materials.**
`InstructorMaterialController.php:22-23` validates only `file|max:102400`. No MIME, no
extension allowlist — `.php`, `.html`, `.svg` are accepted.

**S8 — Assignment uploads have no type restriction when the assignment defines none.**
`SubmitAssignmentRequest.php:26-30` applies `mimes:` **only** `if ($types !== [])`. An
assignment with `allowed_file_types = null` accepts any file.

**S9 — Race conditions on every critical workflow.** No transaction and no row lock in:
- `QuizService::submit` — two concurrent requests both pass the `isCompleted()` guard
  (`QuizService.php:63`), both write `QuizAnswer` rows, both `notify()`, and the attempt can be
  marked `Completed` twice.
- `QuizService::start` — `canStart` counts then inserts; concurrent double-click exceeds
  `attempts_allowed`.
- `EnrollmentService::enroll` — check-then-create (DB unique index does save it, but the error
  is a raw 500 rather than a validation message).
- `CertificateService::issue` — `nextNumber()` is `max('id') + 100001`, computed outside any
  lock, and `issue` is check-then-create with no unique constraint (D2).
- `InstructorSubmissionController::grade` — ledger write + progress + notification are not atomic.

**S10 — `submit` payload is unvalidated against the quiz.** `SubmitQuizRequest.php:21` accepts
`questions.*.question_id` as a bare `integer` with no `exists` scoping to the quiz and **no
`max` array size**, so a client can submit an arbitrarily large answer array.

### MEDIUM

**S11 — `LessonMaterial` disk default `'public'` (D9)** contradicts the controller's `'local'`.
**S12 — `StoreCourseRequest`/`UpdateCourseRequest`/quiz/lesson/assignment requests authorize
`$user->isInstructor()` only** — an admin passes the `role:instructor` middleware but is then
rejected by the FormRequest, so admins cannot create content. Frontend and backend disagree.
**S13 — Icon-only buttons with no accessible name** (`AdminRoles.jsx:126,129`,
`InstructorCourseBuilder.jsx:748,751`). **S14 — `target="_blank"` without `rel="noreferrer"`**
(`Certificates.jsx:55,64`, `InstructorSubmissions.jsx:167`). **S15 — `Modal` has no focus trap,
no Escape handler, no focus restore, no `aria-labelledby`** (`ui.jsx:252-273`, ~8 call sites).
**S16 — orphaned `role="option"`** with no `role="listbox"` ancestor (`Layout.jsx:120-129`).
**S17 — `variant="ghost"` used 8× but absent from `buttonVariants`** and `color="brand"` absent
from `badgePalette` → those controls render with no classes at all.

---

## 6. Performance findings

| # | Finding | Location |
|---|---|---|
| F1 | ~~**N+1 in the learning map**~~ **Fixed** — `quizReadinessFor()` re-fetched the course graph, attempts and graded submissions once per enrolled course | `LearningMapService.php:51` inside the loop at `:31` |
| F2 | **`hasRole()`/`hasPermission()` query the DB on every call**; `isAdmin()` is called in `Gate::before`, every `role:` middleware hit and every policy → 1 query per authorization, 4 queries per `hasPermission` | `HasRoles.php:75-84,110-118`; `User.php:74-93` |
| F3 | 5 policies each run an `Enrollment` existence query per model | `CoursePolicy.php:59`, `LessonPolicy.php:19`, `QuizPolicy.php:19,27`, `AssignmentPolicy.php:19,27`, `LessonMaterialPolicy.php:21` |
| F4 | `Lesson::where('quiz_id',…)->first()` — unindexed (D1) | `QuizService.php:126` |
| F5 | Three **unbounded** `pluck()`s of a student's whole history, filtered in PHP to count a 7-day window | `LearningInsightService.php:495-511` |
| F6 | Learning-minutes algorithm implemented twice and already inconsistent | `LearningInsightService.php:447-476` (7d) vs `PortfolioService.php:112-139` (all-time) |
| F7 | Radar hydrates full `lessonProgress`, `quizAttempts`, `assignmentSubmissions` and `certificates` per student, then aggregates in PHP; no chunking over the cohort | `InstructorRadarService.php:48-172` |
| F8 | `lesson_materials.disk` and `sort_order` unindexed; `max('sort_order')` per material insert | `InstructorMaterialController.php:36` |
| F9 | 600 kB single JS chunk, no code splitting | `npm run build` warning |
| F10 | Two independent 8 s pollers on the same chat resources, no `visibilitychange` pause | `ChatWidget.jsx`, `AgentInbox.jsx:41-53` |

### Correctness bug found in the intelligence layer

**B1 — `MasteryCalculator::loadQuizAttempts` omits `passed` from its column list**
(`MasteryCalculator.php:221` → `['id','quiz_id','score_percentage','submitted_at']`).
`LearningMapService.php:93-94` then tests `(bool) $attempt->passed`, which is always `null`.
**The "skip quizzes already passed" guard is dead code** — a student who has passed a quiz is
still shown it as `upcoming_quiz` with state `ready`. The sibling loader at
`LearningInsightService.php:115` does select `passed`, which is exactly why no test catches it.

**B2 — the same loader starves `time_limit_minutes` and `questions_count`**
(`MasteryCalculator.php:202` → `quiz:id,course_id,title`). Every `upcoming_quiz.lesson` served
through the learning map therefore reports `estimated_minutes: 5, estimated: true`, and the same
quiz via `/api/quizzes/{quiz}/readiness` reports the real time limit. **Two endpoints disagree
about the same quiz.**

---

## 7. Testing gaps

Present: 96 feature tests covering auth, roles, enrollments, progress, quiz scoring, grading,
certificates, and the intelligence layer. Zero frontend tests — `package.json` has no test runner,
so nothing in §5's S3/S13-S17 or the page-level state handling is verified.

Uncovered and load-bearing:

- Authorization **negatives** for materials, quiz attempts, assignment submissions, certificates,
  and the support chat. `RoleAccessTest` only covers 4 endpoints.
- Data-integrity negatives: double quiz submit, attempt-limit bypass, duplicate enrollment,
  duplicate certificate, re-grade idempotency.
- File-upload MIME rejection (S7, S8) and download authorization.
- IDOR on `?student=` in `InstructorSubmissionController::studentSubmissions`.
- Lesson-content gating (S1/S2/S4) — **now covered by the new `CourseContentAccessTest`**.
- Every claim in §6 (no test asserts a query count) and every intelligence contract that is *not*
  a locked key: `InstructorRadarService` stats and flag kinds, `generated_at` format,
  `method` presence, `best_quiz_percentage` semantics, `courses_completed` semantics.

Two contract smells worth recording before they calcify:
`PortfolioService.php:72` computes `courses_completed` from **certificates**, not completions
(locked by `PortfolioTest.php:29,83`); `InstructorRadarService.php:164` computes
`best_quiz_percentage` as a max across **all of the course's quizzes**, not a per-quiz best;
`InstructorRadarService.php:166` returns a 0|1 flag under the plural name `assignments_overdue`.

---

## 8. Prioritized roadmap

**Phase 1 — audit, security, data integrity, architecture cleanup** *(this pass)*
1. Gate `content` / `video_url` behind entitlement (S1) — keys preserved, values gated.
2. Private lesson video disk + authorized streaming route (S2, D8).
3. Reject unpublished lessons in `showLesson` (S4).
4. Sanitize lesson HTML on write (S3).
5. Throttle login/register; generic auth failure (S5, S6).
6. Transactions + row locks: quiz submit/start, enroll, certificate, grade (S9, D2).
7. Upload allowlists with a safe default (S7, S8).
8. Index/constraint migration (D1-D7, D9).
9. Fix `MasteryCalculator` `passed` + quiz column starvation (B1, B2).
10. Remove the authorization N+1 (F2, F3) via a per-request entitlement memo.
11. `GET /api/health`.
12. Regression tests for every item; full suite + Pint + build.

**Phase 2** question bank → advanced assessment engine → gradebook.
**Phase 3** media abstraction, notes, bookmarks, reviews, discussions.
**Phase 4** live sessions, calendar, attendance, notification preferences, queued mail.
**Phase 5** reporting, import/export, settings store, audit log, permission matrix, `tenant_id` scaffolding.
**Phase 6** i18n layer, PWA, WCAG 2.2 AA sweep, `ghost`/`brand` variant bugs, modal focus management.
**Phase 7** caching, queues, chunked analytics, code splitting.
**Phase 8** frontend test runner, E2E for the 8 required journeys.
**Phase 9** visual polish.

---

## 9. Implementation log

Recorded as each item lands, with the tests that prove it.

Baseline before this pass: **96 tests, 376 assertions**. After Phase 1: **155 tests, 520 assertions**. After the Phase 2 question-type slice: **205 tests, 599 assertions**. After the question-bank slice: **238 tests, 705 assertions**. After the learning-map N+1 fix: **239 tests, 710 assertions**. After the quiz blueprint slice: **267 tests, 788 assertions**. After the autosave and resume slice: **294 tests, 847 assertions**. After the review-mode slice: **304 tests, 877 assertions**. After the negative-marking slice: **318 tests, 927 assertions**. After the rubric slice: **334 tests, 970 assertions**. After the exam-windows slice: **343 tests, 1005 assertions**. After the late-rules (grace period) slice: **352 tests, 1048 assertions**. After the core gradebook slice: **361 tests, 1094 assertions**. All passing, on MySQL 8 (`db_lms`) with a full `migrate` → `rollback` → `migrate` round trip verified.

| # | Item | Change | Proof |
|---|------|--------|-------|
| 1 | Gate lesson content (S1) | `app/Support/CourseAccess.php` memoizes entitlement per request; `LessonResource` returns `null` for `content`/`video_url`/`external_url`/materials. Keys preserved. | `CourseContentAccessTest` |
| 2 | Private video (S2, D8) | `lessons.video_disk` column, backfill + file move to the private `local` disk, `LessonVideoController` streams with entitlement + HTTP ranges. | `LessonVideoAccessTest`, migration round trip |
| 3 | Unpublished lessons (S4) | `LearningController::showLesson` returns 404 for drafts; policy hides bodies for everyone but the owner/admin. | `CourseContentAccessTest` |
| 4 | Lesson HTML write (S3) | `HtmlSanitizer` allowlists structure and strips script/handlers/unsafe schemes; applied on create and update. | `LessonContentSanitizationTest` |
| 5 | Auth hardening (S5, S6) | Login 6/min, register 5/min, generic `INVALID_CREDENTIALS`, failed attempts logged with a PII-considering payload. | `AuthenticationHardeningTest` |
| 6 | Concurrency (S9, D2) | Transaction + `lockForUpdate` around quiz start/submit, enrollment, certificate issuance (with number-collision retry), and grading. | `DataIntegrityTest` |
| 7 | Uploads (S7, S8) | `UploadRules` + `config/lms.php` allowlists with a safe default and a globally forbidden extension set; all three upload paths covered. | `UploadValidationTest` |
| 8 | Indexes (D1-D7, D9) | 6 lookup indexes, unique certificate `(student_id, course_id)`, nullable `attempt_number` backfilled to 1. Redundant certificate index dropped. | `DataIntegrityTest`, `information_schema` check |
| 9 | Mastery correctness (B1, B2) | `passed`/`status` returned; attempt query no longer starves on missing `time_limit_minutes`. | `LearningMapTest`, `MasteryCalculator` tests |
| 10 | Authorization N+1 (F2, F3) | `CourseAccess` memo; `LoadUserRoles` web middleware eager-loads roles+permissions once; `HasRoles` reads loaded relations. | covered by the suites above |
| 11 | Health endpoint | `GET /api/health` checks DB + public storage writability, no auth, no PII. | `AuthenticationHardeningTest` |
| 12 | Regression suite | 6 new feature suites + additions to `LearningMapTest`. | 155 passing |
| 13 | Assessment-authority split | `CourseAccess::canAttemptAssessment()` added. Owners and admins keep content read access via `canLearn()` but can no longer sit a quiz or hand in an assignment, which would otherwise write attempts into their own gradebook. | `CourseContentAccessTest` |
| 14 | Course-scoped media | Uploaded lesson videos are stored under `lessons/videos/{course_id}/`, not the uploading instructor's id. | `LessonVideoAccessTest` |
| 15 | Grading strategy (A1) | `Grader` contract owns `grade()`, `serialize()`, `decode()` and `settingsForStudent()`; `GraderRegistry` maps each `QuizQuestionType` to an implementation. `QuizService` no longer knows how any individual type is scored. | `QuizGradingTest` |
| 16 | New question types (A2) | `multi_select` (optional proportional partial credit), `numeric` (hidden accepted value, absolute tolerance, unit/scientific parsing) and `fill_in_blank` (`{{1}}` placeholders, per-blank alternatives, optional partial credit). Each grader is a separate class. | `QuizQuestionTypeGradingTest` |
| 17 | Hidden answer keys | `QuizStudentController::start()` returns a minimal attempt plus a per-type `settingsForStudent()` projection instead of the raw attempt, whose loaded relations handed over `options[].is_correct` and the `settings` answer key before submission. | `QuizQuestionTypeGradingTest` |
| 18 | Authoring | `StoreQuizQuestionRequest` validates type-specific settings and rejects keys that do not apply to the type, so an author is not left with a setting the grader silently ignores. Numeric/fill-in-blank keys live in `settings`, never in `options`, because every option row is rendered to the student. Blanks must line up with the placeholders in the text. | `QuizQuestionAuthoringTest` |
| 19 | Authoring and student UI | `QuestionInput`/`QuestionReview`/`questionTypes` render and review every type from the same per-type switch; the instructor form covers all six types. The answered counter tests each question for a real answer, so an empty multi-select or a half-filled blank no longer counts as answered. | `npm run build` |
| 20 | Question banks (A3) | Course-scoped `question_banks` hold a reusable pool; a quiz either owns its questions or names a bank plus a `draw_size`. `QuizQuestion` is exclusive to one owner (model and request guards, since SQLite cannot add a CHECK to an existing table) and every attempt freezes the questions it was served in `quiz_attempt_questions`. | `QuestionBankTest` |
| 21 | Attempt snapshots | `QuizService::start()` writes the drawn set and `submit()`, the result page and diagnosis all read `$attempt->questions`. `plannedQuestionCount()` reports the draw size for a bank quiz, so a lesson is no longer shown as "0 questions". | `QuestionBankTest`, `QuizDiagnosisApiTest` |
| 22 | Frozen questions | A question already served in an attempt cannot be edited or deleted, from either the quiz or the bank, and `quiz_attempt_questions.quiz_question_id` is `ON DELETE RESTRICT` as a backstop. The builder disables those controls and labels the question "In use" instead of failing on save. | `QuestionBankTest` |
| 23 | Bank builder UI | Banks are managed from `Curriculum → Question banks`; `QuizSettingsForm` covers both create and edit paths, surfaces `draw_size`/`question_bank_id` errors on their own fields, and blocks the bank switch while a quiz still owns questions because the server refuses it. | `npm run build` |
| 24 | Detaching a bank | `InstructorQuizController::update` sets `question_bank_id` and `draw_size` explicitly. Spreading the validated payload only writes keys the client sent, so a quiz that went back to owning its questions would have kept drawing from the bank forever, and the builder's "This quiz only" control would have done nothing. | `QuestionBankTest` |
| 25 | Learning-map N+1 (F1) | `LearningMapService` hands the batch-loaded course, its flattened lessons and its attempts to `quizReadinessFor()` instead of letting it re-derive them. The load was not a harmless repeat: `$quiz->course` and `$quiz->lesson` are separate model instances that share no loaded relations, so each course paid for its own sections/lessons/progress/attempts/submissions walk. Attempts and graded submissions are now also memoised per student. Queries for the map are flat in course count: 17 for 1 course and 47 for 4 before, 8 and 8 after. | `LearningMapTest::test_learning_map_query_count_does_not_grow_with_enrolled_courses` |
| 26 | Upcoming-quiz passing score | The map's quiz eager load selected only `id,course_id,title,time_limit_minutes`, so the upcoming-quiz card rendered every quiz's passing score as 0%. `passing_score` is now selected, and `withCount('questions')` follows the `select()` (the reverse order silently drops the count sub-select, which made every quiz-lesson duration estimate fall back to its own `count(*)`). | `LearningMapTest::test_a_failed_quiz_is_still_offered_as_upcoming` |
| 27 | Quiz blueprints (A4) | `quiz_blueprint_rules` gives a quiz per-type quotas over its bank: a paper that asked for a shape no longer comes out as a uniform sample. `draw_size` stays the paper length and the quotas are exact, with the leftover slots filled from the types the blueprint left blank, so a blueprint that could be inflated by the fill would describe nothing. A quota the bank cannot supply degrades to what exists and tops up from the rest, because a truncated paper is worse than an overshot quota. | `QuizBlueprintTest` |
| 28 | Blueprint authoring | The builder edits quotas per type alongside the draw size, reuses one `QUESTION_TYPES` list with the question form, and a server-side duplicate check backs the unique index on `(quiz_id, question_type)`. Quotas are dropped with the bank and the quiz row and its rules are saved in one transaction, so a half-applied save cannot leave quotas on a quiz that no longer draws. | `QuizBlueprintTest` |
| 29 | Quiz autosave, resume and enforced time limits | Answers are written to `quiz_answers` ungraded (`points_earned` nullable, so a draft is distinguishable from a graded zero) and a second Start resumes the open attempt instead of minting a second paper, with the frozen paper and saved answers restored. A time limit used to be advisory: the client discarded the answers at zero, the attempt stayed `in_progress` forever, and only `completed` attempts counted, so a student could start again for a fresh full-duration draw indefinitely. Now an attempt past its deadline is closed, graded from what was saved, recorded in the gradebook, and counts against `attempts_allowed`. | `QuizAttemptResumeTest` |
| 30 | Autosave round-tripping | A saved answer is decoded through the grader on resume, so a multi-select returns a list and a cleared choice returns null rather than option `0`. The client debounces writes, flushes on unmount and on tab-hide, and submits at the deadline instead of throwing the paper away. `QuizAnswer::question()` and `QuizOption::question()` were missing their foreign key and resolved to a non-existent `question_id` column, so they returned null instead of failing loudly. | `QuizAttemptResumeTest` |
| 31 | Review mode | Every graded attempt -- completed or timed out -- gets a durable, addressable report at `/quiz/attempts/{id}` rendered from the existing read-only endpoint, and the overview lists the student's own recent attempts with a Review link on each. `reviewable` is `submitted_at !== null`, not a status: an expired attempt is graded, so a status check would drop it from history. The report reads the frozen paper, so a quiz that grew after a student finished still shows exactly the questions that were sat. | `QuizReviewModeTest` |
| 32 | Negative marking | A wrong but *attempted* answer deducts `settings.negative_marking` (a fraction of the question's points, one setting shared across every scored type). A blank is never penalised, so running out of time is not charged a second time; an answer that earns any partial credit is never penalised; per-question `points_earned` may go negative while the attempt total and percentage are floored at zero. The rule is disclosed in `settingsForStudent()` before starting and on the review, and the disclosure still excludes the answer key (`answer` and `blanks` never leak). The instructor form gained a shared fraction input for all six types, validated as numeric in `[0, 1]`. | `QuizNegativeMarkingTest` |
| 33 | Rubrics for short answers | A short-answer question can swap its exact-match rule for a rubric: an ordered list of criteria, each naming what the marker looks for, the whole terms that signal it, and how many points it is worth. A wrong-but-on-topic answer earns the points of every criterion whose terms appear (any matching term counts, matched as a whole word so `rate` does not fire on `separate`), capped at the question's points. The terms are the scoring rule, so `settingsForStudent()` discloses the criteria labels and points -- shown to the student before starting and in the review -- but never the keywords. Blank answers still score zero, and negative marking applies to a fully wrong attempt but never to a blank. | `QuizRubricTest` |
| 34 | Exam windows (A5) | A quiz can be bound to an availability window (`available_from` ... `available_until`). `deadlineFor()` is the single place a deadline is worked out, so clamping it with the window's close means a window that shuts mid-paper ends the attempt exactly like a clock running down: the in-flight attempt is closed and graded from its autosaves the next time it is noticed, and a late autosave is rejected as expired. The window gates *new* attempts, so a closed (or not-yet-open) quiz refuses to Start; the rejection is returned from `start()`'s transaction and raised after it, so the close-what-expired step commits before the window turns the request away. The overview disabled the Start button and explains the window ("opens on ..." / "closed on ..."), and the builder edits the window on the settings form, validated to keep `available_until` after `available_from`. | `QuizExamWindowTest` |
| 35 | Late rules -- grace period (A5) | A quiz can grant `late_grace_minutes` after its strict deadline. The strict deadline keeps meaning the student races; the grace puts a second, later cutoff (`answerableUntil` = strict deadline + grace) at which the attempt is force-closed. Between the two, autosaves are accepted and a submission is graded normally but flagged `submitted_late` ("Late" badge, amber on the result screen); past the cutoff the attempt expires exactly as without grace. The client counts down overtime against the grace end instead of auto-submitting at zero, and the overview keeps offering the resumable attempt while its grace runs. The same restart also fixed `start()`: its `attempts`/`questions` rejections threw *inside* the transaction, so closing an out-of-time attempt and then refusing a rerun rolled the close back -- the graded paper came back `in_progress`. They are now returned from the transaction and raised after it, like the window rejection. | `QuizLateGraceTest` |
| 36 | Core gradebook (B2) | A per-course gradebook page joins the course's enrollments (rows) with its quizzes and assignments (columns) and a course percentage on the right. `GradebookService` reads the same ledger rows the graders write and never computes anything the record already holds: a quiz cell is the student's best graded attempt (a quiz grades one ledger row per attempt, so the mean would be polluted by retakes) and an assignment cell is the single submission row. The course percentage is the simple mean of every graded cell, and a column's class average covers only graded students -- an untaken assessment is a dash that counts for nothing rather than a zero. Nothing is paginated and everything is eager-loaded (grades carry their polymorphic `source`, the way to a grade's quiz or assignment, since `source_id` is an attempt or submission id). Active and completed enrollments are rows, cancelled ones are not. The builder links to the page and the route is scoped to the owner like every other course action. | `InstructorGradebookTest` |

### Notes and residual risk

- **Rollback resilience.** MySQL reuses an index as the backing key for a foreign key, so dropping one requires lifting the keys first. `down()` in the integrity migration therefore drops and restores every affected foreign key, and guards each drop with `Schema::hasIndex` / `Schema::getForeignKeys` so an interrupted rollback can be re-run safely instead of failing on a missing key.
- **Two authorisation axes, not one.** `canLearn()` (owners, admins, enrolled students) governs *reading* course content. `canAttemptAssessment()` (enrolled students only) governs *creating graded records*. Collapsing these into one helper is what let course owners take their own quizzes mid-pass; keep them separate and route every new gated feature to the one that matches its intent.
- **Content boundary is one method.** `CourseAccess::canViewLessonContent()` is the single gate for lesson bodies and media. A future content type only needs to route through it; nothing in `LessonResource` should make its own authorization decision.
- **`CourseAccess` is request-scoped, not process-scoped.** It memoizes on the request, so long-lived workers (queues, Octane) must not reuse it across requests without calling `flush()`.
- **Grading is a strategy, and the strategy owns the secret.** Each `Grader` both scores a submission and projects its own settings for the student. That is deliberate: the answer key is the same knowledge the scoring rule needs, so keeping `settingsForStudent()` beside `grade()` means a new type has to decide what is safe to send rather than inheriting a default that might leak. `fill_in_blank` and `numeric` keep their key in `settings` rather than in `options` for the same reason — every option row is shown to the student, so an option-based key would be visible before submitting.
- **A question type is a backend change first.** Adding a case to `QuizQuestionType` without a grader, a `settingsForStudent()` projection, and validation will fail loudly at the registry rather than quietly scoring every submission zero.
- **A random draw has to clear the relation's own ordering.** `QuestionBank::questions()` orders by `sort_order` so the builder shows a stable list, and SQL honours only the first `ORDER BY`. `inRandomOrder()` on top of that looked random in the code and served every student the same first N questions; the draw uses `reorder()` instead. Any future "pick some at random" query has to do the same.
- **A column subset on a has-many eager load must include the foreign key.** `with('courses:id,title,slug')` matches rows on `category_id`, which was not selected, and silently hydrates an empty collection rather than raising - the `withCount` total stays correct, so only the missing list gave it away. `belongsTo` subsets are safe because the key already sits on the parent row.
- **Deliberately not done in this pass.** Certificate PDF generation, the frontend issues catalogued in §6/§7 (dark-mode tokens, stale short-answer options, `ghost`/`brand` variants, modal focus, typography, code splitting), question versioning, and the gradebook extras: categories, weights, dropped grades, overrides, CSV/XLSX export and an audit trail (the core grid is in).

### Follow-ups worth prioritising next

1. Certificate PDF generation — certificates are currently data-only with no printable artifact.
2. The frontend items in §6/§7, which are cosmetic and accessibility work rather than correctness.
