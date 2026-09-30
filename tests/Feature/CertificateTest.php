<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Notifications\CertificateIssued;
use App\Services\EnrollmentService;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private function completeCourse(User $student, int $lessons = 2): array
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);

        $lessonsModels = collect(range(1, $lessons))->map(fn (int $order): Lesson => Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => $order,
            'is_published' => true,
        ]));

        (new EnrollmentService)->enroll($student, $course);

        foreach ($lessonsModels as $lesson) {
            app(ProgressService::class)->completeLesson($lesson, $student);
        }

        return ['course' => $course, 'lessons' => $lessonsModels];
    }

    public function test_certificate_is_issued_when_all_lessons_are_completed(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $enrollment = Enrollment::where('student_id', $student->id)->where('course_id', $course->id)->first();

        $this->assertSame('completed', $enrollment->status->value);
        $this->assertDatabaseHas('certificates', [
            'student_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->first();
        $this->assertStringStartsWith('LMS-', $certificate->certificate_number);
        $this->assertNotNull($certificate->identifier);
    }

    public function test_certificate_is_not_duplicated_for_repeated_progress_updates(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course, 'lessons' => $lessons] = $this->completeCourse($student);

        app(ProgressService::class)->completeLesson($lessons->first(), $student);

        $this->assertSame(1, Certificate::where('student_id', $student->id)->where('course_id', $course->id)->count());
    }

    public function test_public_verification_page_renders(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->firstOrFail();

        $this->get("/certificates/verify/{$certificate->identifier}")
            ->assertOk()
            ->assertSee($student->name);
    }

    public function test_unknown_identifier_shows_not_found(): void
    {
        $this->get('/certificates/verify/not-a-real-identifier')->assertNotFound();
    }

    public function test_a_certificate_has_a_printable_document(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->firstOrFail();

        // Requested as a guest, which is the point: the document is the artifact
        // of record and has to open for whoever holds the link, not only for the
        // student it was issued to.
        $this->get("/certificates/{$certificate->identifier}")
            ->assertOk()
            ->assertSee($student->name)
            ->assertSee($course->title)
            ->assertSee($certificate->certificate_number)
            ->assertSee(config('lms.institution_name', 'LMS'))
            // The print control and the handler behind it: without both, "printable"
            // means whatever the browser decides to do with the page.
            ->assertSee('id="print-certificate"', false)
            ->assertSee('Print / Save as PDF')
            ->assertSee('window.print()', false)
            // An unsized @page leaves the sheet to the browser's default, which is
            // how a certificate ends up printed on letter portrait.
            ->assertSee('size: A4 landscape', false)
            // A paper copy can only be trusted if it says how to check it.
            ->assertSee(route('certificates.verify', $certificate->identifier), false);
    }

    public function test_an_unknown_identifier_has_no_printable_document(): void
    {
        $this->get('/certificates/not-a-real-identifier')->assertNotFound();
    }

    public function test_a_certificate_can_be_verified_by_its_number(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->firstOrFail();

        // The verification page asks for a "Nomor sertifikat" and the printed
        // certificate shows the number, but only the identifier used to resolve,
        // so a holder holding nothing but the number on their copy was told 404.
        $this->get("/certificates/verify/{$certificate->certificate_number}")
            ->assertOk()
            ->assertSee($student->name);
    }

    public function test_the_certificate_api_exposes_the_document_url(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->firstOrFail();

        // The client used to build this path itself out of the identifier, so the
        // route's shape was duplicated into the frontend. The payload is where it
        // belongs, and this is the assertion that keeps it there.
        $this->actingAs($student)
            ->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonPath('data.0.certificate_url', route('certificates.show', $certificate->identifier));
    }

    public function test_the_certificate_email_links_to_a_document_that_exists(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->firstOrFail();

        $mail = (new CertificateIssued($certificate))->toMail($student);

        $this->assertSame(route('certificates.show', $certificate->identifier), $mail->actionUrl);

        // The link is the whole purpose of the mail, so the proof that it is right
        // is that it resolves. It did not: the action URL was built from the
        // certificate id while the route looks the certificate up by identifier,
        // so every "View Certificate" mail sent a 404.
        $this->get($mail->actionUrl)->assertOk();
    }

    public function test_the_certificate_manage_permission_denies_a_user_without_it(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        $this->actingAs($admin)
            ->getJson('/api/admin/certificates')
            ->assertOk();

        // `certificates.manage` is published in the roles matrix, so it has to
        // gate something. `CertificatePolicy::manage()` existed and nothing ever
        // called it, which made the catalog advertise a capability no code
        // enforced.
        //
        // This asserts the policy has a real deny path, not that the HTTP
        // response depends on it: `role:admin` middleware already rejects
        // non-admins and `Gate::before` grants admins everything, so no request
        // can distinguish "middleware said no" from "the policy said no". The
        // controller call keeps the two from silently diverging if either changes.
        $this->assertTrue(Gate::forUser($admin)->allows('manage', Certificate::class));
        $this->assertFalse(Gate::forUser($student)->allows('manage', Certificate::class));
    }

    public function test_the_portfolio_links_to_the_certificate_document(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->firstOrFail();

        // The portfolio used to link to a client-side `/certificates/{identifier}`
        // route that rendered the certificate *list*, so a student clicking a
        // certificate in their portfolio never saw the certificate. The document
        // is server-rendered and public, so the payload carries its URL.
        $this->actingAs($student)
            ->getJson('/api/portfolio')
            ->assertOk()
            ->assertJsonPath('data.certificates.0.certificate_url', route('certificates.show', $certificate->identifier));

        $this->get(route('certificates.show', $certificate->identifier))->assertOk();
    }
}
