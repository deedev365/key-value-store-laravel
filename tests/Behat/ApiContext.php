<?php

namespace Tests\Behat;

use App\Models\KvEntry;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the API in-process: each scenario boots its own Laravel application
 * against an in-memory SQLite database and dispatches real requests through
 * the HTTP kernel, so middleware, routing and validation all run exactly as
 * they do in production.
 *
 * Values come in two step flavours. The plain one takes a string, which is
 * what most scenarios are about. The "JSON value" one takes a JSON literal in
 * single quotes — 'null', 'false', '0', '""', '[]', '{"a":1}' — because
 * "stores any JSON type verbatim" is itself a property under test, and a step
 * that collapsed null and the string "null" into the same argument would stop
 * testing it. Behat strips the outer quotes, so the single quotes are what
 * keeps the inner JSON intact.
 */
class ApiContext implements Context
{
    private Application $app;

    private ?Response $response = null;

    /**
     * Input, its write response, and the read that followed — filled by the
     * table-driven steps so the assertion can name the payload that failed.
     *
     * @var list<array{0: string, 1: Response, 2: Response|null}>
     */
    private array $attempts = [];

    /**
     * @var list<array{query: string, bindings: array<mixed>, time: float}>
     */
    private array $queryLog = [];

    /**
     * Environment the scenarios run under. Mirrors phpunit.xml so that the
     * two suites cannot drift into testing different configurations.
     *
     * @var array<string, string>
     */
    private const ENVIRONMENT = [
        'APP_ENV' => 'testing',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'BCRYPT_ROUNDS' => '4',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ];

    #[BeforeScenario]
    public function bootTheApplication(): void
    {
        // Carbon's test time is global and outlives the application, so a
        // scenario that pinned the clock must not leave it pinned for the next.
        Carbon::setTestNow();

        foreach (self::ENVIRONMENT as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        // A fresh application per scenario, so container state, the array
        // cache backing the rate limiter and the in-memory database all start
        // empty — the equivalent of RefreshDatabase in the PHPUnit suite.
        $this->app = require __DIR__.'/../../bootstrap/app.php';
        $this->app->make(ConsoleKernel::class)->bootstrap();
        $this->app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);

        $this->response = null;
        $this->attempts = [];
        $this->queryLog = [];
    }

    // ---------------------------------------------------------------
    // Given
    // ---------------------------------------------------------------

    #[Given('the store is empty')]
    public function theStoreIsEmpty(): void
    {
        Assert::assertSame(0, KvEntry::count());
    }

    #[Given('the value :value is stored under the key :key')]
    public function theValueIsStoredUnderTheKey(string $value, string $key): void
    {
        $this->writeBody(json_encode([$key => $value], JSON_UNESCAPED_SLASHES));

        Assert::assertSame(
            201,
            $this->response->getStatusCode(),
            'arrangement write failed: '.$this->response->getContent()
        );
    }

    /**
     * Writes straight to the table, bypassing the API. Recording times are
     * assigned by the server on write, so a scenario about "the value that was
     * current at 6pm" cannot arrange itself over HTTP.
     */
    #[Given('the key :key has the value :value recorded at :timestamp')]
    public function theKeyHasTheValueRecordedAt(string $key, string $value, int $timestamp): void
    {
        KvEntry::create([
            'key' => $key,
            'value' => $value,
            'recorded_at' => $timestamp,
        ]);
    }

    /**
     * A version with an activation time. No write path sets one yet, so like
     * the step above this writes straight to the table.
     */
    #[Given('the key :key has the value :value recorded at :recordedAt and published at :publishTime')]
    public function theKeyHasTheValueRecordedAtAndPublishedAt(string $key, string $value, int $recordedAt, int $publishTime): void
    {
        KvEntry::create([
            'key' => $key,
            'value' => $value,
            'recorded_at' => $recordedAt,
            'publish_time' => $publishTime,
        ]);
    }

