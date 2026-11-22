<?php

namespace App\Support;

use App\Domain\Commerce\CheckoutException;
use App\Domain\Payments\PaymentException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiProblem
{
    public static function from(Throwable $e): ?JsonResponse
    {
        [$status, $code, $title, $detail, $extra, $headers] = match (true) {
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', collect($e->errors())->flatten()->first(), ['errors' => $e->errors()], []],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Unauthenticated.', null, [], []],
            $e instanceof MissingAbilityException, $e instanceof AuthorizationException => [403, 'forbidden', 'This action is not allowed.', null, [], []],
            $e instanceof ModelNotFoundException => [404, 'not_found', 'Not found.', null, [], []],
            $e instanceof CheckoutException => [422, 'checkout_rejected', 'The request could not be completed.', $e->getMessage(), [], []],
            $e instanceof PaymentException => [409, 'payment_unavailable', 'Payment could not be started.', $e->getMessage(), [], []],
            $e instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests.', null, [], $e->getHeaders()],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), self::codeFor($e->getStatusCode()), $e->getMessage() ?: 'Error', null, [], $e->getHeaders()],
            default => [null, null, null, null, [], []],
        };

        if ($status === null) {
            return null; // let Laravel report it and render a 500
        }

        return new JsonResponse(array_filter([
            'type' => 'https://lugha.app/errors/'.str_replace('_', '-', $code),
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
        ], fn ($v) => $v !== null) + $extra, $status, $headers + ['Content-Type' => 'application/problem+json']);
    }

    private static function codeFor(int $status): string
    {
        return [400 => 'bad_request', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict'][$status] ?? 'http_error';
    }
}
