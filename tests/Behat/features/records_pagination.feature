Feature: Paging through the listing
  The listing is paged so that a large store cannot be pulled down in one
  request. Paging counts keys rather than rows, and the page is cut in SQL —
  slicing in PHP would hydrate the whole table to serve five records.

  Scenario: A full page holds exactly five records
    Given 25 keys have been stored
    When I list all records
    Then the response status should be 200
    And the response should list 5 records

  Scenario: Fewer records than a page returns them all
    Given 4 keys have been stored
    When I list all records
    Then the response should list 4 records

  Scenario: Exactly one page of records does not spill onto a second
    Given 5 keys have been stored
    When I list all records on page "1"
    Then the response should list 5 records
    When I list all records on page "2"
    Then the response should be an empty array

  Scenario: The second page continues where the first stopped
    Given 25 keys have been stored
    When I list all records on page "1"
    Then the record at position 0 should be "key_001" with the JSON value '1'
    And the record at position 4 should be "key_005" with the JSON value '5'
    When I list all records on page "2"
    Then the record at position 0 should be "key_006" with the JSON value '6'
    And the record at position 4 should be "key_010" with the JSON value '10'

  Scenario: The last page holds the remainder
    23 rather than 25: a store that divides evenly into pages would never
    exercise a partial last page.

    Given 23 keys have been stored
    When I list all records on page "5"
    Then the response status should be 200
    And the response should list 3 records

  Scenario: Paging covers every key exactly once
    Given 23 keys have been stored
    When I list all records on page "1"
    Then the response should list the keys "key_001, key_002, key_003, key_004, key_005"
    When I list all records on page "2"
    Then the response should list the keys "key_006, key_007, key_008, key_009, key_010"
    When I list all records on page "3"
    Then the response should list the keys "key_011, key_012, key_013, key_014, key_015"
    When I list all records on page "4"
    Then the response should list the keys "key_016, key_017, key_018, key_019, key_020"
    When I list all records on page "5"
    Then the response should list the keys "key_021, key_022, key_023"

  Scenario: A page past the end is an empty array
    Given 25 keys have been stored
    When I list all records on page "6"
    Then the response status should be 200
    And the response should be an empty array
    When I list all records on page "9999"
    Then the response status should be 200
    And the response should be an empty array

  Scenario: Page zero and no page both mean the first page
    Given 25 keys have been stored
    When I list all records on page "1"
    Then the response should list the keys "key_001, key_002, key_003, key_004, key_005"
    When I list all records on page "0"
    Then the response should list the keys "key_001, key_002, key_003, key_004, key_005"
    When I list all records
    Then the response should list the keys "key_001, key_002, key_003, key_004, key_005"

  Scenario: An empty store returns an empty array on any page
    When I list all records
    Then the response should be an empty array
    When I list all records on page "3"
    Then the response should be an empty array

  Scenario: Pages hold keys, not versions
    36 writes across 12 keys is 12 records, so page 3 holds 2 of them —
    paging must count keys, not rows.

    Given 12 keys have been stored with 3 versions each
    When I list all records on page "1"
    Then the response should list 5 records
    When I list all records on page "3"
    Then the response should list 2 records
    And the response should contain the record "key_012" with the value "v3"
    And the response should not contain the value "v1"

  Scenario: The page size is configurable
    Given the page size is 3 records
    And 7 keys have been stored
    When I list all records on page "1"
    Then the response should list 3 records
    When I list all records on page "2"
    Then the record at position 0 should be "key_004" with the JSON value '4'
    When I list all records on page "3"
    Then the response should list 1 records

  Scenario: The whole table is not loaded to serve one page
    Given 25 keys have been stored
    When I list all records while recording the queries
    Then the response status should be 200
    And the listing query should be cut in SQL
