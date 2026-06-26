<?php

declare(strict_types=1);

namespace App\Middleware;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Http\Response;
use function bin2hex;
use function headers_sent;
use function hash_equals;
use function in_array;
use function random_bytes;
use function session_start;
use function session_status;
use const PHP_SESSION_NONE;

final class CSRF implements MiddlewareInterface
{
    private const SESSION_KEY = '_csrf_token';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * 返回(必要时生成)当前会话的 CSRF token。
     * 由后续阶段(P3 前端)读取并通过 HTMx 注入到 X-CSRF-Token 头。
     */
    public static function token(): string
    {
        self::ensureSession();

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $expected = self::token();
        $provided = $request->getHeaderLine('X-CSRF-Token');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return (new Response(new Psr7Response(), new HttpFactory()))
                ->withJson(['ret' => 0, 'msg' => 'CSRF token mismatch'], 403);
        }

        return $handler->handle($request);
    }

    /**
     * 防御性地引导 session:这是代码库里首个 session 使用方
     * (鉴权走 cookie),因此中间件自行负责启动 session,
     * 让 token 能跨请求存活。
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && ! headers_sent()) {
            @session_start([
                'cookie_samesite' => 'Lax',
                'cookie_httponly' => true,
            ]);
        }

        if (! isset($_SESSION)) {
            $_SESSION = [];
        }
    }
}
