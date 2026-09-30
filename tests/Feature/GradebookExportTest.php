<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradebookCategory;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Exporting the gradebook.
 *
 * The export is generated from the same payload the page renders, so these tests
 * are mostly about the two ways a spreadsheet can quietly lie: a dropped grade
 * that reads as a mark, and a name that a spreadsheet executes.
 */
class GradebookExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_csv_grid_is_the_page_the_instructor_is_looking_at(): void
    {
        $course = $this->course('intro-php');
        $alice = $this->enroll($course, 'Alice');
        $bob = $this->enroll($course, 'Bob');
        $midterm = $this->quiz($course, 'Midterm');
        $essay = $this->quiz($course, 'Essay');

        $this->grade($course, $midterm, $alice, 9, 10);
        $this->grade($course, $essay, $alice, 5, 10);
        $this->grade($course, $midterm, $bob, 4, 10);

        $rows = $this->parse($this->csv($course));

        // The same arithmetic as the page: Alice is on 70, Bob on 40.
        $this->assertSame(
            ['Student' => 'Alice', 'Email' => $alice->email, 'Essay' => '50', 'Midterm' => '90', 'Course grade' => '70', 'Adjustments' => null],
            $this->row($rows, 'Alice', $this->columns($rows))
        );
        $this->assertSame(
            ['Student' => 'Bob', 'Email' => $bob->email, 'Essay' => null, 'Midterm' => '40', 'Course grade' => '40', 'Adjustments' => null],
            $this->row($rows, 'Bob', $this->columns($rows))
        );
    }

    public function test_a_dropped_grade_exports_as_a_blank_cell_and_says_why(): void
    {
        $course = $this->course('intro-php');
        $alice = $this->enroll($course, 'Alice');
        $midterm = $this->quiz($course, 'Midterm');
        $essay = $this->quiz($course, 'Essay');
        $dropped = $this->grade($course, $essay, $alice, 5, 10);
        $this->grade($course, $midterm, $alice, 9, 10);

        $this->actingAs($course->instructor)
            ->putJson("/api/instructor/courses/{$course->slug}/gradebook/grades/{$dropped->id}", ['dropped' => true])
            ->assertOk();

        $rows = $this->parse($this->csv($course));
        $columns = $this->columns($rows);
        $row = $this->row($rows, 'Alice', $columns);

        // A blank cell, because a zero is a mark and this one is not a mark. A
        // spreadsheet averages a blank cell away; it would average a zero in.
        $this->assertNull($row['Essay'], 'The excluded grade is blank rather than zero.');
        $this->assertSame('90', $row['Midterm']);
        $this->assertSame('90', $row['Course grade']);
        $this->assertSame('Essay: excluded from the course grade (was 50%)', $row['Adjustments']);
    }

    public function test_an_adjusted_grade_exports_the_score_the_course_reports(): void
    {
        $course = $this->course('intro-php');
        $alice = $this->enroll($course, 'Alice');
        $grade = $this->grade($course, $this->quiz($course, 'Midterm'), $alice, 6, 10);

        $this->actingAs($course->instructor)
            ->putJson("/api/instructor/courses/{$course->slug}/gradebook/grades/{$grade->id}", ['score' => 8])
            ->assertOk();

        $rows = $this->parse($this->csv($course));
        $row = $this->row($rows, 'Alice', $this->columns($rows));

        $this->assertSame('80', $row['Midterm']);
        $this->assertSame('80', $row['Course grade']);
        $this->assertSame('Midterm: score adjusted to 80%', $row['Adjustments']);
    }

    public function test_a_column_name_carries_its_category(): void
    {
        $course = $this->course('intro-php');
        $category = GradebookCategory::factory()->for($course)->create(['name' => 'Homework', 'weight' => 40]);
        $alice = $this->enroll($course, 'Alice');
        $this->grade($course, $this->quiz($course, 'Week 1', $category), $alice, 8, 10);

        $header = $this->parse($this->csv($course))[3];

        $this->assertSame(['Student', 'Email', 'Homework · Week 1', 'Course grade', 'Adjustments'], array_slice($header, 0, 5));
    }

    public function test_a_name_that_a_spreadsheet_would_execute_is_exported_as_text(): void
    {
        $course = $this->course('intro-php');
        $student = $this->enroll($course, '=HYPERLINK("http://example.test","click me")');
        $this->grade($course, $this->quiz($course, 'Midterm'), $student, 5, 10);

        $csv = $this->csv($course);

        // A cell starting with `=` is a formula to Excel and Sheets, so a student
        // who named themselves one would have it run in the instructor's copy.
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_the_csv_is_readable_by_a_spreadsheet(): void
    {
        $course = $this->course('intro-php');
        $student = $this->enroll($course, 'Amélie');
        $this->grade($course, $this->quiz($course, 'Café quiz'), $student, 5, 10);

        $response = $this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/export?format=csv")
            ->assertOk();

        // Excel decodes a BOM-less UTF-8 file as the local codepage, which turns
        // every accented name in the gradebook into mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
        $this->assertStringContainsString('Amélie', $response->streamedContent());
    }

    public function test_the_xlsx_is_a_workbook_a_spreadsheet_can_open(): void
    {
        $course = $this->course('intro-php');
        $alice = $this->enroll($course, 'Alice');
        $grade = $this->grade($course, $this->quiz($course, 'Midterm'), $alice, 6, 10);

        $this->actingAs($course->instructor)
            ->putJson("/api/instructor/courses/{$course->slug}/gradebook/grades/{$grade->id}", ['dropped' => true])
            ->assertOk();

        $response = $this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/export?format=xlsx")
            ->assertOk();

        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));

        $path = tempnam(sys_get_temp_dir(), 'xlsx-');
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The export is a readable zip archive.');

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
        ] as $part) {
            $this->assertNotFalse($zip->getFromName($part), "The workbook contains {$part}.");

            // Well-formed at least: a malformed part is a file Excel refuses.
            $this->assertNotFalse(simplexml_load_string((string) $zip->getFromName($part)), "{$part} is well-formed XML.");
        }

        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('<sheet name="Gradebook" sheetId="1" r:id="rId1"/>', $workbook);
        $this->assertStringContainsString('Alice', $sheet);
        $this->assertStringContainsString('t="inlineStr"', $sheet);
    }

    public function test_the_xlsx_marks_an_adjusted_grade_and_an_excluded_one_differently(): void
    {
        $course = $this->course('intro-php');
        $alice = $this->enroll($course, 'Alice');
        $adjusted = $this->grade($course, $this->quiz($course, 'Midterm'), $alice, 6, 10);
        $excluded = $this->grade($course, $this->quiz($course, 'Essay'), $alice, 5, 10);

        foreach ([[$adjusted, ['score' => 8]], [$excluded, ['dropped' => true]]] as [$grade, $payload]) {
            $this->actingAs($course->instructor)
                ->putJson("/api/instructor/courses/{$course->slug}/gradebook/grades/{$grade->id}", $payload)
                ->assertOk();
        }

        $sheet = simplexml_load_string($this->sheet($course));
        $this->assertNotFalse($sheet);

        // Alice's row. Which column is which is settled by the CSV tests; what
        // matters here is that the two marks are told apart in the file itself.
        $cells = [];
        foreach ($sheet->row[4]->c as $cell) {
            $cells[(string) $cell['r']] = ['style' => (int) $cell['s'], 'value' => (string) ($cell->v ?? '')];
        }

        $adjusted = collect($cells)->firstWhere('value', '80');
        $excluded = collect($cells)->first(fn (array $cell): bool => $cell['value'] === '' && $cell['style'] !== 0);

        $this->assertNotNull($adjusted, 'The adjusted grade is written as a number.');
        $this->assertNotNull($excluded, 'The excluded grade keeps a cell so it can be filled.');
        $this->assertSame(2, $adjusted['style'], 'The adjusted grade is the amber fill.');
        $this->assertSame(3, $excluded['style'], 'The excluded grade is the slate fill.');

        $this->assertStringContainsString('FFFEF3C7', $this->part($course, 'xl/styles.xml'));
        $this->assertStringContainsString('FFE2E8F0', $this->part($course, 'xl/styles.xml'));
    }

    public function test_the_adjustment_history_exports_with_what_each_grade_was_before(): void
    {
        $course = $this->course('intro-php');
        $alice = $this->enroll($course, 'Alice');
        $grade = $this->grade($course, $this->quiz($course, 'Midterm'), $alice, 6, 10);

        $this->actingAs($course->instructor)
            ->putJson("/api/instructor/courses/{$course->slug}/gradebook/grades/{$grade->id}", ['score' => 8, 'note' => 'Appeal upheld.'])
            ->assertOk();

        $csv = $this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/adjustments/export?format=csv")
            ->assertOk()
            ->streamedContent();

        $rows = $this->parse($csv);

        $this->assertSame(
            ['When', 'Student', 'Assessment', 'Change', 'Score before', 'Score after', 'Counted', 'Note', 'By'],
            $rows[0]
        );
        $this->assertSame('Alice', $rows[1][1]);
        $this->assertSame('Midterm', $rows[1][2]);
        $this->assertSame('Score changed from 6 of 10 (60%) to 8 of 10 (80%)', $rows[1][3]);
        $this->assertSame('6 of 10 (60%)', $rows[1][4]);
        $this->assertSame('8 of 10 (80%)', $rows[1][5]);
        $this->assertSame('yes', $rows[1][6]);
        $this->assertSame('Appeal upheld.', $rows[1][7]);
        $this->assertSame($course->instructor->name, $rows[1][8]);
    }

    public function test_the_history_export_covers_more_than_the_page_shows(): void
    {
        $course = $this->course('intro-php');
        $alice = $this->enroll($course, 'Alice');
        $grade = $this->grade($course, $this->quiz($course, 'Midterm'), $alice, 6, 10);

        // 51 decisions, one past the page's limit, to catch an export that silently
        // inherited the page's cap.
        foreach (range(1, 51) as $attempt) {
            $this->actingAs($course->instructor)
                ->putJson("/api/instructor/courses/{$course->slug}/gradebook/grades/{$grade->id}", ['score' => $attempt % 10])
                ->assertOk();
        }

        $rows = $this->parse($this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/adjustments/export?format=csv")
            ->assertOk()
            ->streamedContent());

        $this->assertCount(52, $rows);
    }

    public function test_the_export_offers_two_formats_and_nothing_else(): void
    {
        $course = $this->course('intro-php');

        $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook/export?format=pdf")
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');

        $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook/export")
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    public function test_the_export_is_scoped_to_the_course_owner(): void
    {
        $course = $this->course('intro-php');
        $student = $this->enroll($course, 'Alice');

        // Checked before signing anyone in, because `actingAs` holds for the rest
        // of the test and would turn this into a second student request.
        foreach (['/gradebook/export', '/gradebook/adjustments/export'] as $path) {
            $this->getJson("/api/instructor/courses/{$course->slug}{$path}?format=csv")
                ->assertUnauthorized();
        }

        $other = User::factory()->instructor()->create();

        foreach (['/gradebook/export', '/gradebook/adjustments/export'] as $path) {
            $url = "/api/instructor/courses/{$course->slug}{$path}?format=csv";

            $this->actingAs($other)->getJson($url)->assertForbidden();
            $this->actingAs($student)->getJson($url)->assertForbidden();
        }

        // The owner gets the file, so the routes are not simply closed.
        $this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/export?format=csv")
            ->assertOk();
    }

    public function test_the_export_is_named_for_the_course_and_the_day(): void
    {
        $course = $this->course('intro-php');

        $csv = $this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/export?format=csv")
            ->assertOk();

        $xlsx = $this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/export?format=xlsx")
            ->assertOk();

        $this->assertStringContainsString('gradebook-intro-php-'.now()->format('Y-m-d').'.csv', (string) $csv->headers->get('content-disposition'));
        $this->assertStringContainsString('gradebook-intro-php-'.now()->format('Y-m-d').'.xlsx', (string) $xlsx->headers->get('content-disposition'));
    }

    // ---------------------------------------------------------------- helpers

    private function course(string $slug): Course
    {
        return Course::factory()->create(['slug' => $slug]);
    }

    private function enroll(Course $course, string $name): User
    {
        $student = User::factory()->student()->create(['name' => $name]);
        Enrollment::factory()->create(['course_id' => $course->id, 'student_id' => $student->id]);

        return $student;
    }

    private function quiz(Course $course, string $title, ?GradebookCategory $category = null): Quiz
    {
        return Quiz::factory()->create([
            'course_id' => $course->id,
            'title' => $title,
            'category_id' => $category?->id,
        ]);
    }

    private function grade(Course $course, Quiz $quiz, User $student, float $score, float $maxScore): Grade
    {
        $percentage = round(($score / $maxScore) * 100, 2);

        $attempt = QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => $score,
            'score_percentage' => $percentage,
        ]);

        return Grade::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'source_type' => QuizAttempt::class,
            'source_id' => $attempt->id,
            'type' => 'quiz',
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'graded_at' => now(),
        ]);
    }

    private function csv(Course $course): string
    {
        return $this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/export?format=csv")
            ->assertOk()
            ->streamedContent();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parse(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);

        $rows = array_map(
            fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            array_filter(explode("\n", str_replace("\r\n", "\n", $csv)), fn (string $line): bool => trim($line) !== '')
        );

        return array_map(fn (array $row): array => array_map(fn (?string $value): string => (string) $value, $row), $rows);
    }

    /**
     * Grid columns keyed by their short label, so a test can ask for `Midterm`
     * whether the header reads `Midterm` or `Uncategorized · Midterm`.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array<string, int>
     */
    private function columns(array $rows): array
    {
        return collect($rows[3])->mapWithKeys(fn (string $label, int $index): array => [Str::afterLast($label, ' · ') => $index])->all();
    }

    /**
     * One student's row keyed by column, with the excluded cells left as null
     * rather than the empty string the file holds.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, int>  $columns
     * @return array<string, string|null>
     */
    private function row(array $rows, string $student, array $columns): array
    {
        $row = collect($rows)->firstWhere(0, $student);

        return array_map(
            fn (int $index): ?string => $row[$index] === '' ? null : $row[$index],
            $columns
        );
    }

    private function sheet(Course $course, int $number = 1): string
    {
        return $this->part($course, "xl/worksheets/sheet{$number}.xml");
    }

    private function part(Course $course, string $name): string
    {
        $contents = $this->actingAs($course->instructor)
            ->get("/api/instructor/courses/{$course->slug}/gradebook/export?format=xlsx")
            ->assertOk()
            ->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'xlsx-');
        file_put_contents($path, $contents);

        $zip = new ZipArchive;
        $zip->open($path);
        $part = (string) $zip->getFromName($name);
        $zip->close();
        unlink($path);

        return $part;
    }
}
