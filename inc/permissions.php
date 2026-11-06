<?php
require_once __DIR__.'/core.php';
function ek_user_role(): string {
  if (empty($_SESSION['uid'])) return 'guest';
  try {
    $s=db()->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
    $s->execute([(int)$_SESSION['uid']]);
    return strtolower((string)($s->fetchColumn() ?: 'staff'));
  } catch(Throwable $e) {return 'staff';}
}
function ek_require_admin(): void {
  auth();
  if (!in_array(ek_user_role(), ['admin','administrator'], true)) {
    http_response_code(403);
    exit('Bu işlem yalnızca yöneticilere açıktır.');
  }
}
