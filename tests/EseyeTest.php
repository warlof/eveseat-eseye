<?php

/*
 * This file is part of SeAT
 *
 * Copyright (C) 2015, 2016, 2017  Leon Jacobs
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace Seat\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use Seat\Eseye\Access\CheckAccess;
use Seat\Eseye\Cache\FileCache;
use Seat\Eseye\Cache\NullCache;
use Seat\Eseye\Configuration;
use Seat\Eseye\Containers\EsiAuthentication;
use Seat\Eseye\Eseye;
use Seat\Eseye\Exceptions\EsiScopeAccessDeniedException;
use Seat\Eseye\Exceptions\InvalidAuthenticationException;
use Seat\Eseye\Exceptions\InvalidContainerDataException;
use Seat\Eseye\Exceptions\UriDataMissingException;
use Seat\Eseye\Fetchers\Fetcher;
use Seat\Eseye\Fetchers\FetcherInterface;
use Seat\Eseye\Log\NullLogger;

class EseyeTest extends TestCase
{

    /**
     * @var Eseye
     */
    protected Eseye $esi;

    public function setUp(): void
    {

        // Remove logging
        $configuration = Configuration::getInstance();
        $configuration->logger = NullLogger::class;

        // Remove caching
        $configuration->cache = NullCache::class;

        // Force ESI data-source to be singularity
        $configuration->datasource = 'singularity';

        // Setup HTTP client
        $configuration->http_client = Client::class;
        $configuration->http_stream_factory = HttpFactory::class;
        $configuration->http_request_factory = HttpFactory::class;

        $this->esi = new Eseye;
    }

    public function testEseyeInstantiation()
    {

        $this->assertInstanceOf(Eseye::class, $this->esi);
    }

    public function testEseyeInstantiateWithInvalidAuthenticationData()
    {

        $this->expectException(InvalidContainerDataException::class);

        $authentication = new EsiAuthentication([
            'foo' => 'bar',
        ]);
        new Eseye($authentication);
    }

    public function testEseyeInstantiateWithValidAuthenticationData()
    {

        $authentication = new EsiAuthentication([
            'client_id'     => 'SSO_CLIENT_ID',
            'secret'        => 'SSO_SECRET',
            'refresh_token' => 'CHARACTER_REFRESH_TOKEN',
        ]);
        $client = new Eseye($authentication);

        $this->assertEquals($authentication, $client->getAuthentication());
    }

    public function testEseyeSetNewInvalidAuthenticationData()
    {

        $this->expectException(InvalidContainerDataException::class);

        $authentication = new EsiAuthentication([
            'foo' => 'bar',
            'baz' => null,
        ]);
        $this->esi->setAuthentication($authentication);
    }

    public function testEseyeSetNewValidAuthenticationData()
    {

        $authentication = new EsiAuthentication([
            'client_id'     => 'SSO_CLIENT_ID',
            'secret'        => 'SSO_SECRET',
            'access_token'  => 'ACCESS_TOKEN',
            'refresh_token' => 'CHARACTER_REFRESH_TOKEN',
            'token_expires' => '1970-01-01 00:00:00',
            'scopes'        => ['public'],
        ]);
        $this->esi->setAuthentication($authentication);

        $this->assertEquals($authentication, $this->esi->getAuthentication());
    }

    public function testEseyeGetAuthenticationBeforeSet()
    {

        $this->expectException(InvalidAuthenticationException::class);

        $this->esi->getAuthentication();
    }

    public function testEseyeGetAuthenticationAfterSet()
    {

        $authentication = new EsiAuthentication([
            'client_id'     => 'SSO_CLIENT_ID',
            'secret'        => 'SSO_SECRET',
            'access_token'  => 'ACCESS_TOKEN',
            'refresh_token' => 'CHARACTER_REFRESH_TOKEN',
            'token_expires' => '1970-01-01 00:00:00',
            'scopes'        => ['public'],
        ]);
        $this->esi->setAuthentication($authentication);

        $this->assertInstanceOf(EsiAuthentication::class, $this->esi->getAuthentication());
    }

    public function testEseyeGetConfigurationInstance()
    {

        $this->assertInstanceOf(Configuration::class, $this->esi->getConfiguration());
    }

    public function testEseyeGetLogger()
    {

        $this->assertInstanceOf(LoggerInterface::class, $this->esi->getLogger());
    }

    public function testEseyeSetAccessChecker()
    {

        $access = $this->createMock(CheckAccess::class);

        $this->assertInstanceOf(Eseye::class, $this->esi->setAccessChecker($access));
    }

    public function testEseyeGetAccessChecker()
    {

        $this->assertInstanceOf(CheckAccess::class, $this->esi->getAccessChecker());
    }

    public function testEseyeGetsFetcher()
    {

        $get_fetcher = self::getMethod('getFetcher');
        $return = $get_fetcher->invokeArgs(new Eseye, []);

        $this->assertInstanceOf(FetcherInterface::class, $return);
    }

    /**
     * Helper method to set private methods public.
     *
     * @param $name
     *
     * @return \ReflectionMethod
     */
    protected static function getMethod($name)
    {
        $class = new ReflectionClass(Eseye::class);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }

    public function testEseyeGetsCache()
    {

        $get_fetcher = self::getMethod('getCache');
        $return = $get_fetcher->invokeArgs(new Eseye, []);

        $this->assertInstanceOf(CacheInterface::class, $return);
    }

    public function testEseyeGetAndSetQueryString()
    {

        $object = $this->esi->setQueryString([
            'foo'    => 'bar',
            'foobar' => ['foo', 'bar'],
        ]);

        $this->assertInstanceOf(Eseye::class, $object);
        $this->assertEquals([
            'foo'    => 'bar',
            'foobar' => 'foo,bar',
        ], $this->esi->getQueryString());
    }

    public function testEseyeGetAndSetBody()
    {

        $object = $this->esi->setBody(['foo']);

        $this->assertInstanceOf(Eseye::class, $object);
        $this->assertEquals(['foo'], $this->esi->getBody());
    }

    public function testEseyeGetDefaultVersionString()
    {

        $version = $this->esi->getVersion();

        $this->assertEquals('/latest', $version);
    }

    public function testEseyeSetIncompleteVersionStringAndGetsCompleteVersionString()
    {

        $this->esi->setVersion('v1');

        $this->assertEquals('/v1', $this->esi->getVersion());
    }

    public function testEseyeReturnsEseyeAfterSettingEsiApiVersion()
    {

        $esi = $this->esi->setVersion('v4');

        $this->assertInstanceOf(Eseye::class, $esi);
    }

    public function testEseyeBuildValidDataUri()
    {

        $uri = $this->esi->buildDataUri('/{foo}/', ['foo' => 'bar']);

        $this->assertEquals('https://esi.evetech.net/latest/bar/?datasource=singularity',
            $uri->__toString());
    }

    public function testEseyeBuildDataUriFailsOnEmptyDataArray()
    {

        $this->expectException(UriDataMissingException::class);

        $this->esi->buildDataUri('/{foo}/', []);
    }

    public function testEseyeBuildDataUriFailsOnIncompleteDataArray()
    {

        $this->expectException(UriDataMissingException::class);

        $this->esi->buildDataUri('/{foo}/', ['bar' => 'baz']);
    }

    public function testEseyeMakesEsiApiCallWithCachedResponse()
    {

        $mock = new MockHandler([
            new Response(200, ['Expires' => 'Sat, 28 Jan 4017 05:46:49 GMT'], json_encode(['foo' => 'bar'])),
        ]);

        $fetcher = new Fetcher;
        $fetcher->setClient(new Client([
            'handler' => HandlerStack::create($mock),
        ]));

        // Update the fetchers client
        $this->esi->setFetcher($fetcher);

        $response = $this->esi->invoke('get', '/foo');

        $this->assertEquals('bar', $response->foo);

    }

    public function testEseyeMakesEsiApiCallWithExpiredCachedResponseAndValidEtag()
    {
        $mock = new MockHandler([
            new Response(200, [
                'Expires' => carbon()->addSeconds(3)->toRfc7231String(),
                'ETag' => 'W/"b3ef78b1064a27974cbf18270c1f126d519f7b467ba2e35ccb6f0819"',
            ], json_encode(['foo' => 'bar'])),
            new Response(304, [
                'Expires' => carbon()->addHour()->toRfc7231String(),
                'ETag' => 'W/"b3ef78b1064a27974cbf18270c1f126d519f7b467ba2e35ccb6f0819"',
            ]),
        ]);

        $config = Configuration::getInstance();
        $config->cache = FileCache::class;
        $config->file_cache_location = __DIR__ . '/../cache/' . uniqid('', true);

        $fetcher = new Fetcher;
        $fetcher->setClient(new Client([
            'handler' => HandlerStack::create($mock),
        ]));

        // Update the fetchers client
        $this->esi->setFetcher($fetcher);

        // send an initial call to seed cache
        $response = $this->esi->invoke('get', '/foo2');
        $this->assertFalse($response->isCachedLoad());

        sleep(5);

        // send a new call to trigger cache
        $response = $this->esi->invoke('get', '/foo2');
        $this->assertTrue($response->isCachedLoad());
    }

    public function testEseyeMakesEsiApiCallWithoutCachedResponse()
    {

        $mock = new MockHandler([
            new Response(200, ['Foo' => 'Bar'], json_encode(['foo' => 'bar'])),
        ]);

        $fetcher = new Fetcher;
        $fetcher->setClient(new Client([
            'handler' => HandlerStack::create($mock),
        ]));

        // Update the fetchers client
        $this->esi->setFetcher($fetcher);

        $response = $this->esi->invoke('post', '/foo');

        $this->assertEquals('bar', $response->foo);

    }

    public function testEseyeMakesEsiApiCallToAuthenticatedEndpointWithoutAccess()
    {

        $this->expectException(EsiScopeAccessDeniedException::class);

        $mock = new MockHandler([
            new Response(401),
        ]);

        // Update the fetchers client
        $this->esi->setFetcher(new Fetcher(null, new Client([
            'handler' => HandlerStack::create($mock),
        ])));

        $this->esi->invoke('get', '/characters/{character_id}/assets/', [
            'character_id' => 123,
        ]);
    }

    public function testEseyeSetRefreshToken()
    {

        $authentication = new EsiAuthentication([
            'client_id'     => 'SSO_CLIENT_ID',
            'secret'        => 'SSO_SECRET',
            'access_token'  => 'ACCESS_TOKEN',
            'refresh_token' => 'CHARACTER_REFRESH_TOKEN',
            'token_expires' => '1970-01-01 00:00:00',
            'scopes'        => ['public'],
        ]);
        $this->esi->setAuthentication($authentication);

        $this->esi->setRefreshToken('ALTERNATE_REFRESH_TOKEN');

        $this->assertEquals('ALTERNATE_REFRESH_TOKEN', $this->esi->getAuthentication()->refresh_token);
    }

}
