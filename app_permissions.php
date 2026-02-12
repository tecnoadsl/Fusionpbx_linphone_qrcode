<?php
//permission details
$y=0;
$apps[$x]['permissions'][$y]['name'] = "linphone_qrcode_view";
$apps[$x]['permissions'][$y]['groups'][] = "superadmin";

$y++;
$apps[$x]['permissions'][$y]['name'] = "linphone_qrcode_view";
$apps[$x]['permissions'][$y]['groups'][] = "admin";

$y++;
$apps[$x]['permissions'][$y]['name'] = "linphone_qrcode_view";
$apps[$x]['permissions'][$y]['groups'][] = "user";
?>
