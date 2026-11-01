<?php

namespace Tests\Feature\Entries;

use App\Models\ExamCollaborator;
use App\Models\StudentQrToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class EntryReentryTest extends TestCase
{
    use EntryAnnulmentFixtures;
    use RefreshDatabase;

    private function annul(int $id): void
    {
        $this->actingAs($this->owner)->postJson($this->annulPath($id), [
            'confirmed' => true, 'reason' => 'Registro incorrecto.',
        ])->assertOk();
    }

    public function test_annulled_entry_is_removed_from_indicators_and_manual_reentry_preserves_history(): void
    {
        $id = $this->initialEntry();
        $this->actingAs($this->owner)->getJson($this->entryBase().'/students')->assertOk()
            ->assertJsonPath('summary.registered', 1)->assertJsonPath('summary.pending', 0);
        $this->annul($id);
        $this->getJson($this->entryBase().'/students')->assertOk()
            ->assertJsonPath('summary.registered', 0)->assertJsonPath('summary.pending', 1);
        $this->getJson($this->entryBase().'/students/'.$this->student->id)->assertOk()
            ->assertJsonPath('data.entry_status', 'PENDING')->assertJsonPath('data.entry', null);
        $saved = (array) DB::table('exam_entries')->find($id);
        $response = $this->postJson($this->manualPath(), ['room_id' => $this->exam->room_id])
            ->assertCreated()->assertJsonPath('result', 'AUTHORIZED');
        $this->assertNotSame($id, $response->json('data.entry.id'));
        $this->assertSame($saved, (array) DB::table('exam_entries')->find($id));
        $this->getJson($this->entryBase().'/students')->assertOk()->assertJsonPath('summary.registered', 1);
        $this->getJson($this->entryBase().'/entries')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertDatabaseCount('exam_entries', 2);
        $this->assertSame(1, DB::table('exam_entries')->where('status', 'VALID')->count());
    }

    public function test_reentry_rechecks_student_enrollment_eligibility_room_and_exam(): void
    {
        $id = $this->initialEntry();
        $this->annul($id);
        $saved = (array) DB::table('exam_entries')->find($id);
        $payload = ['room_id' => $this->exam->room_id];
        $this->student->update(['status' => 'INACTIVE']);
        $this->postJson($this->manualPath(), $payload)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'STUDENT_INACTIVE');
        $this->student->update(['status' => 'ACTIVE']);
        DB::table('enrollments')->update(['status' => 'INACTIVE']);
        $this->postJson($this->manualPath(), $payload)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'NOT_ENROLLED');
        DB::table('enrollments')->update(['status' => 'ACTIVE']);
        DB::table('exam_eligibilities')->update(['status' => 'INELIGIBLE']);
        $this->postJson($this->manualPath(), $payload)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'INELIGIBLE');
        DB::table('exam_eligibilities')->update(['status' => 'ELIGIBLE']);
        $room = DB::table('rooms')->insertGetId(['code' => 'REENTRY', 'name' => 'Otra aula', 'status' => 'ACTIVE']);
        $this->postJson($this->manualPath(), ['room_id' => $room])->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'ROOM_MISMATCH');
        $this->exam->update(['status' => 'CANCELLED']);
        $this->postJson($this->manualPath(), $payload)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'EXAM_UNAVAILABLE');
        $this->assertSame($saved, (array) DB::table('exam_entries')->find($id));
        $this->assertDatabaseCount('exam_entries', 1);
    }

    public function test_qr_reentry_rechecks_current_eligibility_and_token_validity(): void
    {
        $id = $this->initialEntry();
        $token = $this->signedQr();
        $this->annul($id);
        $payload = ['room_id' => $this->exam->room_id, 'qr_token' => $token];
        DB::table('exam_eligibilities')->update(['status' => 'INELIGIBLE']);
        $this->postJson($this->entryBase().'/qr', $payload)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'INELIGIBLE');
        DB::table('exam_eligibilities')->update(['status' => 'ELIGIBLE']);
        $response = $this->postJson($this->entryBase().'/qr', $payload)->assertCreated()->assertJsonPath('data.entry.verification_method', 'QR');
        $this->annul($response->json('data.entry.id'));
        StudentQrToken::query()->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->postJson($this->entryBase().'/qr', $payload)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR');
        $this->assertDatabaseCount('exam_entries', 2);
        $this->assertSame(0, DB::table('exam_entries')->where('status', 'VALID')->count());
    }

    public function test_revoked_collaborator_cannot_register_again_after_annulment(): void
    {
        $id = $this->initialEntry();
        $collaborator = $this->collaborator();
        $this->annul($id);
        ExamCollaborator::where('user_id', $collaborator->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->actingAs($collaborator)->postJson($this->manualPath(), ['room_id' => $this->exam->room_id])->assertForbidden();
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'ANNULLED']);
    }
}
