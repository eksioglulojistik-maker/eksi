<?php
require __DIR__.'/inc/core.php';auth();$id=max(0,(int)($_GET['id']??0));redirect('quote.php?id='.$id.'#content-service_scope');
