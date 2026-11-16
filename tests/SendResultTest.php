<?php

/**
 * Unit tests for send-response interpretation (no network, no Civi bootstrap).
 * All bodies are canned fixtures; nothing here contacts the Mobile Message API.
 *
 * Run:
 *   php tests/SendResultTest.php
 */

require_once dirname(__DIR__) . '/Civi/Mobilemessage/SendResult.php';

use Civi\Mobilemessage\SendResult;

function mm_assert($cond, string $msg): void {
  if (!$cond) {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
  }
  echo "PASS: $msg\n";
}

function mm_body(array $data): string {
  return json_encode($data);
}

// --- success ---
$r = SendResult::interpret(200, mm_body([
  'status' => 'complete',
  'results' => [['to' => '61400000001', 'status' => 'success', 'message_id' => 'msg-123', 'cost' => 1]],
]));
mm_assert($r['ok'] === TRUE && $r['message_id'] === 'msg-123', 'success with message_id is accepted');

// --- blocked (recipient unsubscribed), HTTP 200 ---
$r = SendResult::interpret(200, mm_body([
  'status' => 'complete',
  'results' => [['to' => '61400000001', 'status' => 'blocked']],
]));
mm_assert($r['ok'] === FALSE, 'blocked is a failure');
mm_assert(stripos($r['error'], 'unsubscribed') !== FALSE, 'blocked error says recipient unsubscribed');

// --- per-message error (invalid number), HTTP 200 ---
$r = SendResult::interpret(200, mm_body([
  'status' => 'complete',
  'results' => [['to' => '123', 'status' => 'error', 'error' => 'Invalid number']],
]));
mm_assert($r['ok'] === FALSE, 'invalid number is a failure');
mm_assert(strpos($r['error'], 'Invalid number') !== FALSE, 'per-message error includes API error text');

// --- per-message error without text ---
$r = SendResult::interpret(200, mm_body(['results' => [['status' => 'error']]]));
mm_assert($r['ok'] === FALSE && $r['error'] !== '', 'error with no text gets a generic message');

// --- structurally invalid entry: only status/error/item ---
$r = SendResult::interpret(200, mm_body([
  'results' => [['status' => 'error', 'error' => 'Missing "to"', 'item' => ['message' => 'hi']]],
]));
mm_assert($r['ok'] === FALSE && strpos($r['error'], 'Missing "to"') !== FALSE, 'structurally invalid entry is a failure with its text');

// --- empty / missing results ---
mm_assert(SendResult::interpret(200, mm_body(['results' => []]))['ok'] === FALSE, 'empty results is a failure');
mm_assert(SendResult::interpret(200, mm_body(['status' => 'complete']))['ok'] === FALSE, 'missing results is a failure');

// --- success without a usable message_id ---
mm_assert(
  SendResult::interpret(200, mm_body(['results' => [['status' => 'success']]]))['ok'] === FALSE,
  'success without message_id is a failure'
);
mm_assert(
  SendResult::interpret(200, mm_body(['results' => [['status' => 'success', 'message_id' => '']]]))['ok'] === FALSE,
  'success with empty message_id is a failure'
);

// --- unknown per-message status ---
mm_assert(
  SendResult::interpret(200, mm_body(['results' => [['status' => 'weird', 'message_id' => 'x']]]))['ok'] === FALSE,
  'unknown status is a failure even with a message_id'
);

// --- HTTP >= 400 ---
$r = SendResult::interpret(401, mm_body(['status' => 'error', 'message' => 'Unauthorized']));
mm_assert($r['ok'] === FALSE && strpos($r['error'], 'Unauthorized') !== FALSE, 'HTTP 401 is a failure with API message');
$r = SendResult::interpret(500, 'Internal Server Error');
mm_assert($r['ok'] === FALSE && strpos($r['error'], 'Internal Server Error') !== FALSE, 'HTTP 500 non-JSON is a failure');
$r = SendResult::interpret(400, mm_body(['results' => [['status' => 'success', 'message_id' => 'm']]]));
mm_assert($r['ok'] === FALSE, 'HTTP >= 400 fails even if the body looks successful');

// --- non-JSON body at HTTP 200 ---
mm_assert(SendResult::interpret(200, '<html>oops</html>')['ok'] === FALSE, 'non-JSON body is a failure');
mm_assert(SendResult::interpret(200, '')['ok'] === FALSE, 'empty body is a failure');
mm_assert(SendResult::interpret(200, FALSE)['ok'] === FALSE, 'FALSE body (cURL failure) is a failure');
mm_assert(SendResult::interpret(200, 'null')['ok'] === FALSE, 'JSON null body is a failure');

// --- top-level status "error" ---
$r = SendResult::interpret(200, mm_body([
  'status' => 'error',
  'message' => 'Insufficient credit',
  'results' => [['status' => 'success', 'message_id' => 'm']],
]));
mm_assert($r['ok'] === FALSE && strpos($r['error'], 'Insufficient credit') !== FALSE, 'top-level error wins and carries its message');

echo "\nAll send result tests passed.\n";
