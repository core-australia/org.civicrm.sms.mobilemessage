<?php

/**
 * Unit tests for webhook HMAC helpers (no Civi bootstrap required).
 *
 * Run:
 *   php tests/WebhookSignatureTest.php
 */

require_once dirname(__DIR__) . '/Civi/Mobilemessage/WebhookSignature.php';

use Civi\Mobilemessage\WebhookSignature;

function mm_sig_assert($cond, string $msg): void {
  if (!$cond) {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
  }
  echo "PASS: $msg\n";
}

// Vendor-documented vector: secret abc123, ts 1754640000, body {"test":1}
$vendorHex = '52344b9592722e0241d82036e0920f4286bc0d47ba4624c5a1588193490a1efb';
mm_sig_assert(
  WebhookSignature::matches('abc123', '1754640000', '{"test":1}', $vendorHex),
  'vendor HMAC test vector matches'
);

mm_sig_assert(
  !WebhookSignature::matches('abc123', '1754640000', '{"test":1}', str_repeat('0', 64)),
  'wrong signature rejected'
);

mm_sig_assert(
  !WebhookSignature::matches('wrong', '1754640000', '{"test":1}', $vendorHex),
  'wrong secret rejected'
);

mm_sig_assert(
  !WebhookSignature::matches('abc123', '1754640000', '{"test":1}', 'not-hex'),
  'non-hex signature rejected'
);

mm_sig_assert(
  WebhookSignature::timestampIsFresh('1754640000', 1754640000),
  'exact timestamp is fresh'
);

mm_sig_assert(
  WebhookSignature::timestampIsFresh((string) (time() - 100)),
  'recent timestamp is fresh'
);

mm_sig_assert(
  !WebhookSignature::timestampIsFresh((string) (time() - 400)),
  'stale timestamp rejected'
);

mm_sig_assert(
  !WebhookSignature::timestampIsFresh('not-a-number'),
  'non-digit timestamp rejected'
);

$secrets = WebhookSignature::secretsFromApiParams(
  "From=0411111111|0422222222\nSigningSecret=sec|with|pipes\nSigningSecretPrevious=oldsecret\n"
);
mm_sig_assert(
  $secrets === ['sec|with|pipes', 'oldsecret'],
  'secrets parsed without pipe-splitting; From ignored'
);

$secretsArr = WebhookSignature::secretsFromApiParams([
  'From' => 'Brand',
  'SigningSecret' => '  current  ',
]);
mm_sig_assert(
  $secretsArr === ['current'],
  'array api_params and trim work'
);

$headers = WebhookSignature::headersFromRequest([
  'HTTP_X_MM_TIMESTAMP' => '111',
  'HTTP_X_MM_SIGNATURE' => 'abc',
]);
mm_sig_assert(
  $headers === ['timestamp' => '111', 'signature' => 'abc'],
  'headers from $_SERVER'
);

$headers2 = WebhookSignature::headersFromRequest([], [
  'X-MM-Timestamp' => '222',
  'X-MM-Signature' => 'def',
]);
mm_sig_assert(
  $headers2 === ['timestamp' => '222', 'signature' => 'def'],
  'headers fall back to getallheaders-style map'
);

echo "\nAll webhook signature tests passed.\n";
