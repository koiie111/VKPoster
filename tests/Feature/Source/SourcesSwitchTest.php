<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\Role;
use App\Kernel\Http\Request;
use App\Tests\Support\TestEnv;
use App\Tests\Support\WorkspaceTestCase;

/** Sources and the radar are off unless SOURCES_ENABLED is true (ADR 0011). */
final class SourcesSwitchTest extends WorkspaceTestCase
{
    private const SECRET = 'source-reader-test-secret-not-real-123456789';

    public function testNobodyHoldsSourcePermissionsWhileTheFeatureIsOff(): void
    {
        $off = new Permissions(TestEnv::config(['SOURCES_ENABLED' => '0'])->get('permissions'));
        $on = new Permissions(TestEnv::config(['SOURCES_ENABLED' => '1'])->get('permissions'));

        foreach (['sources.view', 'sources.manage', 'discovery.view', 'discovery.manage'] as $permission) {
            self::assertFalse($off->allows(Role::Owner, $permission), $permission);
            self::assertTrue($on->allows(Role::Owner, $permission), $permission);
        }
        self::assertTrue($off->allows(Role::Owner, 'posts.publish'), 'other permissions are unaffected');
    }

    public function testReaderIngressIsNotFoundWhileTheFeatureIsOff(): void
    {
        $app = TestEnv::app(['SOURCES_ENABLED' => '0', 'SOURCES_READER_SECRET' => self::SECRET]);
        $request = Request::create('GET', '/internal/sources', headers: ['Authorization' => 'Bearer ' . self::SECRET, 'Accept' => 'application/json']);

        self::assertSame(404, $app->handle($request)->status);
    }
}
