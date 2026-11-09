<?php

use App\Enums\RoleName;
use App\Http\Controllers\Api\V1\Admin\AcademicTermController;
use App\Http\Controllers\Api\V1\Admin\AdminProfileRecordsController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CareerController;
use App\Http\Controllers\Api\V1\Admin\CourseOfferingController;
use App\Http\Controllers\Api\V1\Admin\CourseOfferingImportController;
use App\Http\Controllers\Api\V1\Admin\RoomImportController;
use App\Http\Controllers\Api\V1\Admin\StudentController;
use App\Http\Controllers\Api\V1\Admin\StudentImportController;
use App\Http\Controllers\Api\V1\Admin\SubjectController;
use App\Http\Controllers\Api\V1\Admin\SubjectImportController;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\MobileAuthController;
use App\Http\Controllers\Api\V1\Auth\MobileDeviceController;
use App\Http\Controllers\Api\V1\Collaborators\ExamCollaboratorController;
use App\Http\Controllers\Api\V1\Eligibility\ExamEligibilityController;
use App\Http\Controllers\Api\V1\Enrollments\StudentEnrollmentController;
use App\Http\Controllers\Api\V1\Entries\EntryAnnulmentController;
use App\Http\Controllers\Api\V1\Entries\EntryControlController;
use App\Http\Controllers\Api\V1\Entries\OfflineExamController;
use App\Http\Controllers\Api\V1\Entries\QrEntryController;
use App\Http\Controllers\Api\V1\Exams\ExamManagementController;
use App\Http\Controllers\Api\V1\Exams\ExamSchedulingController;
use App\Http\Controllers\Api\V1\Expulsions\ExpulsionController;
use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use App\Http\Controllers\Api\V1\Incidents\ExamIncidentReportController;
use App\Http\Controllers\Api\V1\Incidents\IncidentController;
use App\Http\Controllers\Api\V1\Incidents\IncidentReportController;
use App\Http\Controllers\Api\V1\Infractions\InfractionController;
use App\Http\Controllers\Api\V1\Portal\PortalCalendarController;
use App\Http\Controllers\Api\V1\Portal\PortalHistoryController;
use App\Http\Controllers\Api\V1\Portal\PortalInformationController;
use App\Http\Controllers\Api\V1\Portal\PortalNewsController;
use App\Http\Controllers\Api\V1\Portal\PortalServiceController;
use App\Http\Controllers\Api\V1\Reports\ExamReportController;
use App\Http\Controllers\Api\V1\Rooms\RoomController;
use App\Http\Controllers\Api\V1\Students\HistoricalPhotoController;
use App\Http\Controllers\Api\V1\Students\ProfilePhotoController;
use App\Http\Controllers\Api\V1\Students\StudentProfileController;
use App\Http\Controllers\Api\V1\Students\StudentQrController;
use App\Http\Controllers\Api\V1\TeacherDashboard\TeacherDashboardController;
use App\Http\Middleware\EnsureActiveAcademicTermAdministrator;
use App\Http\Middleware\EnsureActiveAdministrator;
use App\Http\Middleware\EnsureActiveCourseOfferingAdministrator;
use App\Http\Middleware\EnsureActiveRoomAdministrator;
use App\Http\Middleware\EnsureActiveSubjectAdministrator;
use App\Http\Middleware\EnsureAttendanceExamOwner;
use App\Http\Middleware\EnsureCourseOfferingAccess;
use App\Http\Middleware\EnsureExpulsionExamOwner;
use App\Http\Middleware\EnsureInfractionExamOwner;
use App\Http\Middleware\HandlePortalServiceErrors;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

require __DIR__.'/eligibility-automation.php';

Route::prefix('admin/students/{student}')->whereNumber('student')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureActiveAdministrator::class])
    ->group(function () {
        $photo = HistoricalPhotoController::class;
        Route::get('/profile-photo', [ProfilePhotoController::class, 'show'])->name('students.profile-photo.admin');
        Route::get('/historical-photo', [$photo, 'show'])->name('students.historical-photo.show');
        Route::post('/historical-photo', [$photo, 'store'])->name('students.historical-photo.store');
        Route::post('/historical-photo/corrections', [$photo, 'store'])->name('students.historical-photo.correct');
    });

