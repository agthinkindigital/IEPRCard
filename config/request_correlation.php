<?php
declare(strict_types=1);

require_once __DIR__ . '/technical_events.php';

function httpRequestCorrelationId(): string
{
    static $correlationId = null;
    if (is_string($correlationId)) {
        return $correlationId;
    }

    $correlationId = technicalEventsNewCorrelationId();

    return $correlationId;
}

function httpRequestCorrelationStart(): string
{
    static $startedAt = null;
    static $shutdownRegistered = false;
    $correlationId = httpRequestCorrelationId();
    $startedAt = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Correlation-ID: ' . $correlationId);
    }
    if (PHP_SAPI !== 'cli' && !$shutdownRegistered) {
        $shutdownRegistered = true;
        register_shutdown_function(static function () use (&$startedAt, $correlationId): void {
            try {
                require_once __DIR__ . '/conn.php';
                require_once __DIR__ . '/technical_events.php';
                $pdo = dbConnection();
                if ($pdo->inTransaction()) {
                    $pdo = dbConnect(dbReadEnvironment());
                }
                $route = technicalEventsCanonicalRoute(
                    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
                    (string) ($_SERVER['SCRIPT_NAME'] ?? '')
                );
                $status = http_response_code();
                $lastError = error_get_last();
                if (is_array($lastError) && in_array($lastError['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    $status = 500;
                }
                technicalEventsRecord($pdo, [
                    'correlation_id' => $correlationId,
                    'categoria' => $route['categoria'],
                    'evento_chave' => 'http.response',
                    'rota' => $route['rota'],
                    'status_http' => is_int($status) && $status >= 100 && $status <= 599 ? $status : 200,
                    'duracao_ms' => max(0, (int) floor((microtime(true) - (float) $startedAt) * 1000)),
                ]);
            } catch (Throwable) {
                // Technical telemetry is best-effort and must never change the observed response.
            }
        });
    }

    return $correlationId;
}

httpRequestCorrelationStart();
