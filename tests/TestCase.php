<?php

namespace DropboxAPI\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

abstract class TestCase extends BaseTestCase {

    /**
     * @param  array<mixed>  $expectedParams
     */
    public function mockGuzzleRequest(string|StreamInterface|null $expectedResponse, string $expectedEndpoint, array $expectedParams): MockObject {
        $mockResponse = $this->getMockBuilder(ResponseInterface::class)
            ->getMock();

        if ($expectedResponse) {
            if (is_string($expectedResponse)) {
                $mockResponse->expects($this->once())
                    ->method('getBody')
                    ->willReturn($this->createStreamFromString($expectedResponse));
            } else {
                $mockResponse->expects($this->once())
                    ->method('getBody')
                    ->willReturn($expectedResponse);
            }
        }

        $mockGuzzle = $this->getMockBuilder(GuzzleClient::class)
            ->onlyMethods(['request'])
            ->getMock();
        $mockGuzzle->expects($this->once())
            ->method('request')
            ->with('POST', $expectedEndpoint, $expectedParams)
            ->willReturn($mockResponse);

        return $mockGuzzle;
    }

    public function createStreamFromString(string $content): Stream {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, $content);

        return new Stream($resource);
    }
}
