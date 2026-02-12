<?php
/*
	FusionPBX - Linphone QR Code Generator
	Copyright (C) 2024 Tecnoadsl.net
*/

//includes
	include "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('linphone_qrcode_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//TURN server settings
	$turn_server = '185.29.147.43';
	$turn_port = '3478';
	$turn_username = 'olinphone';
	$turn_protocols = 'stun,turn,ice';

//settings
	$push_proxy = 'push.tecnoadsl.net';

//override push proxy based on domain
	$domain_name_temp = $_SESSION['domain_name'] ?? '';
	if (preg_match('/\.voip6\.tecnoadsl\.net$/', $domain_name_temp)) {
		$push_proxy = 'push6.tecnoadsl.net';
	} elseif (preg_match('/\.voip5\.tecnoadsl\.net$/', $domain_name_temp)) {
		$push_proxy = 'push5.tecnoadsl.net';
	}

//transport selection (default tls)
	$allowed_transports = array('tls', 'tcp', 'udp');
	$transport = isset($_GET['transport']) && in_array($_GET['transport'], $allowed_transports) ? $_GET['transport'] : 'tls';
	switch ($transport) {
		case 'tls': $sip_port = '5061'; break;
		case 'tcp': $sip_port = '5060'; break;
		case 'udp': $sip_port = '5060'; break;
	}

//registration duration selection (default 1 month)
	$allowed_reg_expires = array('3600', '86400', '604800', '2592000');
	$reg_expires = isset($_GET['reg_expires']) && in_array($_GET['reg_expires'], $allowed_reg_expires) ? $_GET['reg_expires'] : '2592000';

//get variables
	$domain_uuid = $_SESSION['domain_uuid'];
	$domain_name = $_SESSION['domain_name'];
	$user_uuid = $_SESSION['user_uuid'];

//get user's extensions
	$sql = "SELECT e.extension_uuid, e.extension, e.password, e.effective_caller_id_name ";
	$sql .= "FROM v_extensions e ";
	$sql .= "JOIN v_extension_users eu ON e.extension_uuid = eu.extension_uuid ";
	$sql .= "WHERE eu.user_uuid = :user_uuid ";
	$sql .= "AND e.domain_uuid = :domain_uuid ";
	$sql .= "AND e.enabled = 'true' ";
	$sql .= "ORDER BY e.extension ASC ";
	$parameters['user_uuid'] = $user_uuid;
	$parameters['domain_uuid'] = $domain_uuid;
	$database = new database;
	$extensions = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//select extension
	$selected_extension = null;
	$selected_extension_uuid = isset($_GET['extension_uuid']) ? $_GET['extension_uuid'] : '';

	if (!empty($extensions)) {
		foreach ($extensions as $ext) {
			if ($ext['extension_uuid'] == $selected_extension_uuid) {
				$selected_extension = $ext;
				break;
			}
		}
		if ($selected_extension === null) {
			$selected_extension = $extensions[0];
			$selected_extension_uuid = $selected_extension['extension_uuid'];
		}
	}

//generate provisioning URL
	$provisioning_url = '';
	if ($selected_extension) {
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
		$token_data = $selected_extension['extension_uuid'] . '|' . time();
		$iv = substr(md5($domain_uuid), 0, 16);
		$encrypted = openssl_encrypt($token_data, 'AES-256-CBC', $domain_uuid, 0, $iv);
		$token = base64_encode($encrypted);
		$provisioning_url = $base_url . '/app/linphone_qrcode/provisioning.php?token=' . urlencode($token) . '&transport=' . urlencode($transport) . '&reg_expires=' . urlencode($reg_expires);
	}

//get extension email from voicemail
	$extension_email = '';
	if ($selected_extension) {
		$sql = "SELECT voicemail_mail_to FROM v_voicemails ";
		$sql .= "WHERE domain_uuid = :domain_uuid ";
		$sql .= "AND voicemail_id = :voicemail_id ";
		$parameters['domain_uuid'] = $domain_uuid;
		$parameters['voicemail_id'] = $selected_extension['extension'];
		$database = new database;
		$row = $database->select($sql, $parameters, 'row');
		if (!empty($row['voicemail_mail_to'])) {
			$extension_email = $row['voicemail_mail_to'];
		}
		unset($sql, $parameters, $row);
	}

