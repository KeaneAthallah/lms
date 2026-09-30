<?php

namespace App\Http\Controllers;

use App\Exports\CsvSpreadsheet;
use App\Exports\GradebookExport;
use App\Exports\Spreadsheet;
use App\Exports\XlsxSpreadsheet;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstructorGradebookExportController extends Controller
{
    /**
     * The gradebook grid as a spreadsheet.
     */
    public function grid(Request $request, Course $course, GradebookExport $export): StreamedResponse
    {
        $this->authorize('manage', $course);

        $spreadsheet = $this->spreadsheet($request, $course, 'gradebook');

        foreach ($export->grid($course) as $sheet) {
            $spreadsheet->addSheet($sheet['name'], $sheet['rows']);
        }

        return $this->download($spreadsheet);
    }

    /**
     * The course's trail of grade decisions, which is the whole record rather than
     * the recent page of it.
     */
    public function adjustments(Request $request, Course $course, GradebookExport $export): StreamedResponse
    {
        $this->authorize('manage', $course);

        $spreadsheet = $this->spreadsheet($request, $course, 'grade-adjustments');

        foreach ($export->adjustments($course) as $sheet) {
            $spreadsheet->addSheet($sheet['name'], $sheet['rows']);
        }

        return $this->download($spreadsheet);
    }

    private function spreadsheet(Request $request, Course $course, string $stem): Spreadsheet
    {
        $format = $request->validate([
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
        ])['format'];

        $name = $stem.'-'.$course->slug.'-'.now()->format('Y-m-d');

        return $format === 'csv' ? new CsvSpreadsheet($name) : new XlsxSpreadsheet($name);
    }

    private function download(Spreadsheet $spreadsheet): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            echo $spreadsheet->output();
        }, $spreadsheet->filename(), [
            'Content-Type' => $spreadsheet->contentType(),
        ]);
    }
}
