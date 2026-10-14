<?php
return [
  'db' => [
    'host' => '127.0.0.1',
    //'name' => 'vendor_portal_test',
    'name' => 'exotic_vendor_portal',
    'user' => 'vendor_user',
    'pass' => 'eXotic@123',
    'port' => 3306,
    'charset' => 'utf8mb4'
  ],
  'token_secret' => 'a1b2c3d4e5f678901234567890abcdef',
  'ENV' => 'live',   // change to 'live' on production

  'LOCAL_API_BASE' => 'http://localhost/exotic_vendor/api',
  'LOCAL_WS_URL'   => 'ws://localhost:8888',

  'TEST_API_BASE'  => 'https://sellertest.exoticindia.com/api',
  'TEST_WS_URL'    => 'wss://sellertest.exoticindia.com/ws/',

  'LIVE_API_BASE'  => 'https://seller.exoticindia.com/api',
  'LIVE_WS_URL'    => 'wss://seller.exoticindia.com/ws/',

  'WS_PORT' => 8082,
  //'WS_PORT' => 8081

  /**
   * Optional: allow running scripts/backfill_published_inbound_logs.php in the browser.
   * Set to a long random string, then call:
   *   .../scripts/backfill_published_inbound_logs.php?key=YOUR_STRING
   *   .../scripts/backfill_published_inbound_logs.php?key=YOUR_STRING&execute=1
   * Leave empty to disable web access (use CLI only).
   */
  'backfill_logs_web_key' => '',

  /**
   * Alankit/Eraahi E-invoice IRN API Credentials
   * Used for generating IRN (Invoice Registration Number) for international invoices
   * API Documentation: https://developers.eraahi.com
   * AppKey: 32-character random unique identifier for application authentication
   */
  // 'alankit' => [
  //   'username' => 'EXOT_IND_2026',
  //   'password' => 'Alankit@123',
  //   'subscription_key' => 'AL6x9c9S1b7g8h9S7C',
  //   'gstin' => '07AGAPA5363L002',
  //   'company_name' => 'EXOTIC INDIA ART PVT LTD',
  //   'app_key' => 'f8c4e2d1a9b3c5e7f9a1b3c5e7f9a1b3', // 32-character unique ID
  //   'force_refresh_access_token' => true, // Refresh token 10 minutes before expiry
  //   'auto_generate_ewaybill' => true, // Auto-generate E-Way Bill when IRN is generated
  //   'ewaybill_transport_mode' => 'ROAD', // ROAD, RAIL, AIR, SHIP
  //   'ewaybill_vehicle_type' => 'REGULAR' // REGULAR, ABNORMAL
  // ],
  /**Sandbox */
  'alankit' => [
    'username' => 'AL001',
    'password' => 'Alankit@123',
    'subscription_key' => 'AL6x9c9S1b7g8h9S7C',
    'gstin' => '07AGAPA5363L002',
    'company_name' => 'EXOTIC INDIA ART PVT LTD',
    'app_key' => 'ZEtrRXA3UWRZalZqT2xtZ3IwOElFa0ZXNnRIazdUSGQ=', // encripted AppKey (Base64 encoded)
    'force_refresh_access_token' => true, // Refresh token 10 minutes before expiry
    'auto_generate_ewaybill' => true, // Auto-generate E-Way Bill when IRN is generated
    'ewaybill_transport_mode' => 'ROAD', // ROAD, RAIL, AIR, SHIP
    'ewaybill_vehicle_type' => 'REGULAR' // REGULAR, ABNORMAL
  ],

 ];