//handle send email POST
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email') {
		//validate CSRF token
		if (!empty($_SESSION['token']) && !empty($_POST['token']) && $_SESSION['token'] === $_POST['token']) {
			//invalidate token to prevent double submit
			unset($_SESSION['token']);

			$send_to = $_POST['email_to'] ?? '';
			if (!empty($send_to) && filter_var($send_to, FILTER_VALIDATE_EMAIL)) {
				//get QR code image from POST (base64 data URI from canvas)
				$qr_base64 = $_POST['qr_image'] ?? '';
				$qr_image_data = '';
				if (!empty($qr_base64) && preg_match('/^data:image\/png;base64,(.+)$/', $qr_base64, $matches)) {
					$qr_image_data = $matches[1];
				}

				//build email body - table-based layout for email client compatibility
				$email_body = '<html><body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">';
				$email_body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding: 20px 0;">';
				$email_body .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background: #ffffff; border-radius: 8px; border: 1px solid #e0e0e0;">';
				//header
				$email_body .= '<tr><td align="center" style="padding: 30px 30px 10px 30px;">';
				$email_body .= '<h2 style="color: #333; margin: 0; font-size: 22px;">'.$text['label-email_body_title'].'</h2>';
				$email_body .= '</td></tr>';
				//intro text
				$email_body .= '<tr><td align="center" style="padding: 10px 30px; color: #555; font-size: 14px; line-height: 1.5;">';
				$email_body .= $text['label-email_body_intro'];
				$email_body .= '</td></tr>';
				//qr code image
				if (!empty($qr_image_data)) {
					$email_body .= '<tr><td align="center" style="padding: 20px 30px;">';
					$email_body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td align="center" style="background: #fff; border: 1px solid #eee; padding: 10px;">';
					$email_body .= '<img src="cid:qrcode_image" width="250" height="250" alt="QR Code" style="display: block; width: 250px; height: 250px;" />';
					$email_body .= '</td></tr></table>';
					$email_body .= '</td></tr>';
				}
				//manual config title
				$email_body .= '<tr><td style="padding: 15px 30px 5px 30px; color: #555; font-size: 14px;">';
				$email_body .= $text['label-email_body_manual'];
				$email_body .= '</td></tr>';
				//config table
				$email_body .= '<tr><td style="padding: 5px 30px 25px 30px;">';
				$email_body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
				$td_label = 'style="padding: 10px 8px; border-bottom: 1px solid #eee; font-weight: bold; color: #333; width: 130px;"';
				$td_value = 'style="padding: 10px 8px; border-bottom: 1px solid #eee; font-family: monospace; font-size: 13px; color: #555;"';
				$email_body .= '<tr><td '.$td_label.'>'.$text['label-username'].'</td><td '.$td_value.'>'.htmlspecialchars($selected_extension['extension']).'</td></tr>';
				$email_body .= '<tr><td '.$td_label.'>'.$text['label-password'].'</td><td '.$td_value.'>'.htmlspecialchars($selected_extension['password']).'</td></tr>';
				$email_body .= '<tr><td '.$td_label.'>'.$text['label-domain'].'</td><td '.$td_value.'>'.htmlspecialchars($domain_name).'</td></tr>';
				$email_body .= '<tr><td '.$td_label.'>'.$text['label-proxy'].'</td><td '.$td_value.'>'.htmlspecialchars($push_proxy).'</td></tr>';
				$email_body .= '<tr><td '.$td_label.'>'.$text['label-transport'].'</td><td '.$td_value.'>'.strtoupper($transport).'</td></tr>';
				$email_body .= '<tr><td '.$td_label.'>'.$text['label-port'].'</td><td '.$td_value.'>'.$sip_port.'</td></tr>';
				$email_body .= '</table>';
				$email_body .= '</td></tr>';
				$email_body .= '</table>';
				$email_body .= '</td></tr></table>';
				$email_body .= '</body></html>';

				//send email - use direct method to support inline CID images
				$email = new email(array("domain_uuid" => $domain_uuid));
				$email->method = 'direct';
				$email->debug_level = 0;
				$email->recipients = $send_to;
				$email->subject = $text['label-email_subject'] . ' ' . htmlspecialchars($selected_extension['extension']);
				$email->body = $email_body;

				//attach QR code as inline image
				if (!empty($qr_image_data)) {
					$email->attachments = array(
						array(
							'mime_type' => 'image/png',
							'name' => 'qrcode.png',
							'base64' => $qr_image_data,
							'cid' => 'qrcode_image'
						)
					);
				}

				$sent = $email->send();
				if ($sent) {
					$_SESSION['email_flash'] = 'success';
				} else {
					$_SESSION['email_flash'] = 'error';
				}
			} else {
				$_SESSION['email_flash'] = 'no_address';
			}
		}

		//PRG redirect to prevent double submit on refresh
		$redirect_url = '?extension_uuid=' . urlencode($selected_extension_uuid) . '&transport=' . urlencode($transport) . '&reg_expires=' . urlencode($reg_expires);
		header('Location: ' . $redirect_url);
		exit;
	}

