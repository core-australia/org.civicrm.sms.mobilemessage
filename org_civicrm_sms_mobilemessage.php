<?php

class org_civicrm_sms_mobilemessage extends CRM_SMS_Provider
{
  /**
   * provider details
   * @var string
   */
  protected $_providerInfo = [];

  public $_apiURL = 'https://api.mobilemessage.com.au/v1/messages';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  private static $_singleton = [];

  /**
   * Constructor
   *
   * @return void
   */
  function __construct($provider = [], $skipAuth = true)
  {
    $this->_providerInfo = $provider;

    if ($skipAuth) {
      return true;
    }

    $this->authenticate();
  }

  /**
   * singleton function used to manage this object
   *
   * @return object
   */
  static function &singleton($providerParams = [], $force = false)
  {
    $providerID = $providerParams['provider_id'] ?? null;
    $skipAuth = $providerID ? false : true;
    $cacheKey = (int) $providerID;

    if (!isset(self::$_singleton[$cacheKey]) || $force) {
      $provider = [];
      if ($providerID) {
        $provider = CRM_SMS_BAO_Provider::getProviderInfo($providerID);
      }
      self::$_singleton[$cacheKey] = new org_civicrm_sms_mobilemessage($provider, $skipAuth);
    }
    return self::$_singleton[$cacheKey];
  }

  /**
   * Authenticate to the Mobile Message Server.
   * @return boolean TRUE
   * @access public
   */
  function authenticate()
  {
    return true;
  }

  /**
   * Send an SMS Message via the Mobile Message API Server
   *
   * @param array $recipients
   * @param array $header
   * @param string $message
   * @param int $jobID
   * @param int $userID
   *
   * @return mixed response on success or PEAR_Error object
   * @access public
   */
  function send($recipients, $header, $message, $jobID = null, $userID = null)
  {
    $username = $this->_providerInfo['username'] ?? '';
    $password = $this->_providerInfo['password'] ?? '';

    $from = '';
    if (isset($this->_providerInfo['api_params']['From'])) {
      $from = $this->_providerInfo['api_params']['From'];
      if (preg_match('/\|/', $from)) {
        $froms = explode('|', $from);
        $key = array_rand($froms);
        $from = $froms[$key];
      }
    }

    $messages = [];
    // CiviCRM usually calls send() for each recipient, but $recipients can be an array in some contexts.
    // However, the standard signature is often one-by-one.
    // In CRM_SMS_BAO_Provider::send, it's called in a loop.
    // $header['To'] contains the current recipient.

    $to = $header['To'] ?? '';
    if (empty($to)) {
      return PEAR::raiseError('No recipient specified', 0, PEAR_ERROR_RETURN);
    }

    $data = [
      'enable_unicode' => true,
      'messages' => [
        [
          'to' => $to,
          'message' => $message,
          'sender' => $from,
          'custom_ref' => $jobID ? "job_$jobID" : null,
        ],
      ],
    ];

    $ch = curl_init($this->_apiURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_POST, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
      return PEAR::raiseError("CURL Error: $err", 0, PEAR_ERROR_RETURN);
    }

    $result = json_decode($response, true);
    if ($httpCode >= 400 || (isset($result['status']) && $result['status'] === 'error')) {
      $errMsg = $result['message'] ?? $response;
      return PEAR::raiseError("Mobile Message API Error: $errMsg", $httpCode, PEAR_ERROR_RETURN);
    }

    // Capture first message_id for CiviCRM
    $messageID = '';
    if (!empty($result['results'][0]['message_id'])) {
      $messageID = $result['results'][0]['message_id'];
    }

    $this->createActivity($messageID, $message, $header, $jobID, $userID);
    return $messageID;
  }

  function callback()
  {
    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);

    if (!$data) {
      return false;
    }

    $messageID = $data['message_id'] ?? '';
    $status = $data['status'] ?? '';

    // CiviCRM doesn't have a standard built-in way to handle outbound delivery receipts in activity logs easily without custom tables,
    // but we can at least log it or update the activity if we had the ID mapping.
    // For now, return true as it's processed.
    return true;
  }

  function inbound()
  {
    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);

    $fromPhone = $data['sender'] ?? '';
    $body = $data['message'] ?? '';
    $type = $data['type'] ?? 'inbound';

    // If Mobile Message identifies this as an unsubscribe event,
    // ensure the body contains a keyword CiviCRM recognizes if it's otherwise empty.
    if ($type === 'unsubscribe' && empty($body)) {
      $body = 'STOP';
    }

    // Use message_id as the primary external identifier for this inbound message.
    // original_message_id is the ID of the message this is replying to.
    $externalID = $data['message_id'] ?? ($data['original_message_id'] ?? null);

    return parent::processInbound($fromPhone, $body, null, $externalID);
  }
}
