<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Http\Controllers\Dev\DevLoginController;
use App\Kernel\Exception\ConfigException;
use App\Kernel\Http\Request;
use App\Tests\Support\AuthTestCase;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `/dev/login-as/{id}` exists only for local development and tests.
 */
#[CoversClass(DevLoginController::class)]
final class DevLoginTest extends AuthTestCase
{
    public function testSignsInAsTheGivenUserInTestingAndLocal(): void
    {
        $user = $this->createUser();

        $response = $this->get('/dev/login-as/' . $user->id);

        self::assertSame('/app', $response->header('Location'));
        self::assertSame(200, $this->get('/app')->status);
    }

    public function testAcceptsAnEmailAddressToo(): void
    {
        $this->createUser('demo@example.com');

        self::assertSame('/app', $this->get('/dev/login-as/demo@example.com')->header('Location'));
        self::assertSame('/app', $this->get('/dev/login-as/demo%40example.com')->header('Location'));
        self::assertSame(404, $this->get('/dev/login-as/nobody@example.com')->status);
        self::assertSame(404, $this->get('/dev/login-as/not-an-id')->status);
    }

    public function testUnknownUsersAre404(): void
    {
        self::assertSame(404, $this->get('/dev/login-as/999999')->status);
    }

    public function testCanBeSwitchedOffWithTheFlag(): void
    {
        $user = $this->createUser();
        $app = TestEnv::app(['DEV_LOGIN' => '0']);

        $response = $app->handle(Request::create('GET', '/dev/login-as/' . $user->id, headers: ['Host' => 'localhost'], server: ['REMOTE_ADDR' => '203.0.113.10']));

        self::assertSame(404, $response->status);
    }

    public function testIsNotAvailableInProduction(): void
    {
        $user = $this->createUser();
        $app = TestEnv::app(['APP_ENV' => 'production']);

        $response = $app->handle(Request::create('GET', '/dev/login-as/' . $user->id, headers: ['Host' => 'localhost'], server: ['REMOTE_ADDR' => '203.0.113.10']));

        self::assertSame(404, $response->status);
        self::assertNull($response->header('Location'));
    }

    public function testProductionRefusesToBootWithDevLoginEnabled(): void
    {
        $this->expectException(ConfigException::class);

        TestEnv::app(['APP_ENV' => 'production', 'DEV_LOGIN' => '1']);
    }
}