//read flash message from session
	$email_message = '';
	$email_message_type = '';
	if (!empty($_SESSION['email_flash'])) {
		switch ($_SESSION['email_flash']) {
			case 'success':
				$email_message = $text['label-email_sent'];
				$email_message_type = 'success';
				break;
			case 'error':
				$email_message = $text['label-email_error'];
				$email_message_type = 'error';
				break;
			case 'no_address':
				$email_message = $text['label-email_no_address'];
				$email_message_type = 'error';
				break;
		}
		unset($_SESSION['email_flash']);
	}

//generate CSRF token
	$_SESSION['token'] = bin2hex(random_bytes(32));

//include the header
	$document['title'] = $text['title-linphone_qrcode'];
	require_once "resources/header.php";

?>

<style>
.qr-container {
	text-align: center;
	max-width: 550px;
	margin: 20px auto;
	padding: 25px;
	background: #fff;
	border-radius: 10px;
	box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.qr-code {
	margin: 15px 0;
	padding: 15px;
	background: #fff;
	display: inline-block;
	border-radius: 8px;
}
.config-table {
	width: 100%;
	margin: 20px 0;
	text-align: left;
	border-collapse: collapse;
}
.config-table td {
	padding: 10px 8px;
	border-bottom: 1px solid #eee;
}
.config-table td:first-child {
	font-weight: 600;
	width: 130px;
	color: #555;
}
.config-table td:last-child {
	font-family: monospace;
	font-size: 13px;
}
.copy-btn {
	padding: 3px 10px;
	font-size: 11px;
	cursor: pointer;
	background: #f0f0f0;
	border: 1px solid #ddd;
	border-radius: 3px;
	margin-left: 8px;
}
.copy-btn:hover {
	background: #e0e0e0;
}
.pwd-toggle {
	color: #007bff;
	cursor: pointer;
	font-size: 12px;
	margin-left: 10px;
}
.pwd-toggle:hover {
	text-decoration: underline;
}
.instructions {
	background: #f8f9fa;
	padding: 18px;
	border-radius: 8px;
	margin: 20px 0;
	text-align: left;
	border-left: 4px solid #007bff;
}
.instructions h4 {
	margin: 0 0 12px 0;
	color: #333;
}
.instructions ol {
	margin: 0;
	padding-left: 20px;
}
.instructions li {
	padding: 5px 0;
	color: #555;
}
.download-btns {
	margin: 25px 0 10px 0;
}
.download-btns a {
	display: inline-block;
	padding: 12px 25px;
	margin: 5px 10px;
	border-radius: 6px;
	text-decoration: none;
	font-weight: 600;
	transition: transform 0.2s;
}
.download-btns a:hover {
	transform: scale(1.05);
}
.download-btns .ios {
	background: #000;
	color: #fff;
}
.download-btns .android {
	background: #3ddc84;
	color: #000;
}
.error-box {
	background: #f8d7da;
	color: #721c24;
	padding: 20px;
	border-radius: 8px;
	margin: 20px 0;
}
.email-btn {
	display: inline-block;
	padding: 10px 25px;
	margin: 10px 0;
	background: #007bff;
	color: #fff;
	border: none;
	border-radius: 6px;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.2s;
}
.email-btn:hover {
	background: #0056b3;
}
.email-btn:disabled {
	background: #6c757d;
	cursor: not-allowed;
}
.email-msg {
	padding: 10px 15px;
	border-radius: 6px;
	margin: 10px 0;
	font-weight: 500;
}
.email-msg.success {
	background: #d4edda;
	color: #155724;
	border: 1px solid #c3e6cb;
}
.email-msg.error {
	background: #f8d7da;
	color: #721c24;
	border: 1px solid #f5c6cb;
}
.settings-section {
	margin-top: 25px;
	padding-top: 20px;
	border-top: 1px solid #eee;
}
.settings-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 15px;
	text-align: left;
}
.setting-card {
	background: #f8f9fa;
	border: 1px solid #e9ecef;
	border-radius: 8px;
	padding: 14px;
}
.setting-card label {
	display: block;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: #6c757d;
	margin-bottom: 8px;
}
.setting-card select {
	width: 100%;
	padding: 8px 10px;
	font-size: 14px;
	border-radius: 5px;
	border: 1px solid #ced4da;
	background: #fff;
	color: #333;
	cursor: pointer;
	appearance: auto;
}
.setting-card select:focus {
	border-color: #007bff;
	outline: none;
	box-shadow: 0 0 0 2px rgba(0,123,255,0.15);
}
</style>

