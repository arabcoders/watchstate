<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\TestCase;
use App\Libs\Uri;
use Psr\Http\Message\UriInterface;

class UriTest extends TestCase
{
    private array $customUrls = [
        'show://tvdb:320234@test_plex?id=130005',
        'redis://127.0.0.1:6379',
        '/',
    ];

    protected function getUri(): UriInterface
    {
        return new Uri('http://user:pass@host:81/path?query=string#fragment');
    }

    public function test_scheme()
    {
        $this->assertSame('http', $this->getUri()->getScheme(), 'The protocol should be http');
        $url2 = $this->getUri()->withScheme('https');
        $this->assertSame('https', $url2->getScheme(), 'The protocol should be https');

        $obj = $this->getUri();

        $this->assertSame(
            spl_object_id($obj),
            spl_object_id($obj->withScheme('HTTP')),
            'The object should be the same if the scheme is the same'
        );

        $this->checkException(
            closure: fn() => $this->getUri()->withScheme(false),
            reason: 'Exception should be thrown if the scheme is not a string',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Scheme must be a string'
        );
    }

    public function test_host()
    {
        $this->assertSame('host', $this->getUri()->getHost(), 'The host should be host');
        $url2 = $this->getUri()->withHost('host2');
        $this->assertSame('host2', $url2->getHost(), 'The host should be host2');
        $obj = $this->getUri();
        $this->assertSame(
            spl_object_id($obj),
            spl_object_id($obj->withHost($this->getUri()->getHost())),
            'The object should be the same if the host is the same'
        );

        $this->checkException(
            closure: fn() => new Uri('http://#example.invalid:81/foo/bar'),
            reason: 'parse_url returns false if the host is invalid',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Unable to parse URI'
        );

        $this->checkException(
            closure: fn() => $this->getUri()->withHost(false),
            reason: 'Exception should be thrown if the host is not a string',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Host must be a string'
        );
    }

    public function test_userInfo()
    {
        $this->assertSame('user:pass', $this->getUri()->getUserInfo(), 'The user info should be user:pass');
        $obj = $this->getUri();
        $this->assertSame(
            spl_object_id($obj),
            spl_object_id($obj->withUserInfo('user', 'pass')),
            'The object should be the same if the user info is the same'
        );

        $url2 = $this->getUri()->withUserInfo('user2', 'pass2');
        $this->assertSame('user2:pass2', $url2->getUserInfo(), 'The user info should be user2:pass2');

        $url3 = $this->getUri()->withUserInfo('user3');
        $this->assertSame('user3', $url3->getUserInfo(), 'The user info should be user3');

        $this->checkException(
            closure: fn() => new Uri('http://#:foo@example.com'),
            reason: 'parse_url returns false if the user info is invalid',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Unable to parse URI'
        );

        $this->checkException(
            closure: fn() => $this->getUri()->withUserInfo('foo', false),
            reason: 'Exception should be thrown if the user info is not a string',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'must be a string'
        );

        $this->checkException(
            closure: fn() => $this->getUri()->withUserInfo(false, 'foo'),
            reason: 'Exception should be thrown if the user info is not a string',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'must be a string'
        );
    }

    public function test_port()
    {
        $this->assertSame(81, $this->getUri()->getPort(), 'The port should be 80');
        $obj = $this->getUri();
        $this->assertSame(
            spl_object_id($obj),
            spl_object_id($obj->withPort(81)),
            'The object should be the same if the port is the same'
        );
        $this->assertSame(8080, $this->getUri()->withPort(8080)->getPort(), 'The port should be 8080');
        $this->assertNull($this->getUri()->withPort(null)->getPort(), 'The port should be null');
        $this->checkException(
            closure: fn() => $this->getUri()->withPort(65536),
            reason: 'Exception should be thrown if the port is invalid',
            exception: InvalidArgumentException::class,
        );

        $this->checkException(
            closure: fn() => new Uri('http://example.com:65536/foo/bar'),
            reason: 'parse_url returns false if the port is invalid',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Unable to parse URI'
        );
    }