// HU38/HU39: independent public calendar; never derives events from private exams.
Route::prefix('portal')->name('portal.public.')->group(function () {
    $services = PortalServiceController::class;
    Route::get('/services', [$services, 'index'])->name('services.index');
    Route::get('/services/{service}', [$services, 'show'])->whereNumber('service')->name('services.show');
    Route::get('/information', [PortalInformationController::class, 'publicContent'])->name('information');
    $news = PortalNewsController::class;
    Route::get('/news', [$news, 'index'])->name('news.index');
    Route::get('/news/{news}', [$news, 'show'])->whereNumber('news')->name('news.show');
    Route::get('/news/{news}/image', [$news, 'image'])->whereNumber('news')->name('news.image');
    Route::get('/event-categories', [PortalCalendarController::class, 'categories'])->name('event-categories');
    Route::get('/events', [PortalCalendarController::class, 'index'])->name('events.index');
    Route::get('/events/{event}', [PortalCalendarController::class, 'show'])->whereNumber('event')->name('events.show');
});

Route::prefix('admin/portal')->name('portal.admin.')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureActiveAdministrator::class])
    ->group(function () {
        $controller = PortalCalendarController::class;
        $services = PortalServiceController::class;
        Route::get('/services', [$services, 'index'])->name('services.index');
        Route::post('/services', [$services, 'store'])->name('services.store');
        Route::get('/services/{service}', [$services, 'show'])->whereNumber('service')->name('services.show');
        Route::get('/services/{service}/history', [PortalHistoryController::class, 'services'])->whereNumber('service')->name('services.history')->middleware(HandlePortalServiceErrors::class);
        Route::patch('/services/{service}', [$services, 'update'])->whereNumber('service')->name('services.update');
        Route::patch('/services/{service}/status', [$services, 'status'])->whereNumber('service')->name('services.status');
        Route::prefix('information')->name('information.')->group(function () {
            $information = PortalInformationController::class;
            foreach (['sections', 'faqs'] as $type) {
                Route::get('/'.$type, [$information, 'index'])->name($type.'.index');
                Route::get('/'.$type.'/{content}', [$information, 'show'])->whereNumber('content')->name($type.'.show');
                Route::get('/'.$type.'/{content}/history', [PortalHistoryController::class, 'information'])->whereNumber('content')->name($type.'.history');
                Route::patch('/'.$type.'/{content}', [$information, 'update'])->whereNumber('content')->name($type.'.update');
                Route::post('/'.$type.'/{content}/publish', [$information, 'publish'])->whereNumber('content')->name($type.'.publish');
                Route::post('/'.$type.'/{content}/unpublish', [$information, 'unpublish'])->whereNumber('content')->name($type.'.unpublish');
            }
            Route::post('/faqs', [$information, 'store'])->name('faqs.store');
        });
        $news = PortalNewsController::class;
        Route::get('/news', [$news, 'index'])->name('news.index');
        Route::post('/news', [$news, 'store'])->name('news.store');
        Route::get('/news/{news}', [$news, 'show'])->whereNumber('news')->name('news.show');
        Route::get('/news/{news}/history', [PortalHistoryController::class, 'news'])->whereNumber('news')->name('news.history');
        Route::patch('/news/{news}', [$news, 'update'])->whereNumber('news')->name('news.update');
        Route::patch('/news/{news}/status', [$news, 'status'])->whereNumber('news')->name('news.status');
        Route::get('/news/{news}/image', [$news, 'image'])->whereNumber('news')->name('news.image');
        Route::post('/news/{news}/image', [$news, 'changeImage'])->whereNumber('news')->name('news.image.store');
        Route::delete('/news/{news}/image', [$news, 'changeImage'])->whereNumber('news')->name('news.image.destroy');
        Route::get('/event-categories', [$controller, 'categories'])->name('event-categories');
        Route::get('/events', [$controller, 'index'])->name('events.index');
        Route::post('/events', [$controller, 'store'])->name('events.store');
        Route::get('/events/{event}', [$controller, 'show'])->whereNumber('event')->name('events.show');
        Route::get('/events/{event}/history', [PortalHistoryController::class, 'events'])->whereNumber('event')->name('events.history');
        Route::patch('/events/{event}', [$controller, 'update'])->whereNumber('event')->name('events.update');
        Route::patch('/events/{event}/status', [$controller, 'status'])->whereNumber('event')->name('events.status');
    });

