<?php
// Copy this file outside the public /portal folder as portal-config.php.
// Never upload the real password into a publicly downloadable folder.
return [
    'dsn' => 'pgsql:host=YOUR_POSTGRES_HOST;port=5432;dbname=YOUR_DATABASE_NAME',
    'user' => 'YOUR_DATABASE_USER',
    'password' => 'YOUR_DATABASE_PASSWORD',
    'portal_base_url' => 'https://portal.e-nv.dk',
    'mail_from' => 'noreply@e-nv.dk',
    'meeting_attachment_dir' => __DIR__ . '/portal-private/meeting-attachments',
    'meeting_attachment_max_bytes' => 10485760,
    'profile_picture_dir' => __DIR__ . '/portal-private/profile-pictures',
    'profile_picture_max_bytes' => 2097152,
];