    /**
     * Pins the clock the listing compares publish times against. Cleared
     * before every scenario, since Carbon's test time is global.
     */
    #[Given('the clock is at :timestamp')]
    #[When('the clock reaches :timestamp')]
    public function theClockIsAt(int $timestamp): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampUTC($timestamp));
    }

    #[Given('the last version of the key :key was recorded at :timestamp')]
    public function theLastVersionOfTheKeyWasRecordedAt(string $key, int $timestamp): void
    {
        KvEntry::where('key', $key)->orderByDesc('id')->limit(1)
            ->update(['recorded_at' => $timestamp]);
    }

    #[Given('every version of the key :key was recorded at :timestamp')]
    public function everyVersionOfTheKeyWasRecordedAt(string $key, int $timestamp): void
    {
        KvEntry::where('key', $key)->update(['recorded_at' => $timestamp]);
    }

    #[Given(':count keys have been stored')]
    public function keysHaveBeenStored(int $count): void
    {
        foreach (range(1, $count) as $i) {
            KvEntry::create([
                'key' => sprintf('key_%03d', $i),
                'value' => $i,
                'recorded_at' => 1000 + $i,
            ]);
        }
    }

    #[Given(':count keys have been stored with :versions versions each')]
    public function keysHaveBeenStoredWithVersionsEach(int $count, int $versions): void
    {
        foreach (range(1, $count) as $i) {
            foreach (range(1, $versions) as $version) {
                KvEntry::create([
                    'key' => sprintf('key_%03d', $i),
                    'value' => "v{$version}",
                    'recorded_at' => 1000 + $version,
                ]);
            }
        }
    }

    #[Given('the page size is :count records')]
    public function thePageSizeIs(int $count): void
    {
        config(['kvstore.records_per_page' => $count]);
    }

    #[Given('the body size limit is :bytes bytes')]
    public function theBodySizeLimitIs(int $bytes): void
    {
        config(['kvstore.max_body_bytes' => $bytes]);
    }

    #[Given('the value depth limit is :levels levels')]
    public function theValueDepthLimitIs(int $levels): void
    {
        config(['kvstore.max_value_depth' => $levels]);
    }

    #[Given('the request limit is :count requests per minute')]
    public function theRequestLimitIs(int $count): void
    {
        config(['kvstore.max_requests_per_minute' => $count]);
    }

    // ---------------------------------------------------------------
    // When
    // ---------------------------------------------------------------

    #[When('I store the value :value under the key :key')]
    public function iStoreTheValueUnderTheKey(string $value, string $key): void
    {
        $this->writeBody(json_encode([$key => $value], JSON_UNESCAPED_SLASHES));
    }

    #[When('I store the JSON value :json under the key :key')]
    public function iStoreTheJsonValueUnderTheKey(string $json, string $key): void
    {
        $this->writeBody(json_encode([$key => $this->decodeLiteral($json)], JSON_UNESCAPED_SLASHES));
    }

    #[When('I write the body :body')]
    public function iWriteTheBody(string $body): void
    {
        $this->writeBody($body);
    }

    #[When('I write the body:')]
    public function iWriteTheBodyMultiline(PyStringNode $body): void
    {
        $this->writeBody($body->getRaw());
    }

    #[When('I write a body of :bytes bytes')]
    public function iWriteABodyOfBytes(int $bytes): void
    {
        $this->writeBody($this->bodyOfSize($bytes));
    }

    #[When('I write a body of :bytes bytes declaring a Content-Length of :declared')]
    public function iWriteABodyDeclaringAContentLength(int $bytes, string $declared): void
    {
        $this->writeBody($this->bodyOfSize($bytes), ['CONTENT_LENGTH' => $declared]);
    }

    #[When('I write the body :body declaring a Content-Length of :declared')]
    public function iWriteTheBodyDeclaringAContentLength(string $body, string $declared): void
    {
        $this->writeBody($body, ['CONTENT_LENGTH' => $declared]);
    }

    /**
     * A value nested exactly $depth levels, e.g. 3 => [[["x"]]].
     */
    #[When('I write a value nested :depth levels deep')]
    public function iWriteAValueNestedLevelsDeep(int $depth): void
    {
        $this->writeBody('{"deepkey":'.str_repeat('[', $depth).'"x"'.str_repeat(']', $depth).'}');
    }

    #[When('I write an object nested :depth levels deep')]
    public function iWriteAnObjectNestedLevelsDeep(int $depth): void
    {
        $this->writeBody('{"deepkey":'.str_repeat('{"a":', $depth).'1'.str_repeat('}', $depth).'}');
    }

    #[When('I send a GET to :uri from the IP :ip')]
    public function iSendAGetToFromTheIp(string $uri, string $ip): void
    {
        $this->request('GET', $uri, null, ['REMOTE_ADDR' => $ip]);
    }

    #[When('I read the key :key')]
    public function iReadTheKey(string $key): void
    {
        $this->request('GET', '/object/'.$key);
    }

    #[When('I read the key :key at timestamp :timestamp')]
    public function iReadTheKeyAtTimestamp(string $key, string $timestamp): void
    {
        $this->request('GET', '/object/'.$key.'?timestamp='.urlencode($timestamp));
    }

    #[When('I read the key :key with the raw query :query')]
    public function iReadTheKeyWithTheRawQuery(string $key, string $query): void
    {
        $this->request('GET', '/object/'.$key.'?'.$query);
    }

    #[When('I write the body :body as content type :type')]
    public function iWriteTheBodyAsContentType(string $body, string $type): void
    {
        $this->writeBody($body, ['CONTENT_TYPE' => $type]);
    }

    /**
     * A form-encoded POST arrives with its fields in the request bag and an
     * empty raw body, which is what the write path reads — so it must fail the
     * same way an empty body does.
     */
    #[When('I post the form field :field with the value :value')]
    public function iPostTheFormField(string $field, string $value): void
    {
        $request = Request::create('/object', 'POST', [$field => $value], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->response = $this->app->make(HttpKernel::class)->handle($request);
    }

    #[When('I read the key :key with the method override header :method')]
    public function iReadTheKeyWithTheMethodOverrideHeader(string $key, string $method): void
    {
        $this->request('GET', '/object/'.$key, null, [
            'HTTP_X_HTTP_METHOD_OVERRIDE' => $method,
        ]);
    }

    #[When('I read the history of the key :key')]
    public function iReadTheHistoryOfTheKey(string $key): void
    {
        $this->request('GET', '/object/'.$key.'/history');
    }

    #[When('I list all records')]
    public function iListAllRecords(): void
    {
        $this->request('GET', '/object/get_all_records');
    }

    #[When('I list all records on page :page')]
    public function iListAllRecordsOnPage(string $page): void
    {
        $this->request('GET', '/object/get_all_records/'.urlencode($page));
    }

    /**
     * The page has to be cut in SQL. If it were sliced in PHP the query would
     * come back with every row in the table, which is invisible from the
     * response alone — hence the query log.
     */
    #[When('I list all records while recording the queries')]
    public function iListAllRecordsWhileRecordingTheQueries(): void
    {
        $db = $this->app->make('db');

        $db->enableQueryLog();
        $this->request('GET', '/object/get_all_records');
        $this->queryLog = $db->getQueryLog();
        $db->disableQueryLog();
    }

    #[When('I delete the key :key')]
    public function iDeleteTheKey(string $key): void
    {
        $this->request('DELETE', '/object/'.$key);
    }

    #[When('I send a :method request to :uri')]
    public function iSendARequestTo(string $method, string $uri): void
    {
        $this->request($method, $uri);
    }

    #[When('I make :count requests to :uri from the IP :ip')]
    public function iMakeRequestsFromTheIp(int $count, string $uri, string $ip): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->request('GET', $uri, null, ['REMOTE_ADDR' => $ip]);
        }
    }

    #[When('I read the key :key from the IP :ip')]
    public function iReadTheKeyFromTheIp(string $key, string $ip): void
    {
        $this->request('GET', '/object/'.$key, null, ['REMOTE_ADDR' => $ip]);
    }

    #[When('I store the value :value under the key :key from the IP :ip')]
    public function iStoreTheValueUnderTheKeyFromTheIp(string $value, string $key, string $ip): void
    {
        $this->writeBody(
            json_encode([$key => $value], JSON_UNESCAPED_SLASHES),
            ['REMOTE_ADDR' => $ip]
        );
    }

    /**
     * Payload lists arrive as tables rather than as Examples columns because
     * the payloads themselves contain both kinds of quote — `' OR '1'='1` and
     * `" OR ""="` — which a quoted step argument cannot carry. A table cell
     * only has to escape a pipe and a backslash.
     */
    #[When('I store each of these values under the key :key:')]
    public function iStoreEachOfTheseValuesUnderTheKey(string $key, TableNode $payloads): void
    {
        $this->attempts = [];

        foreach ($payloads->getColumn(0) as $payload) {
            $this->writeBody(json_encode([$key => $payload], JSON_UNESCAPED_SLASHES));
            $write = $this->response;

            $this->request('GET', '/object/'.$key);

            $this->attempts[] = [$payload, $write, $this->response];
        }
    }

    #[When('I try to store each of these keys:')]
    public function iTryToStoreEachOfTheseKeys(TableNode $keys): void
    {
        $this->attempts = [];

        foreach ($keys->getColumn(0) as $key) {
            $this->writeBody(json_encode([$key => 'value'], JSON_UNESCAPED_SLASHES));

            $this->attempts[] = [$key, $this->response, null];
        }
    }

    #[When('I try each of these timestamps on the key :key:')]
    public function iTryEachOfTheseTimestampsOnTheKey(string $key, TableNode $timestamps): void
    {
        $this->attempts = [];

        foreach ($timestamps->getColumn(0) as $timestamp) {
            $this->request('GET', '/object/'.$key.'?timestamp='.urlencode($timestamp));

            $this->attempts[] = [$timestamp, $this->response, null];
        }
    }

    #[When('I try each of these page segments:')]
    public function iTryEachOfThesePageSegments(TableNode $pages): void
    {
        $this->attempts = [];

        foreach ($pages->getColumn(0) as $page) {
            $this->request('GET', '/object/get_all_records/'.rawurlencode($page));

            $this->attempts[] = [$page, $this->response, null];
        }
    }

    #[When('I try each of these bodies:')]
    public function iTryEachOfTheseBodies(TableNode $bodies): void
    {
        $this->attempts = [];

        foreach ($bodies->getColumn(0) as $body) {
            $this->writeBody($body);

            $this->attempts[] = [$body, $this->response, null];
        }
    }

    /**
     * Both verbs, because a path that the router refuses for a read must not
     * be reachable for a delete either.
     */
    #[When('I request each of these paths with GET and DELETE:')]
    public function iRequestEachOfThesePathsWithGetAndDelete(TableNode $paths): void
    {
        $this->attempts = [];

        foreach ($paths->getColumn(0) as $path) {
            foreach (['GET', 'DELETE'] as $method) {
                $this->request($method, '/object/'.$path);

                $this->attempts[] = [$method.' '.$path, $this->response, null];
            }
        }
    }

    #[When('I read each of these paths:')]
    public function iReadEachOfThesePaths(TableNode $paths): void
    {
        $this->attempts = [];

        foreach ($paths->getColumn(0) as $path) {
            $this->request('GET', $path);

            $this->attempts[] = [$path, $this->response, null];
        }
    }

    #[When('I write the body :body to :uri')]
    public function iWriteTheBodyTo(string $body, string $uri): void
    {
        $this->request('POST', $uri, $body);
    }

    #[When('I store a value under a key of :length characters')]
    public function iStoreAValueUnderAKeyOfCharacters(int $length): void
    {
        $this->writeBody(json_encode([str_repeat('a', $length) => 'value']));
    }

    // ---------------------------------------------------------------
    // Then
    // ---------------------------------------------------------------

    #[Then('the response status should be :status')]
    public function theResponseStatusShouldBe(int $status): void
    {
        Assert::assertSame(
            $status,
            $this->response->getStatusCode(),
            'body was: '.$this->response->getContent()
        );
    }

    #[Then('the response status should be :first or :second')]
    public function theResponseStatusShouldBeEither(int $first, int $second): void
    {
        Assert::assertContains(
            $this->response->getStatusCode(),
            [$first, $second],
            'body was: '.$this->response->getContent()
        );
    }

    #[Then('the response should be the record :key with the value :value')]
    public function theResponseShouldBeTheRecord(string $key, string $value): void
    {
        $body = $this->json();

        Assert::assertSame($key, $body['key'] ?? null);
        Assert::assertSame($value, $body['value'] ?? null);
    }

    #[Then('the response should be the record :key with the JSON value :json')]
    public function theResponseShouldBeTheRecordWithJsonValue(string $key, string $json): void
    {
        $body = $this->json();

        Assert::assertSame($key, $body['key'] ?? null);
        Assert::assertSame($this->decodeLiteral($json), $body['value'] ?? null);
    }

    #[Then('the response value should be :value')]
    public function theResponseValueShouldBe(string $value): void
    {
        Assert::assertSame($value, $this->json()['value'] ?? null);
    }

    #[Then('the response JSON value should be :json')]
    public function theResponseJsonValueShouldBe(string $json): void
    {
        Assert::assertSame($this->decodeLiteral($json), $this->json()['value'] ?? null);
    }

    #[Then('the response should be exactly:')]
    public function theResponseShouldBeExactly(PyStringNode $json): void
    {
        Assert::assertSame(json_decode($json->getRaw(), true), $this->json());
    }

    #[Then('the response should have the fields :fields')]
    public function theResponseShouldHaveTheFields(string $fields): void
    {
        $body = $this->json();

        foreach (array_map('trim', explode(',', $fields)) as $field) {
            Assert::assertArrayHasKey($field, $body);
        }
    }

    #[Then('the response should carry a message')]
    public function theResponseShouldCarryAMessage(): void
    {
        $body = $this->json();

        Assert::assertArrayHasKey('message', $body);
        Assert::assertNotSame('', $body['message']);
    }

    #[Then('the response message should be :message')]
    public function theResponseMessageShouldBe(string $message): void
    {
        Assert::assertSame($message, $this->json()['message'] ?? null);
    }

    #[Then('the response should report a validation error for :field')]
    public function theResponseShouldReportAValidationErrorFor(string $field): void
    {
        $body = $this->json();

        Assert::assertArrayHasKey('errors', $body);
        Assert::assertArrayHasKey($field, $body['errors']);
    }

    #[Then('the response should list the keys :keys')]
    public function theResponseShouldListTheKeys(string $keys): void
    {
        $expected = $keys === '' ? [] : array_map('trim', explode(',', $keys));

        Assert::assertSame($expected, array_column($this->json(), 'key'));
    }

    #[Then('the response should list :count records')]
    public function theResponseShouldListRecords(int $count): void
    {
        Assert::assertCount($count, $this->json());
    }

    #[Then('the response should list the values :values')]
    public function theResponseShouldListTheValues(string $values): void
    {
        $expected = array_map('trim', explode(',', $values));

        Assert::assertSame($expected, array_column($this->json(), 'value'));
    }

    #[Then('the response body should be empty')]
    public function theResponseBodyShouldBeEmpty(): void
    {
        Assert::assertSame('', $this->response->getContent());
    }

    #[Then('the response header :header should be :value')]
    public function theResponseHeaderShouldBe(string $header, string $value): void
    {
        Assert::assertSame($value, $this->response->headers->get($header));
    }

    #[Then('the response should carry the header :header')]
    public function theResponseShouldCarryTheHeader(string $header): void
    {
        Assert::assertTrue(
            $this->response->headers->has($header),
            "header {$header} is missing"
        );
    }

    #[Then('the store should hold :count records')]
    public function theStoreShouldHoldRecords(int $count): void
    {
        Assert::assertSame($count, KvEntry::count());
    }

    #[Then('the key :key should have :count versions')]
    public function theKeyShouldHaveVersions(string $key, int $count): void
    {
        Assert::assertSame($count, KvEntry::where('key', $key)->count());
    }

    #[Then('the stored value for the key :key should be :value')]
    public function theStoredValueForTheKeyShouldBe(string $key, string $value): void
    {
        $entry = KvEntry::where('key', $key)->orderByDesc('id')->first();

        Assert::assertNotNull($entry, "no record stored under {$key}");
        Assert::assertSame($value, $entry->value);
    }

    #[Then('the table :table should still exist')]
    public function theTableShouldStillExist(string $table): void
    {
        Assert::assertTrue($this->app->make('db')->getSchemaBuilder()->hasTable($table));
    }

    #[Then('the response should be an empty array')]
    public function theResponseShouldBeAnEmptyArray(): void
    {
        Assert::assertSame([], $this->json());
    }

    #[Then('the record at position :index should be :key with the value :value')]
    public function theRecordAtPositionShouldBe(int $index, string $key, string $value): void
    {
        $body = $this->json();

        Assert::assertArrayHasKey($index, $body, "no record at position {$index}");
        Assert::assertSame($key, $body[$index]['key']);
        Assert::assertSame($value, $body[$index]['value']);
    }

    #[Then('the record at position :index should be :key with the JSON value :json')]
    public function theRecordAtPositionShouldBeWithJsonValue(int $index, string $key, string $json): void
    {
        $body = $this->json();

        Assert::assertArrayHasKey($index, $body, "no record at position {$index}");
        Assert::assertSame($key, $body[$index]['key']);
        Assert::assertSame($this->decodeLiteral($json), $body[$index]['value']);
    }

    #[Then('the response should contain the record :key with the value :value')]
    public function theResponseShouldContainTheRecord(string $key, string $value): void
    {
        $match = array_filter(
            $this->json(),
            fn (array $record): bool => $record['key'] === $key && $record['value'] === $value
        );

        Assert::assertNotEmpty($match, "no record {$key}={$value} in ".$this->response->getContent());
    }

    #[Then('the response should not contain the value :value')]
    public function theResponseShouldNotContainTheValue(string $value): void
    {
        Assert::assertNotContains($value, array_column($this->json(), 'value'));
    }

    #[Then('the response body should contain :needle')]
    public function theResponseBodyShouldContain(string $needle): void
    {
        Assert::assertStringContainsString($needle, (string) $this->response->getContent());
    }

    #[Then('the response body should not contain :needle')]
    public function theResponseBodyShouldNotContain(string $needle): void
    {
        Assert::assertStringNotContainsString($needle, (string) $this->response->getContent());
    }

    #[Then('the response should not carry the header :header')]
    public function theResponseShouldNotCarryTheHeader(string $header): void
    {
        Assert::assertNull($this->response->headers->get($header));
    }

    #[Then('every value should round-trip unchanged')]
    public function everyValueShouldRoundTripUnchanged(): void
    {
        Assert::assertNotEmpty($this->attempts, 'no values were written');

        foreach ($this->attempts as [$payload, $write, $read]) {
            Assert::assertSame(201, $write->getStatusCode(), "write refused for: {$payload}");
            Assert::assertSame(
                $payload,
                json_decode((string) $write->getContent(), true)['value'] ?? null,
                "value changed on write for: {$payload}"
            );

            Assert::assertSame(200, $read->getStatusCode(), "read failed for: {$payload}");
            Assert::assertSame(
                $payload,
                json_decode((string) $read->getContent(), true)['value'] ?? null,
                "value changed on read for: {$payload}"
            );
        }
    }

    #[Then('every attempt should be refused with status :status')]
    public function everyAttemptShouldBeRefusedWith(int $status): void
    {
        Assert::assertNotEmpty($this->attempts, 'no attempts were made');

        foreach ($this->attempts as [$input, $response]) {
            Assert::assertSame($status, $response->getStatusCode(), "unexpected status for: {$input}");
        }
    }

    #[Then('every attempt should return status :status')]
    public function everyAttemptShouldReturnStatus(int $status): void
    {
        $this->everyAttemptShouldBeRefusedWith($status);
    }

    #[Then('every attempt should be refused with status :first or :second')]
    public function everyAttemptShouldBeRefusedWithEither(int $first, int $second): void
    {
        Assert::assertNotEmpty($this->attempts, 'no attempts were made');

        foreach ($this->attempts as [$input, $response]) {
            Assert::assertContains(
                $response->getStatusCode(),
                [$first, $second],
                "unexpected status for: {$input}"
            );
        }
    }

    #[Then('the response value should be a string')]
    public function theResponseValueShouldBeAString(): void
    {
        Assert::assertIsString($this->json()['value'] ?? null);
    }

    #[Then('every stored value should still be a string')]
    public function everyStoredValueShouldStillBeAString(): void
    {
        Assert::assertNotEmpty($this->attempts, 'no values were written');

        foreach ($this->attempts as [$payload, , $read]) {
            Assert::assertIsString(
                json_decode((string) $read->getContent(), true)['value'] ?? null,
                "value came back as something other than a string for: {$payload}"
            );
        }
    }

    #[Then('the id of the first record should not be :value')]
    public function theIdOfTheFirstRecordShouldNotBe(string $value): void
    {
        Assert::assertNotSame($value, KvEntry::query()->orderBy('id')->first()?->id);
    }

    #[Then('every attempt should be refused with status :status and a validation error for :field')]
    public function everyAttemptShouldBeRefusedWithAValidationError(int $status, string $field): void
    {
        Assert::assertNotEmpty($this->attempts, 'no attempts were made');

        foreach ($this->attempts as [$input, $response]) {
            Assert::assertSame($status, $response->getStatusCode(), "unexpected status for: {$input}");

            $body = json_decode((string) $response->getContent(), true);

            Assert::assertArrayHasKey('errors', $body ?? [], "no errors for: {$input}");
            Assert::assertArrayHasKey($field, $body['errors'], "no {$field} error for: {$input}");
        }
    }

    #[Then('every response should carry the header :header set to :value')]
    public function everyResponseShouldCarryTheHeaderSetTo(string $header, string $value): void
    {
        Assert::assertNotEmpty($this->attempts, 'no requests were made');

        foreach ($this->attempts as [$input, $response]) {
            Assert::assertSame($value, $response->headers->get($header), "wrong {$header} for: {$input}");
        }
    }

    #[Then('no response body should contain :needle')]
    public function noResponseBodyShouldContain(string $needle): void
    {
        Assert::assertNotEmpty($this->attempts, 'no requests were made');

        foreach ($this->attempts as [$input, $response]) {
            Assert::assertStringNotContainsString($needle, (string) $response->getContent(), "in: {$input}");
        }
    }

    #[Then('the retry_after field should be between :min and :max seconds')]
    public function theRetryAfterFieldShouldBeBetween(int $min, int $max): void
    {
        $seconds = $this->json()['retry_after'] ?? null;

        Assert::assertIsInt($seconds);
        Assert::assertGreaterThanOrEqual($min, $seconds);
        Assert::assertLessThanOrEqual($max, $seconds);
    }

    #[Then('the refusal message should quote the retry_after field')]
    public function theRefusalMessageShouldQuoteTheRetryAfterField(): void
    {
        $body = $this->json();

        Assert::assertSame(
            "Too many requests. Try again in {$body['retry_after']} seconds.",
            $body['message'] ?? null
        );
    }

    #[Then('the Retry-After header should equal the retry_after field')]
    public function theRetryAfterHeaderShouldEqualTheField(): void
    {
        Assert::assertSame(
            (int) $this->response->headers->get('Retry-After'),
            $this->json()['retry_after'] ?? null
        );
    }

    #[Then('the listing query should be cut in SQL')]
    public function theListingQueryShouldBeCutInSql(): void
    {
        $select = null;

        foreach ($this->queryLog as $query) {
            if (str_contains($query['query'], 'kv_entries')) {
                $select = $query['query'];

                break;
            }
        }

        Assert::assertNotNull($select, 'no query against kv_entries was recorded');
        Assert::assertStringContainsString('limit', strtolower($select));
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * @param  array<string, string>  $server
     */
    private function writeBody(string $body, array $server = []): void
    {
        $this->request('POST', '/object', $body, $server);
    }

    /**
     * @param  array<string, string>  $server
     */
    private function request(string $method, string $uri, ?string $content = null, array $server = []): void
    {
        $request = Request::create($uri, $method, [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server), $content);

        $this->response = $this->app->make(HttpKernel::class)->handle($request);
    }

    /**
     * A body of exactly $bytes total, padding the value to fit.
     */
    private function bodyOfSize(int $bytes): string
    {
        $envelope = strlen('{"k":""}');

        return '{"k":"'.str_repeat('a', max(0, $bytes - $envelope)).'"}';
    }

    /**
     * Feature files spell values as JSON literals — null, false, 0, "", [],
     * {"a":1} — so that a scenario states the type it means rather than the
     * string that happens to look like it.
     */
    private function decodeLiteral(string $json): mixed
    {
        $decoded = json_decode($json, true);

        Assert::assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            "step argument is not a JSON literal: {$json}"
        );

        return $decoded;
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->response->getContent(), true);

        Assert::assertIsArray($decoded, 'response body is not JSON: '.$this->response->getContent());

        return $decoded;
    }
}
