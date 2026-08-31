<?php
/* ============================================================
   ANICALLS — Google Calendar OAuth configuration TEMPLATE

   Copy this file to `google-config.php` (same folder) and fill
   in the real values. `google-config.php` is git-ignored and is
   required by api/modal-booking.php.

   Credentials come from Google Cloud Console -> APIs & Services
   -> Credentials (OAuth 2.0 Client ID). The refresh token is
   obtained once via the OAuth consent flow for that client.
   ============================================================ */

define('GOOGLE_CLIENT_ID',     'YOUR_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
define('GOOGLE_REFRESH_TOKEN', 'YOUR_REFRESH_TOKEN');
define('GOOGLE_CALENDAR_ID',   'primary');