Route::prefix('admin')->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureActiveAdministrator::class])
    ->group(function () {
        Route::get('/incidents/exams', [ExamIncidentReportController::class, 'exams']);
        Route::get('/users/history', [AdminUserController::class, 'history']);
        Route::get('/profiles/{profileType}/{profileId}/records', [AdminProfileRecordsController::class, 'index'])
            ->where('profileType', 'students|teachers')->whereNumber('profileId');
        Route::get('/roles', [AdminUserController::class, 'roles']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->whereNumber('user');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'status'])->whereNumber('user');
        Route::get('/users/{user}/history', [AdminUserController::class, 'history'])->whereNumber('user');
        Route::get('/users/{user}/roles', [AdminUserController::class, 'roles'])->whereNumber('user');
        Route::post('/users/{user}/roles', [AdminUserController::class, 'assign'])->whereNumber('user');
        Route::delete('/users/{user}/roles/{role}', [AdminUserController::class, 'revoke'])->whereNumber('user')->whereNumber('role');
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->whereNumber('auditLog');
        Route::get('/careers', [CareerController::class, 'index']);
        Route::get('/careers/{career}', [CareerController::class, 'show'])->whereNumber('career');
    });

Route::prefix('exams/{exam}/eligibilities')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', 'teacher.role'])
    ->group(function () {
        Route::get('/', [ExamEligibilityController::class, 'index']);
        Route::get('/reasons', [ExamEligibilityController::class, 'reasons']);
        Route::get('/{student}/profile-photo', [ExamEligibilityController::class, 'photo'])->whereNumber('student')->name('eligibilities.profile-photo');
        Route::post('/bulk', [ExamEligibilityController::class, 'bulk']);
        Route::patch('/{student}', [ExamEligibilityController::class, 'update']);
    });

Route::prefix('admin/course-offerings')
    ->middleware(['auth:sanctum', EnsureActiveCourseOfferingAdministrator::class, 'session.current', 'password.changed'])
    ->group(function () {
        Route::post('/bulk', [CourseOfferingController::class, 'bulk']);
        Route::post('/import/preview', [CourseOfferingImportController::class, 'preview']);
        Route::post('/import/confirm', [CourseOfferingImportController::class, 'confirm']);
        Route::put('/{courseOffering}', [CourseOfferingController::class, 'update'])->where('courseOffering', '[1-9][0-9]*');
        Route::patch('/{courseOffering}/status', [CourseOfferingController::class, 'updateStatus'])->where('courseOffering', '[1-9][0-9]*');
        Route::get('/', [CourseOfferingController::class, 'index']);
        Route::get('/{courseOffering}', [CourseOfferingController::class, 'show'])->where('courseOffering', '[1-9][0-9]*');
    });

Route::prefix('admin/academic-terms')
    ->middleware(['auth:sanctum', EnsureActiveAcademicTermAdministrator::class, 'session.current', 'password.changed'])
    ->group(function () {
        Route::get('/active', [AcademicTermController::class, 'active']);
        Route::post('/', [AcademicTermController::class, 'store']);
        Route::put('/{academicTerm}', [AcademicTermController::class, 'update'])->where('academicTerm', '[1-9][0-9]*');
        Route::patch('/{academicTerm}/status', [AcademicTermController::class, 'updateStatus'])->where('academicTerm', '[1-9][0-9]*');
        Route::get('/', [AcademicTermController::class, 'index']);
        Route::get('/{academicTerm}', [AcademicTermController::class, 'show'])->where('academicTerm', '[1-9][0-9]*');
    });

Route::prefix('admin/subjects')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureActiveSubjectAdministrator::class])
    ->group(function () {
        Route::get('/careers', [SubjectController::class, 'careers']);
        Route::post('/import/preview', [SubjectImportController::class, 'preview']);
        Route::post('/import/confirm', [SubjectImportController::class, 'confirm']);
        Route::post('/', [SubjectController::class, 'store']);
        Route::put('/{subject}', [SubjectController::class, 'update'])->whereNumber('subject');
        Route::patch('/{subject}/status', [SubjectController::class, 'updateStatus'])->whereNumber('subject');
        Route::get('/', [SubjectController::class, 'index']);
        Route::get('/{subject}', [SubjectController::class, 'show'])->whereNumber('subject');
    });

