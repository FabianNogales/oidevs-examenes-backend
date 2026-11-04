<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class HandlePortalServiceErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
            // Laravel's routing pipeline may render an exception before returning
            // through outer middleware, especially when APP_DEBUG is enabled locally.
            if ($response->getStatusCode() >= 500) {
                return $this->failure();
            }
            if ($response->getStatusCode() === 404) {
                return response()->json(['message' => 'Servicio no encontrado.'], 404);
            }

            return $response;
        } catch (ValidationException|HttpResponseException $exception) {
            throw $exception;
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Servicio no encontrado.'], 404);
        } catch (HttpExceptionInterface $exception) {
            return response()->json(['message' => $exception->getMessage() ?: 'No se pudo completar la solicitud.'], $exception->getStatusCode());
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure();
        }
    }

    private function failure()
    {
        return response()->json(['code' => 'INTERNAL_ERROR', 'message' => 'No se pudo completar la operación de servicios. Intenta nuevamente.'], 500);
    }
}
