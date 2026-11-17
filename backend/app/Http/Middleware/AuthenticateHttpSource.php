<?php

namespace App\Http\Middleware;

use App\Exceptions\AuthFlowException;
use App\Models\HttpMeasurementSource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class AuthenticateHttpSource
{
    public function handle(Request $request, Closure $next)
    {
        if (strlen($request->getContent()) > 4096) {
            throw new AuthFlowException('httpIngestion.validation.payloadTooLarge', 413);
        }
        if (! $request->isJson()) {
            throw new AuthFlowException('httpIngestion.validation.jsonRequired', 415);
        }
        $token = $request->bearerToken();
        $source = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? HttpMeasurementSource::with('issuer.permissionRoles')->find($request->route('source')) : null;
        if (! $source || ! $source->is_enabled || ! $source->credentialCurrent() || ! hash_equals((string) $source->token_hash, hash('sha256', $token))) {
            throw new AuthFlowException('httpIngestion.errors.invalidCredential', 401);
        }
        $key = 'http-ingestion-source:'.$source->id;
        if (! RateLimiter::attempt($key, 60, fn () => true, 60)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
        $request->attributes->set('http_source', $source);

        return $next($request);
    }
}
