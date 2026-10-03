<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Http\UploadedFile;
use App\Kernel\Validation\ValidationException;
use App\Kernel\Validation\Validator;
use App\Kernel\View\Translator;
use App\Tests\Support\TestEnv;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each validation rule, Russian messages and the validated() contract.
 */
#[CoversClass(Validator::class)]
#[CoversClass(\App\Kernel\Validation\Validation::class)]
#[CoversClass(Translator::class)]
final class ValidatorTest extends TestCase
{
    private function validator(): Validator
    {
        return new Validator(new Translator(TestEnv::basePath() . '/resources/lang'));
    }

    /**
     * @return array<string, array{string, mixed, bool}>
     */
    public static function cases(): array
    {
        return [
            'required ok' => ['required', 'x', true],
            'required blank' => ['required', '  ', false],
            'required null' => ['required', null, false],
            'string ok' => ['string', 'x', true],
            'string int' => ['string', 5, false],
            'int ok' => ['int', '12', true],
            'int float' => ['int', '1.5', false],
            'email ok' => ['email', 'a@b.co', true],
            'email bad' => ['email', 'a@', false],
            'min ok' => ['string|min:3', 'abc', true],
            'min bad' => ['string|min:3', 'ab', false],
            'min multibyte' => ['string|min:3', 'абв', true],
            'max bad' => ['string|max:2', 'abc', false],
            'int min' => ['int|min:5', '4', false],
            'int max' => ['int|max:5', '5', true],
            'in ok' => ['in:a,b', 'a', true],
            'in bad' => ['in:a,b', 'c', false],
            'url ok' => ['url', 'https://example.com/x?y=1', true],
            'url ftp' => ['url', 'ftp://example.com', false],
            'url js' => ['url', 'javascript:alert(1)', false],
            'timezone ok' => ['timezone', 'Europe/Moscow', true],
            'timezone bad' => ['timezone', 'Mars/Base', false],
            'date ok' => ['date', '2026-02-28', true],
            'date impossible' => ['date', '2026-02-30', false],
            'date format' => ['date:Y-m-d H:i', '2026-02-28 10:30', true],
            'array ok' => ['array', [1], true],
            'array bad' => ['array|required', 'x', false],
            'bool ok' => ['bool', '1', true],
            'bool bad' => ['bool', 'maybe', false],
        ];
    }

    #[DataProvider('cases')]
    public function testRule(string $rules, mixed $value, bool $passes): void
    {
        $result = $this->validator()->make(['f' => $value], ['f' => $rules]);

        self::assertSame($passes, !$result->fails(), (string) json_encode($result->errors(), JSON_UNESCAPED_UNICODE));
    }

    public function testMessagesAreRussianAndUseAttributeNames(): void
    {
        $result = $this->validator()->make(['email' => 'bad'], ['email' => 'required|email', 'name' => 'required'], ['email' => 'Почта']);

        self::assertSame('Укажите корректный адрес почты в поле «Почта».', $result->first('email'));
        self::assertSame('Заполните поле «name».', $result->first('name'));
        self::assertNull($result->first('other'));
    }

    public function testOnlyFirstFailingRuleReportedPerField(): void
    {
        $result = $this->validator()->make(['f' => ''], ['f' => 'required|string|min:3']);

        self::assertCount(1, $result->errors()['f']);
    }

    public function testOptionalEmptyFieldSkipsRules(): void
    {
        $result = $this->validator()->make(['f' => ''], ['f' => 'nullable|email']);

        self::assertFalse($result->fails());
    }

    public function testRegexAcceptsPipesViaListForm(): void
    {
        $validator = $this->validator();

        self::assertFalse($validator->make(['f' => 'b'], ['f' => ['regex:/^(a|b)$/']])->fails());
        self::assertTrue($validator->make(['f' => 'c'], ['f' => ['regex:/^(a|b)$/']])->fails());
    }

    public function testConfirmed(): void
    {
        $validator = $this->validator();

        self::assertFalse($validator->make(['p' => 'x', 'p_confirmation' => 'x'], ['p' => 'confirmed'])->fails());
        self::assertTrue($validator->make(['p' => 'x', 'p_confirmation' => 'y'], ['p' => 'confirmed'])->fails());
    }

    public function testFileRulesUseSniffedMimeAndSize(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vk');
        self::assertIsString($path);
        file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $file = new UploadedFile('evil.php', $path, 2056, UPLOAD_ERR_OK);
        $validator = $this->validator();

        self::assertFalse($validator->make(['f' => $file], ['f' => 'file|mimes:image/png|max:10'])->fails());
        self::assertTrue($validator->make(['f' => $file], ['f' => 'file|mimes:image/jpeg'])->fails());
        self::assertTrue($validator->make(['f' => $file], ['f' => 'file|max:1'])->fails());
        self::assertTrue($validator->make(['f' => 'not a file'], ['f' => 'file'])->fails());
        unlink($path);
    }

    public function testValidatedReturnsOnlyRuledFieldsOrThrows(): void
    {
        $validator = $this->validator();

        $ok = $validator->make(['a' => 'x', 'is_admin' => '1'], ['a' => 'required']);
        self::assertSame(['a' => 'x'], $ok->validated());

        $this->expectException(ValidationException::class);
        $validator->make([], ['a' => 'required'])->validated();
    }

    public function testUnknownRuleIsAProgrammerError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator()->make(['a' => 'x'], ['a' => 'nonsense']);
    }

    public function testTranslatorFallsBackToKeyAndReplacesParams(): void
    {
        $t = new Translator(TestEnv::basePath() . '/resources/lang');

        self::assertSame('Привет, Аня', $t->t('Привет, :name', ['name' => 'Аня']));
    }
}
