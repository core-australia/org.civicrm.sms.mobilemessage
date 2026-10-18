<?php

namespace Civi\Mobilemessage;

/**
 * Pure helpers for Mobile Message webhook HMAC verification.
 *
 * Kept free of Civi bootstrap so unit tests can run with plain PHP.
 *
 * @see https://help.mobilemessage.com.au/api/verifying-webhook-signatures
 */
class WebhookSignature {

  /** Max age (seconds) for X-MM-Timestamp relative to local time. */
  public const MAX_SKEW_SECONDS = 300;

  /**
   * Constant-time HMAC compare. Signature is lowercase hex of SHA-256 digest.
   *
   * Signed string is "{timestamp}.{raw_body}".
   */
  public static function matches(string $secret, string $timestamp, string $rawBody, string $signature): bool {
    $signature = strtolower(trim($signature));
    if (strlen($signature) !== 64 || !ctype_xdigit($signature)) {
      return FALSE;
    }
    $given = hex2bin($signature);
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret, TRUE);
    return is_string($given) && is_string($expected) && hash_equals($expected, $given);
  }

  /**
   * True when the unix timestamp is within MAX_SKEW_SECONDS of $now.
   */
  public static function timestampIsFresh(string $timestamp, ?int $now = NULL): bool {
    if ($timestamp === '' || !ctype_digit($timestamp)) {
      return FALSE;
    }
    $now = $now ?? time();
    return abs($now - (int) $timestamp) <= self::MAX_SKEW_SECONDS;
  }

  /**
   * Extract SigningSecret / SigningSecretPrevious from SMS provider API Parameters.
   *
   * Secrets are NOT pipe-split (unlike From=) — a generated secret may contain `|`.
   *
   * @param array|string $apiParams
   * @return string[]
   */
  public static function secretsFromApiParams($apiParams): array {
    if (is_string($apiParams)) {
      $parsed = [];
      foreach (explode("\n", str_replace("\r", '', $apiParams)) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === FALSE) {
          continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $parsed[trim($key)] = trim($val);
      }
      $apiParams = $parsed;
    }

    if (!is_array($apiParams)) {
      return [];
    }

    $secrets = [];
    foreach (['SigningSecret', 'SigningSecretPrevious'] as $key) {
      if (!isset($apiParams[$key]) || !is_string($apiParams[$key])) {
        continue;
      }
      $secret = trim($apiParams[$key]);
      if ($secret !== '') {
        $secrets[] = $secret;
      }
    }
    return $secrets;
  }

  /**
   * Resolve timestamp/signature from $_SERVER and optional getallheaders() map.
   *
   * @param array $server Typically $_SERVER
   * @param array|null $allHeaders Lowercased header map, or NULL to skip
   * @return array{timestamp: string, signature: string}
   */
  public static function headersFromRequest(array $server, $allHeaders = NULL): array {
    $timestamp = trim((string) ($server['HTTP_X_MM_TIMESTAMP'] ?? ''));
    $signature = trim((string) ($server['HTTP_X_MM_SIGNATURE'] ?? ''));
    if (($timestamp === '' || $signature === '') && is_array($allHeaders)) {
      $headers = array_change_key_case($allHeaders, CASE_LOWER);
      if ($timestamp === '') {
        $timestamp = trim((string) ($headers['x-mm-timestamp'] ?? ''));
      }
      if ($signature === '') {
        $signature = trim((string) ($headers['x-mm-signature'] ?? ''));
      }
    }
    return [
      'timestamp' => $timestamp,
      'signature' => $signature,
    ];
  }

}
