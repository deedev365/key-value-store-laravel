Feature: Per-IP request throttling
  Laravel does not throttle API routes unless a limiter is named, so this pins
  both that the limit exists and what a caller is told when they hit it. The
  window is rolling: the refusal says how many seconds are actually left.

  Scenario: Requests up to the limit are allowed
    When I make 60 requests to "/object/get_all_records" from the IP "10.0.0.1"
    Then the response status should be 200

  Scenario: The refusal says how long to wait
    When I make 61 requests to "/object/get_all_records" from the IP "10.0.0.3"
    Then the response status should be 429
    And the retry_after field should be between 1 and 60 seconds
    And the refusal message should quote the retry_after field

  Scenario: The refusal carries the rate-limit headers
    When I make 61 requests to "/object/get_all_records" from the IP "10.0.0.4"
    Then the response status should be 429
    And the response should carry the header "Retry-After"
    And the response header "X-RateLimit-Limit" should be "60"
    And the response header "X-RateLimit-Remaining" should be "0"
    And the response should carry the header "X-RateLimit-Reset"
    And the Retry-After header should equal the retry_after field

  Scenario: Allowed responses advertise the remaining quota
    When I send a GET to "/object/get_all_records" from the IP "10.0.0.5"
    Then the response status should be 200
    And the response header "X-RateLimit-Limit" should be "60"
    And the response header "X-RateLimit-Remaining" should be "59"
    When I send a GET to "/object/get_all_records" from the IP "10.0.0.5"
    Then the response header "X-RateLimit-Remaining" should be "58"

  Scenario: The refusal is JSON and carries the security headers
    When I make 61 requests to "/object/get_all_records" from the IP "10.0.0.6"
    Then the response status should be 429
    And the response header "Content-Type" should be "application/json"
    And the response header "X-Content-Type-Options" should be "nosniff"

  Scenario: The quota is per IP
    When I make 61 requests to "/object/get_all_records" from the IP "10.0.0.7"
    Then the response status should be 429
    When I send a GET to "/object/get_all_records" from the IP "10.0.0.8"
    Then the response status should be 200

  Scenario: Reads and writes share one quota, and a refused write never lands
    Two guarantees that cannot be separated: the write is refused on the quota
    the reads spent — one pool, not one per verb — and a refused write never
    lands, however many times it is retried.

    When I make 60 requests to "/object/get_all_records" from the IP "10.0.0.9"
    And I store the value "value" under the key "flood" from the IP "10.0.0.9"
    Then the response status should be 429
    When I store the value "value" under the key "flood" from the IP "10.0.0.9"
    Then the response status should be 429
    And the store should hold 0 records

  Scenario: The limit is configurable
    Given the request limit is 3 requests per minute
    When I make 3 requests to "/object/get_all_records" from the IP "10.0.0.11"
    Then the response status should be 200
    When I send a GET to "/object/get_all_records" from the IP "10.0.0.11"
    Then the response status should be 429

  Scenario: The front end is not throttled
    Only the API routes are throttled; the page itself must keep loading.

    Given the request limit is 2 requests per minute
    When I make 3 requests to "/object/get_all_records" from the IP "10.0.0.12"
    Then the response status should be 429
    When I send a GET to "/" from the IP "10.0.0.12"
    Then the response status should be 200
