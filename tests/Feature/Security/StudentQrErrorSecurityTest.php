<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Api\V1\Students\StudentQrController;
use App\Models\Student;
use App\Models\User;
use App\Services\QrV3\QrV3Exception;
use App\Services\Students\StudentQrAccessException;
use App\Services\Students\StudentQrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

// Controller boundary tests require neither persistence nor issuance secrets.
class StudentQrErrorSecurityTest extends TestCase
{
    private function request(string $query = ''): Request
    {
        $actor = new User;
        $actor->setRelation('student', (new Student)->forceFill(['id' => 17]));
        $request = Request::create('/api/v1/students/exams/23/qr'.$query);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }

    public function test_expected_domain_denials_keep_their_safe_messages_and_forbidden_status(): void
    {
        foreach (['EXAM_UNAVAILABLE', 'STUDENT_NOT_ELIGIBLE', 'QR_NOT_YET_AVAILABLE', 'EXAM_FINISHED'] as $reason) {
            $failure = new StudentQrAccessException($reason);
            $service = Mockery::mock(StudentQrService::class);
            $service->shouldReceive('getOrGenerateForExam')->once()->with(17, 23, 2)->andThrow($failure);
            $response = (new StudentQrController($service))->show($this->request(), 23);
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame(['code' => $reason, 'message' => $failure->getMessage()], $response->getData(true));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
    }

    public function test_unexpected_exceptions_and_errors_never_expose_messages_or_sensitive_log_context(): void
    {
        foreach ([new \RuntimeException('SQL token=private-qr-secret key=private-pem'), new \Error('private-qr-secret')] as $failure) {
            $logger = Mockery::mock(LoggerInterface::class);
            Log::shouldReceive('channel')->once()->with('stderr')->andReturn($logger);
            $logger->shouldReceive('error')->once()->with('qr.issue_failed', ['exam_id' => 23, 'exception_class' => $failure::class]);
            $service = Mockery::mock(StudentQrService::class);
            $service->shouldReceive('getOrGenerateForExam')->once()->andThrow($failure);
            $response = (new StudentQrController($service))->show($this->request('?version=3'), 23);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame('QR_GENERATION_FAILED', $response->getData(true)['code']);
            $this->assertStringNotContainsString('private-qr-secret', $response->getContent());
            $this->assertStringNotContainsString('SQL', $response->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
    }

    public function test_logging_failure_still_returns_a_generic_internal_error(): void
    {
        Log::shouldReceive('channel')->once()->andThrow(new \RuntimeException('logger private detail'));
        $service = Mockery::mock(StudentQrService::class);
        $service->shouldReceive('getOrGenerateForExam')->once()->andThrow(new \RuntimeException('database private detail'));
        $response = (new StudentQrController($service))->show($this->request(), 23);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('QR_GENERATION_FAILED', $response->getData(true)['code']);
        $this->assertStringNotContainsString('private detail', $response->getContent());
    }

    public function test_v3_configuration_failure_keeps_its_existing_service_unavailable_contract(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->once()->with('stderr')->andReturn($logger);
        $logger->shouldReceive('error')->once()->with('qr_v3.issue_failed', ['exam_id' => 23, 'failure_code' => 'SIGNING_FAILED']);
        $service = Mockery::mock(StudentQrService::class);
        $service->shouldReceive('getOrGenerateForExam')->once()->with(17, 23, 3)->andThrow(new QrV3Exception('SIGNING_FAILED'));
        $response = (new StudentQrController($service))->show($this->request('?version=3'), 23);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('QR_V3_UNAVAILABLE', $response->getData(true)['code']);
    }

    public function test_successful_v3_issuance_and_invalid_version_keep_the_existing_contracts(): void
    {
        $service = Mockery::mock(StudentQrService::class);
        $data = ['exam_id' => 23, 'token' => 'synthetic-signed-token', 'qr_code_base64' => 'synthetic-image'];
        $service->shouldReceive('getOrGenerateForExam')->once()->with(17, 23, 3)->andReturn($data);
        $controller = new StudentQrController($service);
        $response = $controller->show($this->request('?version=3'), 23);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($data, $response->getData(true)['data']);
        $this->assertSame(422, $controller->show($this->request('?version=1'), 23)->getStatusCode());
    }
}
