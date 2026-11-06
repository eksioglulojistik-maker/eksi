<?php
require __DIR__.'/inc/core.php';auth();
if(!ekAdmin()){http_response_code(403);exit('Yönetici yetkisi gerekir.');}
redirect('system-check.php');