Route::prefix('admin/rooms')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureActiveRoomAdministrator::class])
    ->group(function () {
        Route::post('/import/preview', [RoomImportController::class, 'preview']);
        Route::post('/import/confirm', [RoomImportController::class, 'confirm']);
        Route::get('/', [App\Http\Controllers\Api\V1\Admin\RoomController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\V1\Admin\RoomController::class, 'store']);
        Route::put('/{room}', [App\Http\Controllers\Api\V1\Admin\RoomController::class, 'update'])->whereNumber('room');
        Route::patch('/{room}/status', [App\Http\Controllers\Api\V1\Admin\RoomController::class, 'updateStatus'])->whereNumber('room');
        Route::get('/{room}', [App\Http\Controllers\Api\V1\Admin\RoomController::class, 'show'])->whereNumber('room');
    });

Route::middleware(['auth:sanctum', 'session.current'])->group(function () {
    Route::get('/rooms', [RoomController::class, 'index']);
});

// HU35-HU37: sync checks historical package ownership itself so revocations become recorded conflicts.
Route::prefix('exams/{exam_id}/offline')->whereNumber('exam_id')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', 'throttle:60,1'])
    ->controller(OfflineExamController::class)
    ->group(function () {
        Route::post('/prepare', 'prepare');
        Route::get('/assignments', 'assignments');
        Route::get('/package', 'package');
        Route::post('/sync', 'sync');
    });

// Health Check
Route::get('health', HealthCheckController::class)->name('health');

// Autenticación genérica
Route::post('auth/mobile/login', [MobileAuthController::class, 'login'])
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware('throttle:mobile-login')->name('auth.mobile.login');

Route::prefix('auth')->middleware(['auth:sanctum', 'session.current'])->group(function () {
    Route::post('/logout', [AuthController::class, 'revokeSessionToken']);
    Route::get('/devices', [MobileDeviceController::class, 'index']);
    Route::delete('/devices/{id}', [MobileDeviceController::class, 'destroy'])->whereNumber('id');
});

// HU02 Auth: devuelve usuario, roles y estado de primer acceso
Route::middleware(['auth:sanctum', 'session.current'])->group(function () {
    Route::get('me', CurrentUserController::class)->name('me');
});

// Rutas de Estudiantes (HU-10 Perfil, HU-11 Exámenes/QR)
Route::middleware([
    'auth:sanctum',
    'session.current',
    'password.changed',
    'role:'.RoleName::ESTUDIANTE->value,
])->group(function () {
    // Rutas de Perfil del Estudiante (HU-10)
    Route::get('/students/profile', [StudentProfileController::class, 'show']);
    Route::get('/students/profile/photo', [ProfilePhotoController::class, 'own'])->name('students.profile-photo');
    Route::post('/students/profile/photo', [StudentProfileController::class, 'updatePhoto']);

    // Rutas de Exámenes y QR del Estudiante (HU-11)
    Route::get('/students/exams', [StudentQrController::class, 'index']);
    Route::get('/students/exams/{exam_id}/qr', [StudentQrController::class, 'show']);

});

// HU 01: Acceso Administrativo protegido estrictamente
Route::middleware(['auth:sanctum', 'session.current', 'password.changed', 'verify.admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/test', function () {
            return response()->json([
                'success' => true,
                'message' => 'Acceso administrativo autorizado.',
            ]);
        });
        Route::post(
            '/students/import/preview',
            [StudentImportController::class, 'preview']
        );

        Route::post(
            '/students/import/confirm',
            [StudentImportController::class, 'confirm']
        );
        Route::get('students', [StudentController::class, 'index'])->name('students.index');
        Route::post('students', [StudentController::class, 'store'])->name('students.store');
        Route::get('students/{student}', [StudentController::class, 'show'])->name('students.show');
        Route::patch('students/{student}', [StudentController::class, 'update'])->name('students.update');
        Route::patch('students/{student}/status', [StudentController::class, 'updateStatus'])->name('students.status');
        Route::get('teachers', [TeacherController::class, 'index'])->name('teachers.index');
        Route::post('teachers', [TeacherController::class, 'store'])->name('teachers.store');
        Route::get('teachers/{teacher}', [TeacherController::class, 'show'])->name('teachers.show');
        Route::put('teachers/{teacher}', [TeacherController::class, 'update'])->name('teachers.update');
        Route::patch('teachers/{teacher}/status', [TeacherController::class, 'updateStatus'])->name('teachers.status');
    });

