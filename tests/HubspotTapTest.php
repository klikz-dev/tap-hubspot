<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

class HubspotTapTest extends TestCase
{
    public function testHasDesiredMethods()
    {
        $this->assertTrue(method_exists('HubspotTap', 'test'));
        $this->assertTrue(method_exists('HubspotTap', 'discover'));
        $this->assertTrue(method_exists('HubspotTap', 'tap'));
        $this->assertTrue(method_exists('HubspotTap', 'getTables'));
    }
}
