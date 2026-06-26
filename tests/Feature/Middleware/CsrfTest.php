<?php

declare(strict_types=1);

use App\Middleware\CSRF;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Slim\Http\Response;

afterEach(function () {
    $_SESSION = [];
});

/**
 * 一个永远返回 200 + {ret:1,msg:'ok'} 的下游 handler，
 * 用来断言中间件是否放行。
 */
function csrfOkHandler(): Psr\Http\Server\RequestHandlerInterface
{
    return new class () implements Psr\Http\Server\RequestHandlerInterface {
        public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
        {
            return (new Response(new Psr7Response(), new HttpFactory()))->withJson(['ret' => 1, 'msg' => 'ok']);
        }
    };
}

/**
 * 在测试用例上下文($this 绑定到 SlimTestCase)中执行 CSRF 中间件。
 * 用 Closure::bind 把 $this 绑给闭包,这样 helper 内部可以调用
 * $this->createRequest()。直接写裸函数名 runCsrf->call(...) 会被 PHP
 * 当作未定义常量,故不可行。
 */
function runCsrf(object $ctx, string $method, array $headers = []): Response
{
    $run = Closure::bind(function (string $method, array $headers): Response {
        $mw = new CSRF();
        $request = $this->createRequest($method, '/user/edit/theme');
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }

        return $mw->process($request, csrfOkHandler());
    }, $ctx, $ctx);

    return $run($method, $headers);
}

it('lets GET requests through without a token', function () {
    $mw = new CSRF();
    $request = $this->createRequest('GET', '/user/subscription');
    $resp = $mw->process($request, csrfOkHandler());
    expect($resp->getStatusCode())->toBe(200);
});

it('rejects a POST with no token', function () {
    $resp = runCsrf($this, 'POST');
    expect($resp->getStatusCode())->toBe(403);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body['ret'])->toBe(0);
    expect($body['msg'])->toBe('CSRF token mismatch');
});

it('accepts a POST carrying the session token', function () {
    $token = CSRF::token();
    $resp = runCsrf($this, 'POST', ['X-CSRF-Token' => $token]);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body['ret'])->toBe(1);
});

it('rejects a POST with a wrong token', function () {
    CSRF::token();
    $resp = runCsrf($this, 'POST', ['X-CSRF-Token' => 'definitely-wrong']);
    expect($resp->getStatusCode())->toBe(403);
});