Route::prefix('teacher/exams')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', 'teacher.role'])
    ->group(function () {
        Route::get('/', [ExamManagementController::class, 'index']);
        Route::get('/{exam}', [ExamManagementController::class, 'show'])->where('exam', '[1-9][0-9]*');
        Route::patch('/{exam}', [ExamManagementController::class, 'update'])->where('exam', '[1-9][0-9]*');
        Route::post('/{exam}/cancel', [ExamManagementController::class, 'cancel'])->where('exam', '[1-9][0-9]*');
    });

// HU 06: Seguridad de rutas del panel docente
Route::prefix('teacher/dashboard')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', 'throttle:60,1', 'teacher.role', 'block.mutations', 'audit.logger'])
    ->group(function () {
        Route::get('/subjects', [TeacherDashboardController::class, 'getAssignedSubjects']);
        Route::get('/upcoming-exams', [TeacherDashboardController::class, 'getUpcomingExams']);
    });

// HU 07 y HU 08: Bloquear a usuarios sin permisos (RBAC) para inscripciones y exámenes
Route::prefix('course-offerings/{courseOffering}')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', 'teacher.role', EnsureCourseOfferingAccess::class])
    ->group(function () {
        Route::get('/enrollments', [StudentEnrollmentController::class, 'index']);
        Route::post('/enrollments/manual', [StudentEnrollmentController::class, 'storeManual']);
        Route::post('/enrollments/bulk', [StudentEnrollmentController::class, 'storeBulk']);
        Route::post('/enrollments/bulk/preview', [StudentEnrollmentController::class, 'previewBulk']);
        Route::post('/exams', [ExamSchedulingController::class, 'store']);
    });

// HU15: Solo el docente responsable gestiona colaboradores.
Route::prefix('exams/{exam_id}/collaborators')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', 'teacher.role'])
    ->group(function () {
        Route::get('/', [ExamCollaboratorController::class, 'index']);
        Route::get('/{user_id}/profile-photo', [ExamCollaboratorController::class, 'photo'])->whereNumber('user_id')->name('collaborators.profile-photo');
        Route::post('/', [ExamCollaboratorController::class, 'store']);
        Route::delete('/{user_id}', [ExamCollaboratorController::class, 'destroy']);
    });

Route::middleware(['auth:sanctum', 'session.current', 'password.changed'])->group(function () {
    Route::get('/me/collaborations', [ExamCollaboratorController::class, 'myCollaborations']);
    Route::get('/users', [ExamCollaboratorController::class, 'users'])->middleware('teacher.role');
});

Route::prefix('exams/{exam_id}/attendance')
    ->where(['exam_id' => '[1-9][0-9]*'])
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureAttendanceExamOwner::class])
    ->group(function () {
        $controller = AttendanceController::class;
        Route::get('/summary', [$controller, 'summary']);
        Route::get('/students', [$controller, 'students']);
        Route::get('/collaborators', [$controller, 'collaborators']);
    });

Route::get('/exam-reports/catalog', [ExamReportController::class, 'catalog'])
    ->middleware(['auth:sanctum', 'session.current', 'password.changed'])->name('exam-reports.catalog');

Route::prefix('exams/{exam_id}/reports')->where(['exam_id' => '[1-9][0-9]*'])
    ->middleware(['auth:sanctum', 'session.current', 'password.changed'])
    ->group(function () {
        $controller = ExamReportController::class;
        Route::get('/{type}/export', [$controller, 'export'])->name('exam-reports.export');
        Route::get('/{type}', [$controller, 'show'])->name('exam-reports.show');
    });

Route::prefix('exams/{exam_id}/expulsions')
    ->where(['exam_id' => '[1-9][0-9]*'])
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureExpulsionExamOwner::class])
    ->group(function () {
        Route::post('/', [ExpulsionController::class, 'store']);
        Route::get('/', [ExpulsionController::class, 'index']);
        Route::get('/students', [ExpulsionController::class, 'students']);
        Route::get('/eligible-infractions', [ExpulsionController::class, 'eligibleInfractions']);
        Route::get('/students/{student_id}/history', [ExpulsionController::class, 'studentHistory'])
            ->where('student_id', '[1-9][0-9]*');
        Route::get('/{expulsion_id}', [ExpulsionController::class, 'show'])
            ->where('expulsion_id', '[1-9][0-9]*');
    });

