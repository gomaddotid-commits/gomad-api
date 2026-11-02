<?php

use App\Core\Ledger\Domain\IdempotencyConflict;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\MadTrans\Domain\CodCollateralInsufficient;
use App\Core\MadTrans\Domain\TravelCheckoutNotConfigured;
use App\Core\Payment\Domain\InvalidWebhookSignature;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Core\Warung\Domain\CheckoutNotConfigured;
use App\Http\Middleware\EnsureAccountArea;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'area' => EnsureAccountArea::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (LedgerException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception instanceof IdempotencyConflict ? 'IDEMPOTENCY_CONFLICT' : 'LEDGER_CONFLICT',
                'errors' => (object) [],
            ], 409);
        });
        $exceptions->render(function (InvalidArgumentException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_FAILED',
                'errors' => (object) [],
            ], 422);
        });
        $exceptions->render(function (InvalidWebhookSignature $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Signature webhook tidak valid.',
                'code' => 'INVALID_WEBHOOK_SIGNATURE',
                'errors' => (object) [],
            ], 401);
        });
        $exceptions->render(function (PaymentConflict $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'PAYMENT_CONFLICT',
                'errors' => (object) [],
            ], 409);
        });
        $exceptions->render(function (PaymentGatewayException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'PAYMENT_GATEWAY_UNAVAILABLE',
                'errors' => (object) [],
            ], 502);
        });
        $exceptions->render(function (CheckoutNotConfigured $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'CHECKOUT_NOT_CONFIGURED',
                'errors' => (object) [],
            ], 503);
        });
        $exceptions->render(function (TravelCheckoutNotConfigured $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'CHECKOUT_NOT_CONFIGURED',
                'errors' => (object) [],
            ], 503);
        });
        $exceptions->render(function (CodCollateralInsufficient $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'COD_COLLATERAL_INSUFFICIENT',
                'errors' => (object) [],
            ], 409);
        });
        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request) {
            if (! $request->is('api/*')
                || ! in_array($response->getStatusCode(), [400, 401, 403, 404, 409, 422, 429, 500], true)) {
                return $response;
            }

            $payload = $response->getContent();
            $body = is_string($payload) ? json_decode($payload, true) : null;
            $status = $response->getStatusCode();
            $code = is_array($body) && is_string($body['code'] ?? null)
                ? $body['code']
                : match ($status) {
                    400 => 'INVALID_REQUEST',
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    409 => 'CONFLICT',
                    422 => 'VALIDATION_FAILED',
                    429 => 'RATE_LIMITED',
                    500 => 'SERVER_ERROR',
                };
            $message = $status === 500
                ? 'Terjadi kesalahan pada server.'
                : (is_array($body) && isset($body['message'])
                ? $body['message']
                : match ($status) {
                    400 => 'Permintaan tidak valid.',
                    401 => 'Autentikasi diperlukan.',
                    403 => 'Anda tidak memiliki izin untuk melakukan aksi ini.',
                    404 => 'Data tidak ditemukan.',
                    409 => 'Permintaan bertentangan dengan keadaan saat ini.',
                    422 => 'Data yang dikirim tidak valid.',
                    429 => 'Terlalu banyak permintaan. Coba lagi nanti.',
                });

            return response()->json([
                'message' => $message,
                'code' => $code,
                'errors' => $status === 422 && is_array($body['errors'] ?? null)
                    ? (object) $body['errors']
                    : (object) [],
            ], $status);
        });
    })->create();
