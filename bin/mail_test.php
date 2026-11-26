<?php
require __DIR__.'/../lib/bootstrap.php'; require __DIR__.'/../lib/mailer.php';
$to = $argv[1] ?? exit("usage: php bin/mail_test.php you@example.com\n");
echo send_action_mail($to, 'Email test - XAU/USD Terminal', 'Your email setup works', 'This is a test of the new branded email design.',
  'Open dashboard', app_url().'/dashboard.php', 'If you received this, SMTP and the template are both working.') ? "sent OK\n" : "FAILED (see error above)\n";