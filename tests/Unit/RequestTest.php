<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Request;
use Fw\Tests\TestCase;

final class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset superglobals
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
        ];

        // Trust all proxies for tests to verify header detection
        Request::setTrustedProxies(['*']);
    }

    public function testMethodIsDetected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request = new Request();

        $this->assertEquals('POST', $request->method);
    }

    public function testMethodIsCaseInsensitive(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';

        $request = new Request();

        $this->assertEquals('POST', $request->method);
    }

    public function testUriIsParsed(): void
    {
        $_SERVER['REQUEST_URI'] = '/users/123';

        $request = new Request();

        $this->assertEquals('/users/123', $request->uri);
    }

    public function testUriQueryStringIsStripped(): void
    {
        $_SERVER['REQUEST_URI'] = '/search?q=test&page=1';

        $request = new Request();

        $this->assertEquals('/search', $request->uri);
    }

    public function testFullUriIncludesQueryString(): void
    {
        $_SERVER['REQUEST_URI'] = '/search?q=test&page=1';

        $request = new Request();

        $this->assertEquals('/search?q=test&page=1', $request->fullUri);
    }

    public function testGetReturnsQueryParameter(): void
    {
        $_GET['name'] = 'John';

        $request = new Request();

        $this->assertEquals('John', $request->get('name'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $request = new Request();

        $this->assertEquals('default', $request->get('missing', 'default'));
    }

    public function testPostReturnsPostData(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['email'] = 'test@example.com';

        $request = new Request();

        $this->assertEquals('test@example.com', $request->post('email'));
    }

    public function testPostReturnsDefaultWhenMissing(): void
    {
        $request = new Request();

        $this->assertEquals('default', $request->post('missing', 'default'));
    }

    public function testInputPrefersPostOverGet(): void
    {
        $_GET['key'] = 'get-value';
        $_POST['key'] = 'post-value';

        $request = new Request();

        $this->assertEquals('post-value', $request->input('key'));
    }

    public function testInputFallsBackToGet(): void
    {
        $_GET['key'] = 'get-value';

        $request = new Request();

        $this->assertEquals('get-value', $request->input('key'));
    }

    public function testAllMergesGetAndPost(): void
    {
        $_GET['from_get'] = 'get-value';
        $_POST['from_post'] = 'post-value';

        $request = new Request();
        $all = $request->all();

        $this->assertArrayHasKey('from_get', $all);
        $this->assertArrayHasKey('from_post', $all);
    }

    public function testOnlyReturnsSpecifiedKeys(): void
    {
        $_POST = [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'secret',
        ];

        $request = new Request();
        $only = $request->only(['name', 'email']);

        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com'], $only);
    }

    public function testExceptExcludesSpecifiedKeys(): void
    {
        $_POST = [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'secret',
        ];

        $request = new Request();
        $except = $request->except(['password']);

        $this->assertArrayNotHasKey('password', $except);
        $this->assertArrayHasKey('name', $except);
        $this->assertArrayHasKey('email', $except);
    }

    public function testHasReturnsTrueWhenKeyExists(): void
    {
        $_GET['key'] = 'value';

        $request = new Request();

        $this->assertTrue($request->has('key'));
    }

    public function testHasReturnsFalseWhenKeyMissing(): void
    {
        $request = new Request();

        $this->assertFalse($request->has('missing'));
    }

    public function testHeaderReturnsHttpHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $request = new Request();

        $this->assertEquals('application/json', $request->header('Accept'));
    }

    public function testHeaderIsCaseInsensitive(): void
    {
        $_SERVER['HTTP_CONTENT_TYPE'] = 'text/html';

        $request = new Request();

        $this->assertEquals('text/html', $request->header('content-type'));
        $this->assertEquals('text/html', $request->header('Content-Type'));
    }

    public function testHeaderReturnsDefaultWhenMissing(): void
    {
        $request = new Request();

        $this->assertEquals('default', $request->header('X-Missing', 'default'));
    }

    public function testIsAjaxDetectsXhrHeader(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $request = new Request();

        $this->assertTrue($request->isAjax());
    }

    public function testIsAjaxReturnsFalseForNormalRequests(): void
    {
        $request = new Request();

        $this->assertFalse($request->isAjax());
    }

    public function testIsJsonDetectsJsonContentType(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $request = new Request();

        $this->assertTrue($request->isJson());
    }

    public function testIsJsonDetectsJsonContentTypeWithCharset(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';

        $request = new Request();

        $this->assertTrue($request->isJson());
    }

    public function testIsSecureDetectsHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $request = new Request();

        $this->assertTrue($request->isSecure());
    }

    public function testIsSecureDetectsForwardedProto(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $request = new Request();

        $this->assertTrue($request->isSecure());
    }

    public function testIpReturnsRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        $request = new Request();

        $this->assertEquals('192.168.1.1', $request->ip());
    }

    public function testIpPrefersForwardedFor(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';

        $request = new Request();

        $this->assertEquals('10.0.0.1', $request->ip());
    }

    public function testUserAgentReturnsHeader(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

        $request = new Request();

        $this->assertEquals('Mozilla/5.0', $request->userAgent());
    }

    public function testBearerTokenExtractsToken(): void
    {
        // Realistic token: userId|hex64 format, 101+ chars
        $token = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890|' . str_repeat('a1b2c3d4', 8);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $request = new Request();

        $this->assertEquals($token, $request->bearerToken());
    }

    public function testBearerTokenReturnsNullForOtherAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $request = new Request();

        $this->assertNull($request->bearerToken());
    }

    public function testBearerTokenReturnsNullForEmptyToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';

        $request = new Request();

        $this->assertNull($request->bearerToken());
    }

    public function testBearerTokenReturnsNullForTooShortToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tooshort';

        $request = new Request();

        $this->assertNull($request->bearerToken());
    }

    public function testBearerTokenReturnsNullForTooLongToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('x', 513);

        $request = new Request();

        $this->assertNull($request->bearerToken());
    }

    public function testMethodSpoofingWithPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_method'] = 'PUT';

        $request = new Request();

        $this->assertEquals('PUT', $request->method);
    }

    public function testMethodSpoofingAllowsDelete(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_method'] = 'DELETE';

        $request = new Request();

        $this->assertEquals('DELETE', $request->method);
    }

    public function testMethodSpoofingAllowsPatch(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_method'] = 'PATCH';

        $request = new Request();

        $this->assertEquals('PATCH', $request->method);
    }

    public function testMethodSpoofingIgnoredForGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['_method'] = 'DELETE';

        $request = new Request();

        $this->assertEquals('GET', $request->method);
    }

    public function testMethodSpoofingRejectsInvalidMethods(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_method'] = 'INVALID';

        $request = new Request();

        $this->assertEquals('POST', $request->method);
    }

    public function testFileReturnsUploadedFile(): void
    {
        $_FILES['avatar'] = [
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '/tmp/phpXXX',
            'error' => UPLOAD_ERR_OK,
            'size' => 12345,
        ];

        $request = new Request();
        $file = $request->file('avatar');

        $this->assertNotNull($file);
        $this->assertEquals('photo.jpg', $file['name']);
    }

    public function testFileReturnsNullWhenMissing(): void
    {
        $request = new Request();

        $this->assertNull($request->file('missing'));
    }

    public function testQueryReturnsAllGetParams(): void
    {
        $_GET = ['a' => '1', 'b' => '2'];

        $request = new Request();

        $this->assertEquals(['a' => '1', 'b' => '2'], $request->query());
    }

    public function testPostDataReturnsAllPostParams(): void
    {
        $_POST = ['a' => '1', 'b' => '2'];

        $request = new Request();

        $this->assertEquals(['a' => '1', 'b' => '2'], $request->postData());
    }

    public function testServerReturnsServerValue(): void
    {
        $_SERVER['SERVER_NAME'] = 'example.com';

        $request = new Request();

        $this->assertEquals('example.com', $request->server('SERVER_NAME'));
    }

    public function testServerReturnsDefaultWhenMissing(): void
    {
        $request = new Request();

        $this->assertEquals('default', $request->server('MISSING', 'default'));
    }

    public function testExpectsJsonReturnsTrueForJsonAcceptHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $request = new Request();

        $this->assertTrue($request->expectsJson());
    }

    public function testExpectsJsonReturnsTrueForWildcardAccept(): void
    {
        $_SERVER['HTTP_ACCEPT'] = '*/*';

        $request = new Request();

        $this->assertTrue($request->expectsJson());
    }

    public function testExpectsJsonReturnsFalseForHtmlAccept(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $request = new Request();

        $this->assertFalse($request->expectsJson());
    }

    public function testWantsJsonReturnsTrueForJsonAcceptHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $request = new Request();

        $this->assertTrue($request->wantsJson());
    }

    public function testWantsJsonReturnsTrueForAjaxRequests(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $request = new Request();

        $this->assertTrue($request->wantsJson());
    }

    public function testWantsJsonReturnsTrueForApiPaths(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/users';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $request = new Request();

        $this->assertTrue($request->wantsJson());
    }

    public function testWantsJsonReturnsTrueForNestedApiPaths(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/users/123';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $request = new Request();

        $this->assertTrue($request->wantsJson());
    }

    public function testWantsJsonReturnsFalseForNonApiPaths(): void
    {
        $_SERVER['REQUEST_URI'] = '/dashboard';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $request = new Request();

        $this->assertFalse($request->wantsJson());
    }

    public function testHasReturnsTrueForNullQueryParam(): void
    {
        $_GET = ['patch_field' => null];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $request = new Request();

        $this->assertTrue($request->has('patch_field'));
    }

    public function testHasReturnsTrueForNullPostParam(): void
    {
        $_POST = ['nullable_field' => null];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request = new Request();

        $this->assertTrue($request->has('nullable_field'));
    }

    public function testHasReturnsFalseForAbsentKey(): void
    {
        $_GET = ['other' => 'value'];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $request = new Request();

        $this->assertFalse($request->has('missing'));
    }
}