<div class="action_bar" id="action_bar">
	<div class="heading"><b><?php echo $text['title-linphone_qrcode']; ?></b></div>
	<div class="actions"></div>
	<div style="clear: both;"></div>
</div>

<?php if (empty($extensions)): ?>
<div class="qr-container">
	<div class="error-box">
		<strong><?php echo $text['error-no_extension']; ?></strong>
	</div>
</div>
<?php else: ?>
<div class="qr-container">

	<!-- QR Code in alto -->
	<p><?php echo $text['description-linphone_qrcode']; ?></p>

	<div class="qr-code" id="qrcode"></div>

	<!-- Pulsante Invia via Email -->
	<?php if (!empty($email_message)): ?>
	<div class="email-msg <?php echo $email_message_type; ?>"><?php echo $email_message; ?></div>
	<?php endif; ?>

	<form id="email_form" method="post" action="">
		<input type="hidden" name="action" value="send_email">
		<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>">
		<input type="hidden" name="extension_uuid" value="<?php echo htmlspecialchars($selected_extension_uuid); ?>">
		<input type="hidden" name="transport" value="<?php echo htmlspecialchars($transport); ?>">
		<input type="hidden" name="reg_expires" value="<?php echo htmlspecialchars($reg_expires); ?>">
		<input type="hidden" name="email_to" value="<?php echo htmlspecialchars($extension_email); ?>">
		<input type="hidden" name="qr_image" id="qr_image_data" value="">
		<button type="submit" class="email-btn" id="send_email_btn" <?php if (empty($extension_email)) echo 'disabled'; ?>>
			&#9993; <?php echo $text['label-send_email']; ?>
			<?php if (!empty($extension_email)): ?>
				(<?php echo htmlspecialchars($extension_email); ?>)
			<?php endif; ?>
		</button>
		<?php if (empty($extension_email)): ?>
		<br><small style="color: #999;"><?php echo $text['label-email_no_address']; ?></small>
		<?php endif; ?>
	</form>

	<!-- Settings sotto -->
	<div class="settings-section">
		<div class="settings-grid">

			<?php if (count($extensions) > 1): ?>
			<div class="setting-card">
				<label><?php echo $text['label-select_extension']; ?></label>
				<select onchange="location.href='?extension_uuid='+this.value+'&transport=<?php echo urlencode($transport); ?>&reg_expires=<?php echo urlencode($reg_expires); ?>'">
					<?php foreach ($extensions as $ext): ?>
					<option value="<?php echo $ext['extension_uuid']; ?>" <?php if($ext['extension_uuid']==$selected_extension_uuid) echo 'selected'; ?>>
						<?php echo htmlspecialchars($ext['extension']); ?>
						<?php if($ext['effective_caller_id_name']) echo ' - '.htmlspecialchars($ext['effective_caller_id_name']); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>

			<div class="setting-card">
				<label><?php echo $text['label-transport']; ?></label>
				<select onchange="location.href='?extension_uuid=<?php echo urlencode($selected_extension_uuid); ?>&transport='+this.value+'&reg_expires=<?php echo urlencode($reg_expires); ?>'">
					<option value="tls" <?php if($transport=='tls') echo 'selected'; ?>>TLS (<?php echo $text['label-recommended']; ?>)</option>
					<option value="tcp" <?php if($transport=='tcp') echo 'selected'; ?>>TCP</option>
					<option value="udp" <?php if($transport=='udp') echo 'selected'; ?>>UDP</option>
				</select>
			</div>

			<div class="setting-card">
				<label><?php echo $text['label-reg_expires']; ?></label>
				<select onchange="location.href='?extension_uuid=<?php echo urlencode($selected_extension_uuid); ?>&transport=<?php echo urlencode($transport); ?>&reg_expires='+this.value">
					<option value="3600" <?php if($reg_expires=='3600') echo 'selected'; ?>><?php echo $text['label-1_hour']; ?></option>
					<option value="86400" <?php if($reg_expires=='86400') echo 'selected'; ?>><?php echo $text['label-1_day']; ?></option>
					<option value="604800" <?php if($reg_expires=='604800') echo 'selected'; ?>><?php echo $text['label-1_week']; ?></option>
					<option value="2592000" <?php if($reg_expires=='2592000') echo 'selected'; ?>><?php echo $text['label-1_month']; ?> (<?php echo $text['label-recommended']; ?>)</option>
				</select>
			</div>

		</div>
	</div>

	<table class="config-table">
		<tr>
			<td><?php echo $text['label-username']; ?>:</td>
			<td>
				<span id="val_user"><?php echo htmlspecialchars($selected_extension['extension']); ?></span>
				<button class="copy-btn" onclick="copyText('val_user')">&#128203; <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-password']; ?>:</td>
			<td>
				<span id="val_pwd">&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;</span>
				<span class="pwd-toggle" onclick="togglePwd()"><?php echo $text['label-show_password']; ?></span>
				<button class="copy-btn" onclick="copyPwd()">&#128203; <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-domain']; ?>:</td>
			<td>
				<span id="val_domain"><?php echo htmlspecialchars($domain_name); ?></span>
				<button class="copy-btn" onclick="copyText('val_domain')">&#128203; <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-proxy']; ?>:</td>
			<td>
				<span id="val_proxy"><?php echo htmlspecialchars($push_proxy); ?></span>
				<button class="copy-btn" onclick="copyText('val_proxy')">&#128203; <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-transport']; ?>:</td>
			<td><?php echo strtoupper($transport); ?></td>
		</tr>
		<tr>
			<td><?php echo $text['label-port']; ?>:</td>
			<td><?php echo $sip_port; ?></td>
		</tr>
		<tr>
			<td><?php echo $text['label-reg_expires']; ?>:</td>
			<td><?php
				switch ($reg_expires) {
					case '3600': echo $text['label-1_hour']; break;
					case '86400': echo $text['label-1_day']; break;
					case '604800': echo $text['label-1_week']; break;
					case '2592000': echo $text['label-1_month']; break;
				}
			?></td>
		</tr>
		<tr>
			<td><?php echo $text['label-stun_server']; ?>:</td>
			<td>
				<span id="val_stun"><?php echo htmlspecialchars($turn_server . ':' . $turn_port); ?></span>
				<button class="copy-btn" onclick="copyText('val_stun')">&#128203; <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-stun_username']; ?>:</td>
			<td>
				<span id="val_turn_user"><?php echo htmlspecialchars($turn_username); ?></span>
				<button class="copy-btn" onclick="copyText('val_turn_user')">&#128203; <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-ice_protocols']; ?>:</td>
			<td><?php echo htmlspecialchars($turn_protocols); ?></td>
		</tr>
	</table>

	<div class="instructions">
		<h4><?php echo $text['label-instructions']; ?></h4>
		<ol>
			<li><?php echo $text['label-instruction_1']; ?></li>
			<li><?php echo $text['label-instruction_2']; ?></li>
			<li><?php echo $text['label-instruction_3']; ?></li>
			<li><?php echo $text['label-instruction_4']; ?></li>
		</ol>
	</div>

	<div class="download-btns">
		<a href="https://apps.apple.com/app/linphone/id360065638" target="_blank" class="ios">
			&#127822; iOS
		</a>
		<a href="https://play.google.com/store/apps/details?id=org.linphone" target="_blank" class="android">
			&#129302; Android
		</a>
	</div>

