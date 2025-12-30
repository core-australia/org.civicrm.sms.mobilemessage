<?php

require_once 'mobilemessage.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function mobilemessage_civicrm_config(&$config)
{
  _mobilemessage_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 */
function mobilemessage_civicrm_install()
{
  $groupID = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_OptionGroup', 'sms_provider_name', 'id', 'name');

  civicrm_api3('OptionValue', 'create', [
    'option_group_id' => $groupID,
    'label' => 'Mobile Message',
    'value' => 'org.civicrm.sms.mobilemessage',
    'name' => 'mobilemessage',
    'is_default' => 1,
    'is_active' => 1,
  ]);

  return _mobilemessage_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function mobilemessage_civicrm_uninstall()
{
  $optionID = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_OptionValue', 'mobilemessage', 'id', 'name');

  if ($optionID) {
    CRM_Core_BAO_OptionValue::del($optionID);
  }

  $filter = ['name' => 'org.civicrm.sms.mobilemessage'];
  $Providers = CRM_SMS_BAO_Provider::getProviders(false, $filter, false);
  if ($Providers) {
    foreach ($Providers as $key => $value) {
      CRM_SMS_BAO_Provider::del($value['id']);
    }
  }
  return;
}

/**
 * Implementation of hook_civicrm_enable
 */
function mobilemessage_civicrm_enable()
{
  $optionID = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_OptionValue', 'mobilemessage', 'id', 'name');

  if ($optionID) {
    CRM_Core_BAO_OptionValue::setIsActive($optionID, true);
  }

  $filter = ['name' => 'org.civicrm.sms.mobilemessage'];
  $Providers = CRM_SMS_BAO_Provider::getProviders(false, $filter, false);
  if ($Providers) {
    foreach ($Providers as $key => $value) {
      CRM_SMS_BAO_Provider::setIsActive($value['id'], true);
    }
  }
  return _mobilemessage_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function mobilemessage_civicrm_disable()
{
  $optionID = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_OptionValue', 'mobilemessage', 'id', 'name');

  if ($optionID) {
    CRM_Core_BAO_OptionValue::setIsActive($optionID, false);
  }

  $filter = ['name' => 'org.civicrm.sms.mobilemessage'];
  $Providers = CRM_SMS_BAO_Provider::getProviders(false, $filter, false);
  if ($Providers) {
    foreach ($Providers as $key => $value) {
      CRM_SMS_BAO_Provider::setIsActive($value['id'], false);
    }
  }
  return;
}
