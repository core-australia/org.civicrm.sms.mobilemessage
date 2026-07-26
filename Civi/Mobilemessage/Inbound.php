<?php

namespace Civi\Mobilemessage;

/**
 * Pure helpers for inbound webhook handling (idempotency + routing).
 *
 * Kept free of Civi bootstrap so unit tests can run with plain PHPUnit/php.
 */
class Inbound {

  /**
   * True when the JSON body is a delivery-status event, not an inbound/unsubscribe.
   *
   * Inbound webhooks use `type` = inbound|unsubscribe and have no delivery `status`.
   * Status webhooks use `status` = sent|delivered|failed and have no inbound `type`.
   */
  public static function isDeliveryStatusPayload(array $data): bool {
    if (empty($data['status']) || !is_string($data['status'])) {
      return FALSE;
    }
    $type = $data['type'] ?? NULL;
    if (in_array($type, ['inbound', 'unsubscribe'], TRUE)) {
      return FALSE;
    }
    $status = strtolower($data['status']);
    return in_array($status, ['sent', 'delivered', 'failed', 'pending', 'scheduled'], TRUE)
      || !empty($data['message_id']);
  }

  /**
   * Normalize AU mobiles toward national 0… form so processInbound's phone_numeric
   * LIKE match hits contacts stored as 04… rather than creating 61…@mobile.sms.
   */
  public static function normalizeAustralianMobile(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === NULL || $digits === '') {
      return $phone;
    }
    // 61XXXXXXXXX (11+) → 0XXXXXXXXX
    if (str_starts_with($digits, '61') && strlen($digits) >= 11) {
      return '0' . substr($digits, 2);
    }
    return $phone;
  }

  /**
   * Stable external id stored on civicrm_activity.result for idempotent ingest.
   *
   * Prefer provider inbound message_id when present. Do NOT use original_message_id
   * alone — that is the outbound ID and would collide across multiple replies.
   */
  public static function buildInboundTrackId(array $data): string {
    if (!empty($data['message_id']) && is_string($data['message_id'])) {
      return $data['message_id'];
    }
    $parts = [
      (string) ($data['sender'] ?? ''),
      (string) ($data['to'] ?? ''),
      (string) ($data['message'] ?? ''),
      (string) ($data['received_at'] ?? ''),
      (string) ($data['type'] ?? 'inbound'),
    ];
    return 'mm_in_' . sha1(implode('|', $parts));
  }

}
