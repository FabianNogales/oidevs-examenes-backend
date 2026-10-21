<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\Enrollments\StudentEnrollmentController;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use App\Services\CourseOfferings\CourseOfferingAccess;
use App\Services\Eligibility\ExamEligibilityInitializer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCourseOfferingAccess
{
    public function __construct(
        private readonly CourseOfferingAccess $access,
        private readonly AcademicTermIntegrity $integrity,
        private readonly ExamEligibilityInitializer $eligibilities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->route('courseOffering');
        if (! preg_match('/\A[1-9][0-9]*\z/', $id) || strlen($id) > strlen((string) PHP_INT_MAX)
            || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) {
            abort(404);
        }

        $operation = fn () => $this->access->run((int) $id, $request->user()->id, ! $request->isMethodSafe(), fn () => $next($request));
        if (in_array($request->route()->getActionName(), [
            StudentEnrollmentController::class.'@storeManual',
            StudentEnrollmentController::class.'@storeBulk',
        ], true)) {
            return $this->integrity->transaction(function () use ($id, $operation) {
                // Reuse the existing coordinator, but lock exams before users (HU14 order).
                $this->eligibilities->lockExamsBeforeEnrollment((int) $id);

                return $operation();
            });
        }

        return $operation();
    }
}
