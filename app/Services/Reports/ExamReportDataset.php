<?php

namespace App\Services\Reports;

use App\Enums\ExamReportType;
use App\Models\Exam;
use App\Models\Student;
use App\Models\User;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Incidents\IncidentDataset;
use App\Services\Incidents\IncidentIdentity;
use App\Services\Incidents\IncidentVocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExamReportDataset
{
    public function __construct(private readonly IncidentDataset $incidents) {}

    public function participants(Exam $exam): int
    {
        return DB::table('enrollments')->where('course_offering_id', $exam->course_offering_id)->where('status', 'ACTIVE')->count();
    }

    public function classification(Exam $exam): string
    {
        if ($exam->status === 'CANCELLED') {
            return 'NOT_APPLICABLE';
        }
        if (in_array($exam->status, ['FINISHED', 'COMPLETED'], true)) {
            return 'ABSENT';
        }
        if (! in_array($exam->status, ExamEntryAvailability::STATES, true)) {
            return 'NOT_APPLICABLE';
        }

        return CarbonImmutable::now(ExamEntryAvailability::TIMEZONE)->greaterThanOrEqualTo(ExamEntryAvailability::endsAt($exam)) ? 'ABSENT' : 'PENDING';
    }

    private function dates(Builder $query, string $column, array $filters): Builder
    {
        if ($filters['date_from'] !== null) {
            $query->where($column, '>=', CarbonImmutable::parse($filters['date_from'], 'America/La_Paz')
                ->startOfDay()->setTimezone(config('app.timezone'))->toDateTimeString());
        }
        if ($filters['date_to'] !== null) {
            $query->where($column, '<', CarbonImmutable::parse($filters['date_to'], 'America/La_Paz')
                ->startOfDay()->addDay()->setTimezone(config('app.timezone'))->toDateTimeString());
        }

        return $query;
    }

    public function rows(Exam $exam, ExamReportType $type, array $filters, ?string $classification): array
    {
        $records = collect();
        if (in_array($type, [ExamReportType::ENROLLED, ExamReportType::PENDING_ABSENT], true)) {
            $day = substr($exam->exam_date, 0, 10);
            if (($filters['date_from'] !== null && $day < $filters['date_from'])
                || ($filters['date_to'] !== null && $day > $filters['date_to']) || $classification === 'NOT_APPLICABLE') {
                return [];
            }
            $query = DB::table('enrollments as en')->where('en.course_offering_id', $exam->course_offering_id)->where('en.status', 'ACTIVE');
            if ($type === ExamReportType::PENDING_ABSENT) {
                $query->whereNotExists(fn ($entry) => $entry->selectRaw('1')->from('exam_entries')
                    ->whereColumn('student_id', 'en.student_id')->where('exam_id', $exam->id)->where('status', 'VALID'));
            }
            $records = $query->select('en.id as source_id', 'en.student_id')->orderBy('en.id')->get()
                ->map(fn ($row) => (object) ((array) $row + ['record_type' => $type === ExamReportType::ENROLLED ? 'ENROLLMENT' : $classification,
                    'state' => $type === ExamReportType::ENROLLED ? 'ENROLLED' : $classification]));
        } elseif ($type === ExamReportType::ATTENDANCE) {
            $query = DB::table('exam_entries as e')->where('e.exam_id', $exam->id)->where('e.status', 'VALID')
                ->whereExists(fn ($en) => $en->selectRaw('1')->from('enrollments')
                    ->whereColumn('enrollments.student_id', 'e.student_id')->where('course_offering_id', $exam->course_offering_id)->where('status', 'ACTIVE'));
            $records = $this->dates($query, 'e.entered_at', $filters)
                ->select('e.id as source_id', 'e.student_id', 'e.entered_at as occurred_at', 'e.verified_by as actor_id', 'e.verification_method as method')
                ->orderByDesc('e.entered_at')->orderByDesc('e.id')->get()
                ->map(fn ($row) => (object) ((array) $row + ['record_type' => 'ENTRY', 'state' => 'VALID']));
        } elseif ($type === ExamReportType::INCIDENTS) {
            $records = $this->dates($this->incidents->query($exam->id), 'incidents.created_at', $filters)
                ->orderByDesc('created_at')->orderBy('origin')->orderByDesc('source_id')->get()->map(function ($row) {
                    return (object) ['source_id' => $row->origin === 'AUTOMATIC_QR' ? IncidentIdentity::automatic((int) $row->source_id) : (int) $row->source_id,
                        'student_id' => $row->student_id, 'record_type' => 'INCIDENT', 'state' => $row->status,
                        'category' => IncidentVocabulary::type($row->type), 'description' => $row->description,
                        'origin' => $row->origin === 'AUTOMATIC_QR' ? 'AUTOMATIC' : 'MANUAL',
                        'occurred_at' => $row->created_at, 'actor_id' => $row->reported_by];
                });
        } else {
            $infractions = $this->dates(DB::table('infractions')->where('exam_id', $exam->id), 'occurred_at', $filters)
                ->select('id as source_id', 'student_id', 'type as category', 'status as state', 'description', 'occurred_at', 'reported_by as actor_id')
                ->get()->map(fn ($row) => (object) ((array) $row + ['record_type' => 'INFRACTION']));
            $expulsions = $this->dates(DB::table('expulsions')->where('exam_id', $exam->id), 'expelled_at', $filters)
                ->select('id as source_id', 'student_id', 'reason as description', 'expelled_at as occurred_at', 'expelled_by as actor_id', 'infraction_id as related_infraction_id')
                ->get()->map(fn ($row) => (object) ((array) $row + ['record_type' => 'EXPULSION', 'state' => 'EXPELLED']));
            $records = $infractions->concat($expulsions)->sortBy(fn ($row) => [$row->occurred_at, $row->record_type, (int) $row->source_id], SORT_REGULAR, true)->values();
        }

        return $this->serialize($records, $exam);
    }

    private function serialize(Collection $records, Exam $exam): array
    {
        $students = Student::whereIn('id', $records->pluck('student_id')->filter()->unique())->get()->keyBy('id');
        $users = User::with(['teacher', 'student'])->whereIn('id', $records->pluck('actor_id')->filter()->unique())->get()->keyBy('id');

        return $records->map(function ($record) use ($students, $users, $exam) {
            $student = $students->get($record->student_id);
            $actor = $users->get($record->actor_id ?? null);
            $event = in_array($record->record_type, ['ENTRY', 'INCIDENT', 'INFRACTION', 'EXPULSION'], true);
            abort_if(($event && ($actor === null || trim($actor->display_name) === ''))
                || ($record->record_type !== 'INCIDENT' && $student === null), 503,
                'El reporte contiene evidencia histórica incompleta; no se generó una copia válida.');

            return ['source_id' => (int) $record->source_id, 'record_type' => $record->record_type, 'exam_id' => (int) $exam->id,
                'student' => $student === null ? null : ['id' => (int) $student->id, 'first_names' => $student->first_names,
                    'last_names' => $student->last_names, 'sis_code' => (string) $student->sis_code,
                    'identity_number' => $student->identity_number === null ? null : (string) $student->identity_number],
                'state' => $record->state, 'category' => $record->category ?? null, 'description' => $record->description ?? null,
                'occurred_at' => ! isset($record->occurred_at) ? null : CarbonImmutable::parse($record->occurred_at, config('app.timezone'))->toISOString(),
                'method' => $record->method ?? null, 'origin' => $record->origin ?? null,
                'recorded_by' => $actor === null ? null : ['id' => (int) $actor->id, 'name' => $actor->display_name],
                'related_infraction_id' => isset($record->related_infraction_id) ? (int) $record->related_infraction_id : null];
        })->all();
    }
}
