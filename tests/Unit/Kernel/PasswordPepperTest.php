<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Exception\ConfigException;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Security\SecretKeyFile;
use PHPUnit\Framework\TestCase;

/** External pepper rotation preserves verification without exporting key material into the hash. */
final class PasswordPepperTest extends TestCase
{
    public function testLegacyLoginRequiresUpgradeAndWrongPepperCannotVerify(): void
    {
        $legacy = new PasswordHasher(1024, 1);
        $current = new PasswordHasher(1024, 1, 1, str_repeat('a', 32));
        $old = $legacy->hash('synthetic-password-fixture');
        self::assertTrue($current->verify('synthetic-password-fixture', $old));
        self::assertTrue($current->needsRehash($old));
        $hash = $current->hash('synthetic-password-fixture');
        self::assertTrue($current->verify('synthetic-password-fixture', $hash));
        self::assertFalse($current->verify('wrong-password', $hash));
        self::assertFalse((new PasswordHasher(1024, 1, 1, str_repeat('b', 32)))->verify('synthetic-password-fixture', $hash));
        self::assertFalse($legacy->verify('synthetic-password-fixture', $hash));
        self::assertFalse($current->needsRehash($hash));
        self::assertStringNotContainsString(str_repeat('a', 32), $hash);
    }

    public function testRotationVerifiesOldKeyButRehashesWithCurrentKey(): void
    {
        $previous = new PasswordHasher(1024, 1, 1, str_repeat('a', 32), 'old');
        $rotated = new PasswordHasher(1024, 1, 1, str_repeat('b', 32), 'new', ['old' => str_repeat('a', 32)]);
        $hash = $previous->hash('synthetic-password-fixture');
        self::assertTrue($rotated->verify('synthetic-password-fixture', $hash));
        self::assertTrue($rotated->needsRehash($hash));
        self::assertFalse($rotated->verify('synthetic-password-fixture', 'pepper:v1:unknown:$argon2id$invalid'));
        self::assertFalse($rotated->verify('synthetic-password-fixture', 'pepper:v0:old:invalid'));
        self::assertFalse($rotated->needsRehash($rotated->hash('synthetic-password-fixture')));
    }

    public function testExternalFileMustBePrivateAndMustNotBeASymlink(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pepper-unit-');
        self::assertNotFalse($file);
        $link = $file . '-link';
        try {
            file_put_contents($file, base64_encode(str_repeat('c', 32)));
            chmod($file, 0600);
            self::assertSame(str_repeat('c', 32), SecretKeyFile::read($file));
            symlink($file, $link);
            try {
                SecretKeyFile::read($link);
                self::fail('Symlinks must be refused.');
            } catch (ConfigException $e) {
                self::assertStringNotContainsString($link, $e->getMessage());
            }
            chmod($file, 0644);
            clearstatcache(true, $file);
            $this->expectException(ConfigException::class);
            SecretKeyFile::read($file);
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            unlink($file);
        }
    }
}
