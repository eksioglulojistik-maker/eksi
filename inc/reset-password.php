<?php
require_once __DIR__.'/core.php';
function ekResetReady(){try{return (bool)db()->query("SHOW TABLES LIKE 'ek_password_resets'")->fetchColumn();}catch(Throwable $e){return false;}}
function ekResetThrottle(){require_once __DIR__.'/login-throttle.php';return ekLoginAttempt();}
function ekResetUrl($token){return 'https://panel.eksioglulojistik.com/reset-password.php?token='.rawurlencode($token);}
