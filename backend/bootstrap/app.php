<?php

use App\Exceptions\AlertException;
use App\Exceptions\AssetException;
use App\Exceptions\AuthFlowException;
use App\Exceptions\CatalogException;
use App\Exceptions\DocumentException;
use App\Exceptions\EventJournalException;
use App\Exceptions\InstanceSettingsException;
use App\Exceptions\InventoryException;
use App\Exceptions\LocationException;
use App\Exceptions\MeasurementException;
use App\Exceptions\NotificationException;
use App\Exceptions\PlantException;
use App\Exceptions\ReportException;
use App\Exceptions\SensorException;
use App\Exceptions\UserAdministrationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/ingestion.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([InstanceSettingsException::class, PlantException::class, CatalogException::class, AuthFlowException::class, UserAdministrationException::class, LocationException::class, AssetException::class, SensorException::class, EventJournalException::class, DocumentException::class, InventoryException::class, MeasurementException::class, AlertException::class, ReportException::class, NotificationException::class]);
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            if ($exception instanceof PlantException || $exception instanceof CatalogException || $exception instanceof InstanceSettingsException || $exception instanceof AuthFlowException || $exception instanceof UserAdministrationException || $exception instanceof LocationException || $exception instanceof AssetException || $exception instanceof SensorException || $exception instanceof EventJournalException || $exception instanceof DocumentException || $exception instanceof InventoryException || $exception instanceof MeasurementException || $exception instanceof AlertException || $exception instanceof ReportException || $exception instanceof NotificationException) {
                return response()->json(['error' => ['code' => $exception->errorCode]], $exception->status);
            }

            if ($exception instanceof ValidationException) {
                return response()->json(['error' => ['code' => 'auth.errors.validationFailed'], 'errors' => $exception->errors()], 422);
            }

            $status = $exception instanceof AuthenticationException ? 401
                : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);
            $code = match ($status) {
                401 => 'auth.errors.unauthenticated',
                403 => 'auth.errors.forbidden',
                404 => 'auth.errors.notFound',
                419 => 'auth.errors.sessionExpired',
                413 => 'documents.errors.tooLarge',
                429 => 'auth.errors.rateLimited',
                default => 'auth.errors.requestFailed',
            };
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response()->json(['error' => ['code' => $code]], $status, $headers);
        });
    })->create();