</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
var actualPwd = "<?php echo addslashes($selected_extension['password']); ?>";
var pwdVisible = false;

<?php if($provisioning_url): ?>
new QRCode(document.getElementById("qrcode"), {
	text: "<?php echo addslashes($provisioning_url); ?>",
	width: 250,
	height: 250,
	colorDark: "#000000",
	colorLight: "#ffffff",
	correctLevel: QRCode.CorrectLevel.M
});
<?php endif; ?>

//capture QR code image before form submit
document.getElementById('email_form').addEventListener('submit', function(e) {
	var canvas = document.querySelector('#qrcode canvas');
	if (canvas) {
		document.getElementById('qr_image_data').value = canvas.toDataURL('image/png');
	}
	var btn = document.getElementById('send_email_btn');
	btn.disabled = true;
	btn.innerHTML = '&#9993; <?php echo addslashes($text['label-sending']); ?>';
});

function togglePwd() {
	var el = document.getElementById('val_pwd');
	var toggle = document.querySelector('.pwd-toggle');
	if (pwdVisible) {
		el.textContent = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
		toggle.textContent = '<?php echo $text['label-show_password']; ?>';
		pwdVisible = false;
	} else {
		el.textContent = actualPwd;
		toggle.textContent = '<?php echo $text['label-hide_password']; ?>';
		pwdVisible = true;
	}
}

function copyText(id) {
	var text = document.getElementById(id).textContent;
	navigator.clipboard.writeText(text).then(function() {
		alert('<?php echo $text['label-copied']; ?>');
	});
}

function copyPwd() {
	navigator.clipboard.writeText(actualPwd).then(function() {
		alert('<?php echo $text['label-copied']; ?>');
	});
}
</script>

<?php
//include the footer
	require_once "resources/footer.php";
?>
