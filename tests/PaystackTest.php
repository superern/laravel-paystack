<?php

/*
 * This file is part of the Laravel Paystack package.
 *
 * (c) Prosper Otemuyiwa <prosperotemuyiwa@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Superern\Paystack\Test;

use Mockery as m;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Superern\Paystack\Paystack;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade as Facade;

class PaystackTest extends TestCase
{
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

    public function testAllCustomersAreReturned()
    {
        $array = $this->paystack->shouldReceive('getAllCustomers')->andReturn(['prosper']);

        $this->assertEquals('array', gettype(array($array)));
    }

    public function testAllTransactionsAreReturned()
    {
        $array = $this->paystack->shouldReceive('getAllTransactions')->andReturn(['transactions']);

        $this->assertEquals('array', gettype(array($array)));
    }

    public function testAllPlansAreReturned()
    {
        $array = $this->paystack->shouldReceive('getAllPlans')->andReturn(['intermediate-plan']);

        $this->assertEquals('array', gettype(array($array)));
    }
}
