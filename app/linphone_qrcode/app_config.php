<?php
	//application details
	$apps[$x]['name'] = "Linphone QR Code";
	$apps[$x]['uuid'] = "a1b2c3d4-e5f6-7890-abcd-ef1234567890";
	$apps[$x]['category'] = "Accounts";
	$apps[$x]['subcategory'] = "";
	$apps[$x]['version'] = "1.0.1";
	$apps[$x]['license'] = "Mozilla Public License 1.1";
	$apps[$x]['url'] = "https://www.tecnoadsl.net";
	$apps[$x]['description']['it-it'] = "Genera QR code per configurare l'app Linphone";
	$apps[$x]['description']['en-us'] = "Generate QR codes for Linphone app configuration";

	//menu details
	$y=0;
	$apps[$x]['menu'][$y]['title']['it-it'] = "QR Code Linphone";
	$apps[$x]['menu'][$y]['title']['en-us'] = "Linphone QR Code";
	$apps[$x]['menu'][$y]['uuid'] = "b2c3d4e5-f6a7-8901-bcde-f23456789012";
	$apps[$x]['menu'][$y]['parent_uuid'] = "PARENT_UUID_PLACEHOLDER";
	$apps[$x]['menu'][$y]['category'] = "internal";
	$apps[$x]['menu'][$y]['icon'] = "fa-qrcode";
	$apps[$x]['menu'][$y]['path'] = "/app/linphone_qrcode/qrcode.php";
	$apps[$x]['menu'][$y]['order'] = "";
	$apps[$x]['menu'][$y]['groups'][] = "superadmin";
	$apps[$x]['menu'][$y]['groups'][] = "admin";
	$apps[$x]['menu'][$y]['groups'][] = "user";

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
