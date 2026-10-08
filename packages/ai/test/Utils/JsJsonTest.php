<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\JsJson;

/**
 * V8's `JSON.parse` messages and `JSON.stringify` output, as upstream's code surfaces them.
 *
 * Every expected message here is real `node -e` output (Node 22, the engine pi pins), produced
 * by running `JSON.parse` over the input on the left — not written by hand. `JsJson` was also
 * swept against node over some fifteen thousand mutated and truncated JSON texts (structural
 * damage, control characters, non-BMP characters, CR/LF mixes, invalid UTF-8) with no difference
 * but the one its docblock names: a lone surrogate half in V8's message is U+FFFD here.
 */
final class JsJsonTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function v8Messages(): iterable
    {
        yield 'end of input' => ["", "Unexpected end of JSON input"];
        yield 'end inside an array' => ["[1,", "Unexpected end of JSON input"];
        yield 'end inside a literal' => ["tru", "Unexpected end of JSON input"];
        yield 'backslash at the end' => ["\"ab\\", "Unexpected end of JSON input"];
        yield 'property name expected' => ["{", "Expected property name or '}' in JSON at position 1 (line 1 column 2)"];
        yield 'unquoted key' => ["{a:1}", "Expected property name or '}' in JSON at position 1 (line 1 column 2)"];
        yield 'colon expected' => ["{\"a\" 1}", "Expected ':' after property name in JSON at position 5 (line 1 column 6)"];
        yield 'comma or brace expected' => ["{\"a\":1 \"b\":2}", "Expected ',' or '}' after property value in JSON at position 7 (line 1 column 8)"];
        yield 'end after a property value' => ["{\"a\":1", "Expected ',' or '}' after property value in JSON at position 6 (line 1 column 7)"];
        yield 'trailing comma in an object' => ["{\"a\":1,}", "Expected double-quoted property name in JSON at position 7 (line 1 column 8)"];
        yield 'comma or bracket expected' => ["[1 2]", "Expected ',' or ']' after array element in JSON at position 3 (line 1 column 4)"];
        yield 'trailing comma in an array' => ["[1,]", "Unexpected token ']', \"[1,]\" is not valid JSON"];
        yield 'unterminated string' => ["\"abc", "Unterminated string in JSON at position 4 (line 1 column 5)"];
        yield 'bad control character' => ["{\"a\":\"x\ty\"}", "Bad control character in string literal in JSON at position 7 (line 1 column 8)"];
        yield 'bad escape' => ["\"\\x\"", "Bad escaped character in JSON at position 2 (line 1 column 3)"];
        yield 'bad unicode escape' => ["\"\\u12g4\"", "Bad Unicode escape in JSON at position 5 (line 1 column 6)"];
        yield 'escape past Latin-1' => ["\"\\\u{4E2D}\"", "Unexpected token '\u{4E2D}', \"\"\\\u{4E2D}\"\" is not valid JSON"];
        yield 'no number after minus' => ["-a", "No number after minus sign in JSON at position 1 (line 1 column 2)"];
        yield 'leading zero' => ["01", "Unexpected number in JSON at position 1 (line 1 column 2)"];
        yield 'unterminated fraction' => ["1.", "Unterminated fractional number in JSON at position 2 (line 1 column 3)"];
        yield 'exponent without digits' => ["1e+", "Exponent part is missing a number in JSON at position 3 (line 1 column 4)"];
        yield 'literal broken by a digit' => ["tru1", "Unexpected number in JSON at position 3 (line 1 column 4)"];
        yield 'literal broken by a quote' => ["nul\"", "Unexpected string in JSON at position 3 (line 1 column 4)"];
        yield 'non-whitespace after JSON' => ["{\"a\":1}x", "Unexpected non-whitespace character after JSON at position 7 (line 1 column 8)"];
        yield 'two values' => ["{\"a\":1}{\"b\":2}", "Unexpected non-whitespace character after JSON at position 7 (line 1 column 8)"];
        yield 'short source' => ["hello", "Unexpected token 'h', \"hello\" is not valid JSON"];
        yield 'long source, token near the start' => ["hello world, this is not JSON", "Unexpected token 'h', \"hello worl\"... is not valid JSON"];
        yield 'long source, token in the middle' => ["{\"key\": \"value\", \"other\": wrong}", "Unexpected token 'w', ...\" \"other\": wrong}\" is not valid JSON"];
        yield 'long source, token near the end' => ["{\"aaaaaaaaaaaaaaaaaa\": x}", "Unexpected token 'x', ...\"aaaaaaa\": x}\" is not valid JSON"];
        yield 'position on a later line' => ["{\n  \"a\": 1,\r\n  \"b\": x\n}", "Unexpected token 'x', ...\",\r\n  \"b\": x\n}\" is not valid JSON"];
        yield 'expected-colon on a later line' => ["{\n  \"a\": 1,\r\n  \"b\" 2\n}", "Expected ':' after property name in JSON at position 19 (line 3 column 7)"];
        yield 'CR-only line breaks' => ["{\r\"a\":\r1\r\"b\"}", "Expected ',' or '}' after property value in JSON at position 9 (line 4 column 1)"];
        yield 'undefined' => ["undefined", "\"undefined\" is not valid JSON"];
        yield 'NaN' => ["NaN", "\"NaN\" is not valid JSON"];
        yield 'HTML error page' => ["<html><body>502 Bad Gateway</body></html>", "Unexpected token '<', \"<html><bod\"... is not valid JSON"];
        yield 'UTF-16 position after a non-BMP character' => ["{\"a\":\"\u{1F600}\"x}", "Expected ',' or '}' after property value in JSON at position 9 (line 1 column 10)"];
    }

    #[DataProvider('v8Messages')]
    public function testTheMessageIsV8s(string $json, string $message): void
    {
        $this->assertSame($message, JsJson::syntaxError($json));
    }

    #[DataProvider('v8Messages')]
    public function testParseThrowsV8sMessage(string $json, string $message): void
    {
        try {
            JsJson::parse($json);
            $this->fail('parsed');
        } catch (JsonException $error) {
            $this->assertSame($message, $error->getMessage());
        }
    }

    public function testValidJsonHasNoMessageAndParses(): void
    {
        $this->assertNull(JsJson::syntaxError(" {\"a\": [1, -2.5e3, true, null, \"x\\u00e9\"]} \r\n"));
        $this->assertSame(['a' => [1, -2500.0, true, null, "x\u{E9}"]], JsJson::parse('{"a": [1, -2.5e3, true, null, "x\\u00e9"]}'));
    }

    public function testWhatV8AcceptsAndPhpDoesNotStillParses(): void
    {
        // An escaped lone surrogate is a valid JSON string to V8; PHP refuses it. The half becomes
        // U+FFFD, which is what V8's string turns into once it is printed as UTF-8.
        $this->assertSame("a\u{FFFD}b", JsJson::parse('"a\\ud800b"'));
        $this->assertSame("\u{1F600}", JsJson::parse('"\\ud83d\\ude00"'));
        // Bytes that are not UTF-8 read as `TextDecoder` reads them.
        $this->assertSame(['k' => "\u{FFFD}"], JsJson::parse("{\"k\":\"\xff\"}"));
    }

    public function testNotUtf8ReadsAsReplacementCharacters(): void
    {
        // node: Buffer.from([0x5b, 0xff, 0x5d]).toString() is "[\ufffd]", and JSON.parse of that says
        // the token is the replacement character.
        $this->assertSame("Unexpected token '\u{FFFD}', \"[\u{FFFD}]\" is not valid JSON", JsJson::syntaxError("[\xff]"));
    }

    public function testStringifyWritesWhatJavaScriptWrites(): void
    {
        // node -e 'console.log(JSON.stringify(JSON.parse(…)))' for each.
        $this->assertSame("{\"1\":{},\"2\":2,\"b\":1,\"a\":[],\"s\":\"\u{2028}/\u{E9}\"}", JsJson::stringify(JsJson::parse("{\"b\":1,\"2\":2,\"a\":[],\"1\":{},\"s\":\"\\u2028/\u{E9}\"}", false)));
        $this->assertSame('[1,1e+21,1e-7,0.000001,123.456,-0.5,9007199254740992,1.7976931348623157e+308,5e-324,100000000000000000000]', JsJson::stringify(
            [1.0, 1e21, 1e-7, 0.000001, 123.456, -0.5, 9007199254740993, 1.7976931348623157e308, 5e-324, 1e20],
        ));
    }

    public function testTrimIsJavaScriptsTrim(): void
    {
        // A NUL is not white space to JavaScript; the no-break space and the BOM are.
        $this->assertSame("x\0", JsJson::trim("\u{FEFF}\u{A0} x\0 \u{3000}\n"));
        $this->assertSame('x ', JsJson::trimStart("\t x "));
    }
}