Route::prefix('exams/{exam_id}/infractions')
    ->where(['exam_id' => '[1-9][0-9]*'])
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureInfractionExamOwner::class])
    ->group(function () {
        $controller = InfractionController::class;
        Route::get('/options', [$controller, 'options']);
        Route::get('/students', [$controller, 'students']);
        Route::get('/students/{student_id}/history', [$controller, 'studentHistory'])->where('student_id', '[1-9][0-9]*');
        Route::get('/', [$controller, 'index']);
        Route::post('/', [$controller, 'store']);
        Route::get('/{infraction_id}', [$controller, 'show'])->where('infraction_id', '[1-9][0-9]*');
        Route::get('/{infraction_id}/history', [$controller, 'history'])->where('infraction_id', '[1-9][0-9]*');
        Route::patch('/{infraction_id}', [$controller, 'update'])->where('infraction_id', '[1-9][0-9]*');
        Route::patch('/{infraction_id}/status', [$controller, 'status'])->where('infraction_id', '[1-9][0-9]*')->name('infractions.status');
    });

Route::get('exams/{exam_id}/students/{student_id}/expulsions', [ExpulsionController::class, 'contractStudentHistory'])
    ->where(['exam_id' => '[1-9][0-9]*', 'student_id' => '[1-9][0-9]*'])
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureExpulsionExamOwner::class]);

Route::get('exams/{exam_id}/students/{student_id}/infractions', [InfractionController::class, 'contractStudentHistory'])
    ->where(['exam_id' => '[1-9][0-9]*', 'student_id' => '[1-9][0-9]*'])
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureInfractionExamOwner::class]);

Route::prefix('incident-reports')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed'])
    ->group(function () {
        $controller = IncidentReportController::class;
        Route::get('/', [$controller, 'index']);
        Route::get('/summary', [$controller, 'summary']);
        Route::get('/export', [$controller, 'export']);
        Route::get('/{incident_id}', [$controller, 'show'])->where('incident_id', '(?:qr-)?[1-9][0-9]*');
    });

Route::prefix('exams/{exam_id}/incidents')
    ->where(['exam_id' => '[1-9][0-9]*'])
    ->middleware(['auth:sanctum', 'session.current', 'password.changed'])
    ->group(function () {
        Route::post('/', [IncidentController::class, 'store']);
        Route::get('/', [IncidentController::class, 'index']);
        Route::get('/export', [ExamIncidentReportController::class, 'export']);
        Route::get('/{incident_id}', [IncidentController::class, 'show'])
            ->where('incident_id', '(?:qr-)?[1-9][0-9]*');
    });

Route::prefix('exams/{exam_id}/entry-control')
    ->whereNumber('exam_id')
    ->middleware(['auth:sanctum', 'session.current', 'password.changed', 'verify.exam.access'])
    ->group(function () {
        Route::get('/students', [EntryControlController::class, 'index']);
        Route::get('/students/{student_id}/historical-photo', [HistoricalPhotoController::class, 'entry'])
            ->whereNumber('student_id')->name('entry-control.historical-photo');
        Route::get('/students/{student_id}/profile-photo', [ProfilePhotoController::class, 'entry'])
            ->whereNumber('student_id')->name('entry-control.profile-photo');
        Route::post('/qr', [QrEntryController::class, 'store']);
        Route::post('/students/{student_id}/entries', [QrEntryController::class, 'storeManual'])
            ->whereNumber('student_id');
        Route::get('/entries', [QrEntryController::class, 'index']);
        Route::post('/entries/{entry_id}/annulment', [EntryAnnulmentController::class, 'store'])
            ->where('entry_id', '[1-9][0-9]*')->name('entries.annulment');
        Route::post('/entries/{entry_id}/annul', [EntryAnnulmentController::class, 'store'])
            ->where('entry_id', '[1-9][0-9]*');
        Route::get('/students/{student_id}', [EntryControlController::class, 'show'])
            ->whereNumber('student_id');
    });
