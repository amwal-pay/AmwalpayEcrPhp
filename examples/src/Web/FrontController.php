<?php

declare(strict_types=1);

namespace AmwalExample\Web;

/**
 * Everything between the web server and the API: host filtering, security
 * headers, the `/api` guard, the 64 KB body cap, static files, and the
 * exception handler. The PHP counterpart of the web sample's Program.cs and
 * SecurityMiddleware.
 *
 * No authentication. These controls make a local, single-operator deployment
 * reasonable; they do not make it a service. Read "Before you expose this" in
 * examples/README.md.
 */
final class FrontController
{
    /** No request here carries more than a form. */
    public const MAX_BODY_BYTES = 64 * 1024;

    /** Loopback names only by default: defeats DNS rebinding. `AMWAL_ECR_EXAMPLE_ALLOWED_HOSTS` overrides. */
    public const DEFAULT_ALLOWED_HOSTS = '127.0.0.1;localhost;[::1]';

    // No inline script or style anywhere, so the policy needs no exceptions.
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; "
        . "form-action 'self'; frame-ancestors 'none'";

    private const STATIC_TYPES = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8'];

    public function __construct(private readonly string $publicDirectory, private readonly \Closure $app)
    {
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $query $_GET
     */
    public function handle(array $server, array $query, string $rawBody): Response
    {
        $response = $this->route($server, $query, $rawBody);
        $headers = $response->headers + [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];
        $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);
        // Terminals, results and the log are operator data: never cached.
        if (str_starts_with($path, '/api')) {
            $headers['Cache-Control'] = 'no-store';
        }
        return new Response($response->status, $headers, $response->body);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     */
    private function route(array $server, array $query, string $rawBody): Response
    {
        $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
        $hostName = preg_replace('/:[0-9]+$/', '', $host) ?? $host;
        $allowed = array_map('strtolower', array_map('trim', explode(';', getenv('AMWAL_ECR_EXAMPLE_ALLOWED_HOSTS') ?: self::DEFAULT_ALLOWED_HOSTS)));
        if (!\in_array($hostName, $allowed, true) && !\in_array('*', $allowed, true)) {
            return Response::empty(400);
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);

        if (!str_starts_with($path, '/api/') && $path !== '/api') {
            return $this->staticFile($path);
        }

        if (!self::sameOrigin($server, $host)) {
            return Response::json(403, ['error' => 'Cross-origin requests are not accepted']);
        }
        $mutating = $method !== 'GET' && $method !== 'HEAD';
        if ($mutating && !self::isJson((string) ($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? ''))) {
            return Response::json(415, ['error' => 'Use Content-Type: application/json']);
        }
        if (\strlen($rawBody) > self::MAX_BODY_BYTES || (int) ($server['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            return Response::empty(413);
        }

        $body = [];
        if ($mutating && trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true, 16);
            if (!\is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                return Response::json(400, ['error' => 'The request could not be read', 'transactionId' => null]);
            }
            $body = $decoded;
        }

        try {
            return (new Api(($this->app)()))->handle($method, $path, $query, $body);
        } catch (\Throwable $e) {
            // Never the exception text: it can name paths, and it is not for a browser.
            error_log('[AmwalECR example] ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return Response::json(500, ['error' => 'Unexpected error', 'transactionId' => null]);
        }
    }

    /** `/` is index.html; files under the public folder are served with the same headers. */
    private function staticFile(string $path): Response
    {
        $relative = $path === '/' ? 'index.html' : ltrim($path, '/');
        $extension = strtolower(pathinfo($relative, \PATHINFO_EXTENSION));
        if (preg_match('#^[a-z0-9_./-]+$#i', $relative) !== 1 || str_contains($relative, '..') || !isset(self::STATIC_TYPES[$extension])) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
        }
        $file = $this->publicDirectory . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        if (!is_file($file)) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
        }
        return new Response(200, ['Content-Type' => self::STATIC_TYPES[$extension]], (string) file_get_contents($file));
    }

    /**
     * Refuses requests another origin made. No CORS is configured, so a browser
     * will not let another site read a response; requiring JSON on mutating
     * calls forces a preflight that fails, and an Origin must be this host.
     *
     * @param array<string, mixed> $server
     */
    private static function sameOrigin(array $server, string $host): bool
    {
        $site = (string) ($server['HTTP_SEC_FETCH_SITE'] ?? '');
        if ($site !== '' && $site !== 'same-origin' && $site !== 'none') {
            return false;
        }
        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return true;
        }
        $parts = parse_url($origin);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return false;
        }
        $authority = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $authority === $host;
    }

    private static function isJson(string $contentType): bool
    {
        return strtolower(trim(explode(';', $contentType)[0])) === 'application/json';
    }
}