    public function test_authority()
    {
        $this->assertSame(
            'user:pass@host:81',
            $this->getUri()->getAuthority(),
            'The authority should be user:pass@host:81'
        );
        $this->assertSame(
            '',
            $this->getUri()->withHost('')->getAuthority(),
            'The authority should be empty if the host is empty'
        );
    }

    public function test_path()
    {
        $uri = new Uri('http://example.com/');

        $this->assertSame('/', $uri->getPath(), 'The path should be /path');
        $this->assertSame('', $uri->withPath('')->getPath(), 'The path should be empty');
        $this->assertSame(
            spl_object_id($uri),
            spl_object_id($uri->withPath('/')),
            'The object should be the same if the path is the same'
        );
        $this->assertSame('/path2', $uri->withPath('/path2')->getPath(), 'The path should be /path2');
        $this->assertSame('/path/', $uri->withPath('/path/')->getPath(), 'The path should be /path');
        $this->assertSame(
            '/path/bar',
            $this->getUri()->withPath('/bar')->getPath(),
            'The path should be /path/bar due to basePath'
        );
        $this->checkException(
            closure: fn() => $this->getUri()->withPath(false),
            reason: 'Exception should be thrown if the path is not a string',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'must be a string'
        );
        $this->assertSame('/path/foo/', $this->getUri()->withHost('')->withPath('///foo/')->getPath());
        $this->assertSame('/foo/', $uri->withPath('foo/')->getPath());
        $this->assertSame(
            '/path/foo/',
            $this->getUri()->withPath('/foo/')->getPath(),
            'withPath should consider the base path if it was given in constructor.'
        );

        $this->assertSame(
            'http://user:pass@host:81/path/foo/?query=string#fragment',
            (string)$this->getUri()->withPath('/foo/'),
            'uri string should consider the base path if it was given in constructor.'
        );
    }

    public function test_query()
    {
        $this->assertSame('query=string', $this->getUri()->getQuery(), 'The query should be query=string');
        $obj = $this->getUri();
        $this->assertSame(
            spl_object_id($obj),
            spl_object_id($obj->withQuery('query=string')),
            'The object should be the same if the query is the same'
        );
        $this->assertSame(
            'query=string2',
            $this->getUri()->withQuery('query=string2')->getQuery(),
            'The query should be query=string2'
        );
        $this->assertSame('', $this->getUri()->withQuery('')->getQuery(), 'The query should be empty');

        $this->checkException(
            closure: fn() => $this->getUri()->withQuery(false),
            reason: 'parse_url returns false if the port is invalid',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'must be a string'
        );
    }

    public function test_fragment()
    {
        $this->assertSame('fragment', $this->getUri()->getFragment(), 'The fragment should be fragment');
        $obj = $this->getUri();
        $this->assertSame(
            spl_object_id($obj),
            spl_object_id($obj->withFragment('fragment')),
            'The object should be the same if the fragment is the same'
        );
        $this->assertSame(
            'fragment2',
            $this->getUri()->withFragment('fragment2')->getFragment(),
            'The fragment should be fragment2'
        );
        $this->assertSame('', $this->getUri()->withFragment('')->getFragment(), 'The fragment should be empty');

        $this->checkException(
            closure: fn() => $this->getUri()->withFragment(false),
            reason: 'Exception should be thrown if the fragment is not a string',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'must be a string'
        );
    }

    public function test_toString()
    {
        $this->assertSame(
            'http://user:pass@host:81/path?query=string#fragment',
            $this->getUri()->__toString(),
            'The string should be http://user:pass@host:81/path?query=string#fragment'
        );

        $this->assertSame(
            'http://user:pass@host:81/path',
            (string)(new Uri('http://user:pass@host:81'))->withPath('path'),
            'The string should be http://user:pass@host:81/path'
        );

        $this->assertSame(
            'http:/path',
            (string)(new Uri('http://host:81'))->withHost('')->withPath('//path'),
            'The string should be http:/path'
        );
    }

    public function test_customUrls()
    {
        foreach ($this->customUrls as $url) {
            $this->assertSame($url, (string)(new Uri($url)), "The URL should be $url");
        }
    }
}
