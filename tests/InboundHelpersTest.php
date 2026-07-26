<?php

/**
 * Unit tests for inbound webhook helpers (no Civi bootstrap required).
 *
 * Run:
 *   php tests/InboundHelpersTest.php
 */

require_once dirname(__DIR__) . '/Civi/Mobilemessage/Inbound.php';

use Civi\Mobilemessage\Inbound;

function mm_assert($cond, string $msg): void {
  if (!$cond) {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
  }
  echo "PASS: $msg\n";
}

// --- status vs inbound routing ---
mm_assert(
  Inbound::isDeliveryStatusPayload([
    'to' => '61412345678',
    'sender' => 'MyBrand',
    'message' => 'Hello',
    'status' => 'delivered',
    'message_id' => 'abcd-1234',
    'received_at' => '2026-03-08 14:35:00',
  ]),
  'delivered status payload is treated as status'
);

mm_assert(
  !Inbound::isDeliveryStatusPayload([
    'to' => '61400000010',
    'sender' => '61412345678',
    'message' => 'Yes please',
    'received_at' => '2026-03-08 14:35:00',
    'type' => 'inbound',
    'original_message_id' => 'abcd-1234',
  ]),
  'inbound payload is not treated as status'
);

mm_assert(
  !Inbound::isDeliveryStatusPayload([
    'type' => 'inbound',
    'status' => 'delivered',
    'sender' => '61412345678',
    'message' => 'hi',
  ]),
  'explicit inbound type wins over status field'
);

mm_assert(
  Inbound::isDeliveryStatusPayload([
    'status' => 'failed',
    'message_id' => 'x',
  ]),
  'failed status payload is treated as status'
);

// --- AU phone normalize ---
mm_assert(
  Inbound::normalizeAustralianMobile('61412345678') === '0412345678',
  '61… normalizes to 04…'
);
mm_assert(
  Inbound::normalizeAustralianMobile('+61 412 345 678') === '0412345678',
  '+61 with spaces normalizes to 04…'
);
mm_assert(
  Inbound::normalizeAustralianMobile('0412345678') === '0412345678',
  'already-national number unchanged'
);
mm_assert(
  Inbound::normalizeAustralianMobile('MyBrand') === 'MyBrand',
  'alphanumeric sender left alone'
);

// --- track id idempotency ---
$payload = [
  'to' => '61400000010',
  'sender' => '61412345678',
  'message' => 'Yes, I would like to confirm',
  'received_at' => '2026-03-08 14:35:00',
  'type' => 'inbound',
  'original_message_id' => 'outbound-shared-id',
];
$id1 = Inbound::buildInboundTrackId($payload);
$id2 = Inbound::buildInboundTrackId($payload);
mm_assert($id1 === $id2, 'same inbound payload yields same track id');
mm_assert(str_starts_with($id1, 'mm_in_'), 'synthesized track id uses mm_in_ prefix');

$otherReply = $payload;
$otherReply['message'] = 'Different reply text';
mm_assert(
  Inbound::buildInboundTrackId($otherReply) !== $id1,
  'different body yields different track id (not keyed only on original_message_id)'
);

$withProviderId = $payload;
$withProviderId['message_id'] = 'inbound-uuid-999';
mm_assert(
  Inbound::buildInboundTrackId($withProviderId) === 'inbound-uuid-999',
  'provider message_id preferred when present'
);

echo "\nAll inbound helper tests passed.\n";
