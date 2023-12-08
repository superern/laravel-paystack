<?php

namespace Superern\Paystack\Test;

use Mockery as m;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase {

    protected $paystack;

    public function setUp(): void
    {
        $this->paystack = m::mock('Superern\Paystack\Paystack');
        $this->mock = m::mock('GuzzleHttp\Client');
    }

    public function tearDown(): void
    {
        m::close();
    }

    /**
     * Tests that helper returns
     *
     * @test
     * @return void
     */
    function it_returns_instance_of_paystack () {

        $this->assertInstanceOf("Superern\Paystack\Paystack", $this->paystack);
    }
}
