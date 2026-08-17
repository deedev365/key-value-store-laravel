Feature: Hostile input on every entry point
  Two distinct guarantees run through this file.

  A KEY is a constrained identifier. It reaches the URL path, the route regex
  and error messages, so anything outside [A-Za-z0-9_.-] must be refused — 422
  on a write, 404 on a read, where the route simply never matches.

  A VALUE is opaque data. It must be accepted no matter what it spells, stored
  byte for byte and returned unchanged — never parsed, evaluated, concatenated
  into SQL or emitted as markup.

  Payload lists are tables rather than Examples columns because the payloads
  carry both kinds of quote, which a quoted step argument cannot hold.

  # ---------------------------------------------------------------
  # SQL injection
  # ---------------------------------------------------------------

  Scenario: SQL payloads in a value are stored as inert data
    When I store each of these values under the key "sqlkey":
      | ' OR '1'='1                                     |
      | admin'--                                        |
      | '; DROP TABLE kv_entries; --                    |
      | 1); DELETE FROM kv_entries WHERE ('1'='1        |
      | ' UNION SELECT name, sql FROM sqlite_master --  |
      | \\' OR 1=1 --                                   |
      | " OR ""="                                       |
      | ' AND (SELECT COUNT(*) FROM kv_entries) > 0 --  |
      | ; PRAGMA writable_schema = 1; --                |
      | 0x27 OR 1=1                                     |
    Then every value should round-trip unchanged
    And the table "kv_entries" should still exist

  Scenario: SQL payloads in a key are refused
    When I try to store each of these keys:
      | ' OR '1'='1                                     |
      | admin'--                                        |
      | '; DROP TABLE kv_entries; --                    |
      | 1); DELETE FROM kv_entries WHERE ('1'='1        |
      | ' UNION SELECT name, sql FROM sqlite_master --  |
      | \\' OR 1=1 --                                   |
      | " OR ""="                                       |
      | ' AND (SELECT COUNT(*) FROM kv_entries) > 0 --  |
      | ; PRAGMA writable_schema = 1; --                |
      | 0x27 OR 1=1                                     |
    Then every attempt should be refused with status 422 and a validation error for "key"
    And the store should hold 0 records
    And the table "kv_entries" should still exist

  Scenario: SQL payloads in the timestamp parameter are refused
    Given the value "value" is stored under the key "mykey"
    When I try each of these timestamps on the key "mykey":
      | ' OR '1'='1                                     |
      | admin'--                                        |
      | '; DROP TABLE kv_entries; --                    |
      | 1); DELETE FROM kv_entries WHERE ('1'='1        |
      | ' UNION SELECT name, sql FROM sqlite_master --  |
      | \\' OR 1=1 --                                   |
      | " OR ""="                                       |
      | ' AND (SELECT COUNT(*) FROM kv_entries) > 0 --  |
      | ; PRAGMA writable_schema = 1; --                |
      | 0x27 OR 1=1                                     |
    Then every attempt should be refused with status 422 and a validation error for "timestamp"

  Scenario: SQL payloads in the page segment never reach the controller
    {page} is constrained to \d+, so a payload is refused by the router.

    When I try each of these page segments:
      | ' OR '1'='1                                     |
      | admin'--                                        |
      | '; DROP TABLE kv_entries; --                    |
      | 1); DELETE FROM kv_entries WHERE ('1'='1        |
      | ' UNION SELECT name, sql FROM sqlite_master --  |
      | \\' OR 1=1 --                                   |
      | " OR ""="                                       |
      | ' AND (SELECT COUNT(*) FROM kv_entries) > 0 --  |
      | ; PRAGMA writable_schema = 1; --                |
      | 0x27 OR 1=1                                     |
    Then every attempt should be refused with status 404

  Scenario: A DROP TABLE payload leaves existing rows intact
    Given the value "still here" is stored under the key "keep_me"
    When I store the value "'; DROP TABLE kv_entries; --" under the key "other"
    Then the response status should be 201
    And the table "kv_entries" should still exist
    When I read the key "keep_me"
    Then the response status should be 200
    And the response value should be "still here"

  Scenario: A stacked DELETE payload does not remove other keys
    Given the value "intact" is stored under the key "victim"
    When I store the value "1); DELETE FROM kv_entries; --" under the key "attacker"
    Then the response status should be 201
    And the store should hold 2 records
    When I read the key "victim"
    Then the response value should be "intact"

  Scenario: A key lookup matching a SQL wildcard is an exact match
    where('key', $key) is an equality comparison, not LIKE — '%' and '_' must
    not behave as wildcards.

    Given the value "value" is stored under the key "secret"
    When I read the key "_______"
    Then the response status should be 404
    When I read the key "history"
    Then the response status should be 404

  # ---------------------------------------------------------------
  # PHP: code execution and object injection
  # ---------------------------------------------------------------

  Scenario: PHP payloads in a value are never evaluated
    Each value comes back as the literal string, not as the result of
    evaluating, unserialising or resolving a stream wrapper.

    When I store each of these values under the key "phpkey":
      | <?php system("whoami"); ?>                              |
      | <?= `id` ?>                                             |
      | O:8:"stdClass":1:{s:4:"prop";s:3:"bad";}                |
      | a:1:{s:3:"key";s:5:"value";}                            |
      | phar://evil.phar/payload.txt                            |
      | data://text/plain;base64,PD9waHAgcGhwaW5mbygpOw==       |
      | php://filter/convert.base64-encode/resource=.env        |
      | {{ config("app.key") }}                                 |
      | {!! system("id") !!}                                    |
      | ${@print(md5(1))}                                       |
    Then every value should round-trip unchanged
    And every stored value should still be a string

  Scenario: A serialized payload is not turned into an object
    Read straight from the model, so this proves the storage layer rather than
    just the JSON response.

    When I store the value 'O:8:"stdClass":1:{s:4:"prop";s:3:"bad";}' under the key "ser"
    Then the response status should be 201
    And the stored value for the key "ser" should be 'O:8:"stdClass":1:{s:4:"prop";s:3:"bad";}'

  Scenario: Shell metacharacters in a value are stored verbatim
    When I store each of these values under the key "shellkey":
      | ; rm -rf /tmp/x       |
      | \| cat /etc/passwd    |
      | && whoami             |
      | `whoami`              |
      | $(whoami)             |
      | value\nwhoami         |
      | & dir C:\\            |
    Then every value should round-trip unchanged

  Scenario: Shell metacharacters in a key are refused
    When I try to store each of these keys:
      | ; rm -rf /tmp/x       |
      | \| cat /etc/passwd    |
      | && whoami             |
      | `whoami`              |
      | $(whoami)             |
      | value\nwhoami         |
      | & dir C:\\            |
    Then every attempt should be refused with status 422 and a validation error for "key"

  # ---------------------------------------------------------------
  # Path traversal
  # ---------------------------------------------------------------

  Scenario: Traversal keys are refused on write
    When I try to store each of these keys:
      | ../../etc/passwd         |
      | ..\\..\\windows\\win.ini |
      | /etc/passwd              |
      | C:\\windows\\win.ini     |
      | ./.env                   |
      | ../.env                  |
    Then every attempt should be refused with status 422 and a validation error for "key"

  Scenario: Encoded traversal paths never reach the controller
    '%' is outside the key charset, so the route regex refuses the encoded
    form before any decoding could rebuild a traversal. Malformed encodings
    are refused a step earlier still, as a 400 — either way the request is
    dead before routing.

    When I request each of these paths with GET and DELETE:
      | ..%2f..%2fetc%2fpasswd          |
      | ..%252f..%252fetc%252fpasswd    |
      | ..%5c..%5cwindows%5cwin.ini     |
      | mykey%00.txt                    |
      | %00                             |
      | %2e%2e%2f%2e%2e%2fetc%2fpasswd  |
      | %c0%ae%c0%ae%2fetc%2fpasswd     |
    Then every attempt should be refused with status 400 or 404
    And the store should hold 0 records

  # ---------------------------------------------------------------
  # HTML and JavaScript
  # ---------------------------------------------------------------

  Scenario: HTML payloads in a value round-trip unchanged
    When I store each of these values under the key "htmlkey":
      | <script>alert(1)</script>                |
      | <img src=x onerror=alert(1)>             |
      | <svg/onload=alert(1)>                    |
      | </pre><script>alert(1)</script><pre>     |
      | javascript:alert(document.cookie)        |
      | " onmouseover="alert(1)                  |
      | <style>@import"//evil"</style>           |
      | <iframe src="//evil"></iframe>           |
      | &lt;script&gt;alert(1)&lt;/script&gt;    |
    Then every value should round-trip unchanged

  Scenario: HTML payloads in a key are refused
    When I try to store each of these keys:
      | <script>alert(1)</script>                |
      | <img src=x onerror=alert(1)>             |
      | <svg/onload=alert(1)>                    |
      | </pre><script>alert(1)</script><pre>     |
      | javascript:alert(document.cookie)        |
      | " onmouseover="alert(1)                  |
      | <style>@import"//evil"</style>           |
      | <iframe src="//evil"></iframe>           |
      | &lt;script&gt;alert(1)&lt;/script&gt;    |
    Then every attempt should be refused with status 422 and a validation error for "key"

  Scenario: Markup in a value is escaped in the raw response body
    SecurityHeaders re-encodes JSON with JSON_HEX_TAG and friends, so a raw
    '<' never appears. Laravel's default encoding does not do this — without
    that middleware the payload is echoed literally.

    Given the value "<script>alert(1)</script>" is stored under the key "htmlkey"
    When I read the key "htmlkey"
    Then the response status should be 200
    And the response body should not contain "<script>"
    And the response body should not contain "<"
    And the response body should contain "\u003C"

  Scenario: No stored payload can emit raw markup on any endpoint
    Given the value "<script>alert(1)</script>" is stored under the key "htmlkey"
    When I read the key "htmlkey"
    Then the response body should not contain "<"
    And the response body should not contain ">"
    And the response body should not contain "&"
    When I read the history of the key "htmlkey"
    Then the response body should not contain "<"
    And the response body should not contain ">"
    And the response body should not contain "&"
    When I list all records
    Then the response body should not contain "<"
    And the response body should not contain ">"
    And the response body should not contain "&"

  Scenario: Responses are served as JSON and may not be sniffed
    Given the value "<script>alert(1)</script>" is stored under the key "htmlkey"
    When I read each of these paths:
      | /object/htmlkey          |
      | /object/htmlkey/history  |
      | /object/get_all_records  |
    Then every attempt should return status 200
    And every response should carry the header "Content-Type" set to "application/json"
    And every response should carry the header "X-Content-Type-Options" set to "nosniff"

  Scenario: Security headers are present on error responses too
    When I read the key "never_written"
    Then the response status should be 404
    And the response header "X-Content-Type-Options" should be "nosniff"
    When I write the body '{"bad key!":"value"}'
    Then the response status should be 422
    And the response header "X-Content-Type-Options" should be "nosniff"

  Scenario: A delete confirmation cannot carry markup from the key
    The success body names the key, so the route charset is what keeps markup
    out of it.

    Given the value "value" is stored under the key "mykey"
    When I delete the key "mykey"
    Then the response status should be 200
    And the response body should not contain "<"
    And the response header "X-Content-Type-Options" should be "nosniff"

  Scenario: A 404 message cannot carry markup from the key
    The 404 body interpolates the key, so the route charset is what keeps that
    safe.

    When I read the key "<script>alert(1)</script>"
    Then the response status should be 404
    When I read the key "plain_key"
    Then the response body should not contain "<"

  # ---------------------------------------------------------------
  # Header and log injection
  # ---------------------------------------------------------------

  Scenario: A CRLF sequence in a key is refused
    Written as raw bodies: a carriage return cannot be expressed in a Gherkin
    table cell, but JSON's own escapes carry it exactly.

    When I write the body '{"mykey\r\nX-Injected: 1":"value"}'
    Then the response status should be 422
    And the response should report a validation error for "key"

  Scenario: A bare line feed in a key is refused
    When I write the body '{"mykey\nX-Injected: 1":"value"}'
    Then the response status should be 422
    And the response should report a validation error for "key"

  Scenario: A bare carriage return in a key is refused
    When I write the body '{"mykey\rX-Injected: 1":"value"}'
    Then the response status should be 422
    And the response should report a validation error for "key"

  Scenario: A forged log line in a key is refused
    When I write the body '{"mykey\n[critical] forged log line":"value"}'
    Then the response status should be 422
    And the response should report a validation error for "key"

  Scenario: A tab in a key is refused
    When I write the body '{"my\tkey":"value"}'
    Then the response status should be 422
    And the response should report a validation error for "key"

  Scenario: CRLF in a value does not reach the response headers
    When I write the body '{"crlfvalue":"line1\r\nX-Injected: yes"}'
    Then the response status should be 201
    When I read the key "crlfvalue"
    Then the response status should be 200
    And the response should not carry the header "X-Injected"

  # ---------------------------------------------------------------
  # JSON structure abuse
  # ---------------------------------------------------------------

  Scenario: A prototype-polluting key is stored as an ordinary key
    __proto__ matches the key charset, so it is accepted. It is inert
    server-side; this pins that it behaves like any other key and does not
    corrupt the listing payload.

    When I write the body '{"__proto__":{"polluted":true}}'
    Then the response status should be 201
    When I list all records
    Then the response status should be 200
    And the record at position 0 should be "__proto__" with the JSON value '{"polluted":true}'

  Scenario: A constructor key is stored as an ordinary key
    When I store the value "value" under the key "constructor"
    Then the response status should be 201
    And the response should be the record "constructor" with the value "value"

  Scenario: Duplicate JSON properties do not bypass the single-pair rule
    json_decode keeps the last occurrence, so this is one pair, not two.

    When I write the body '{"dupe": "first", "dupe": "second"}'
    Then the response status should be 201
    And the response should be the record "dupe" with the value "second"
    And the store should hold 1 records

  Scenario: A nested object cannot smuggle a second key
    When I write the body '{"outer":{"inner":"value"}}'
    Then the response status should be 201
    And the response should be the record "outer" with the JSON value '{"inner":"value"}'
    And the key "inner" should have 0 versions

  Scenario: A _method body field is treated as an ordinary key
    Laravel copies the decoded JSON body into the request bag Symfony reads
    _method from, so this property used to rewrite the verb.

    Given the value "value" is stored under the key "mykey"
    When I store the value "DELETE" under the key "_method"
    Then the response status should be 201
    And the response should be the record "_method" with the value "DELETE"
    When I read the key "mykey"
    Then the response status should be 200
    And the store should hold 2 records

  Scenario: A _method query parameter cannot rewrite the verb
    Symfony also consults the query string for _method.

    When I write the body '{"mykey":"value"}' to "/object?_method=DELETE"
    Then the response status should be 201
    When I read the key "mykey"
    Then the response status should be 200

  Scenario: A method-override header cannot turn a read into a delete
    Given the value "value" is stored under the key "mykey"
    When I read the key "mykey" with the method override header "DELETE"
    Then the response status should be 200
    When I read the key "mykey"
    Then the response status should be 200
    And the store should hold 1 records

  Scenario: Model columns cannot be mass assigned through the body
    'id', 'created_at' and friends are just key names here — they must not
    bind to model attributes.

    When I try to store each of these keys:
      | id          |
      | created_at  |
      | recorded_at |
    Then every attempt should return status 201
    And the store should hold 3 records
    And the id of the first record should not be "injected"

  Scenario: Numeric-looking keys stay distinct
    Raw bodies on purpose: json_decode(..., true) turns {"0":"a"} into
    [0 => 'a'], which is indistinguishable from ["a"] — the key "0" used to be
    refused as an array body because of that collision.

    When I write the body '{"0": "zero"}'
    Then the response status should be 201
    When I write the body '{"00": "double zero"}'
    Then the response status should be 201
    When I write the body '{"0e123": "exponent"}'
    Then the response status should be 201
    And the store should hold 3 records
    When I read the key "0"
    Then the response value should be "zero"
    When I read the key "00"
    Then the response value should be "double zero"
    When I read the key "0e123"
    Then the response value should be "exponent"

  Scenario: A JSON object with list-like properties stays an object
    An associative decode would collapse {"0":"a","1":"b"} into a PHP list and
    re-encode it as ["a","b"], silently changing the value's JSON type on both
    the write and the read path.

    When I write the body '{"shapekey": {"0":"a","1":"b"}}'
    Then the response status should be 201
    When I read the key "shapekey"
    Then the response body should contain '"value":{"0":"a","1":"b"}'
    And the response body should not contain '["a","b"]'

  Scenario: A genuine JSON array value stays an array
    When I write the body '{"arraykey": ["a","b"]}'
    Then the response status should be 201
    When I read the key "arraykey"
    Then the response body should contain '"value":["a","b"]'

  Scenario: An empty object value is not confused with an empty array
    When I write the body '{"objkey": {}}'
    Then the response status should be 201
    When I write the body '{"arrkey": []}'
    Then the response status should be 201
    When I read the key "objkey"
    Then the response body should contain '"value":{}'
    When I read the key "arrkey"
    Then the response body should contain '"value":[]'

  # ---------------------------------------------------------------
  # Encoding and parser limits
  # ---------------------------------------------------------------

  Scenario: Malformed JSON is refused
    When I try each of these bodies:
      | {"mykey":                |
      | not json at all          |
      | null                     |
      | "just a string"          |
      | [{"mykey": "value"}]     |
    Then every attempt should be refused with status 422

  Scenario: An empty body is refused
    When I write the body ''
    Then the response status should be 422

  Scenario: A lone surrogate is refused
    When I write the body '{"mykey": "\ud800"}'
    Then the response status should be 422

  Scenario: A content type mismatch does not bypass validation
    The body is parsed from the raw request content, so a lying Content-Type
    must not change the outcome.

    When I write the body '{"bad key!": "value"}' as content type "text/plain"
    Then the response status should be 422

  Scenario: Form-encoded bodies are refused
    When I post the form field "mykey" with the value "value"
    Then the response status should be 422
    And the response should report a validation error for "body"
    And the store should hold 0 records

  # ---------------------------------------------------------------
  # Boundaries
  # ---------------------------------------------------------------

  Scenario: A key of exactly 255 characters is accepted
    When I store a value under a key of 255 characters
    Then the response status should be 201

  Scenario: A key of 256 characters is refused
    When I store a value under a key of 256 characters
    Then the response status should be 422
    And the response should report a validation error for "key"

  Scenario: Invalid timestamps are refused
    Given the value "value" is stored under the key "mykey"
    When I try each of these timestamps on the key "mykey":
      | -1                  |
      | 1.5                 |
      | 1e10                |
      | 0x10                |
      | abc                 |
      | 9223372036854775808 |
    Then every attempt should be refused with status 422

  Scenario: An array-shaped timestamp parameter is refused
    Given the value "value" is stored under the key "mykey"
    When I read the key "mykey" with the raw query "timestamp[]=1"
    Then the response status should be 422

  Scenario: Timestamp zero is accepted and finds nothing
    Given the value "value" is stored under the key "mykey"
    When I read the key "mykey" at timestamp "0"
    Then the response status should be 404

  Scenario: Non-numeric page segments do not match the route
    When I try each of these page segments:
      | -1        |
      | 1.5       |
      | abc       |
      | 1 OR 1    |
    Then every attempt should be refused with status 404
