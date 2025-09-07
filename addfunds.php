if ($_GET && $_GET["success"]) :
    $success = 1;
    $successText = "Your payment paid successfully";
endif;

if ($_GET && $_GET["cancel"]) :
    $error = 1;
    $errorText = "Your payment cancelled successfully";
endif;






elseif ($method_id == 133) :
$apiKey = $extra['api_key'];
$apiUrl = "https://payment.doniapay.com/api/payment/create";

$final_amount = $amount * $extra['exchange_rate'];
$txnid = substr(hash('sha256', mt_rand() . microtime()), 0, 20);

$posted = [
	'cus_name' => isset($user['username']) ? $user['username'] : 'John Doe',
	'cus_email' => $user['email'],
	'amount' => $final_amount,
	'success_url' => site_url('payment/doniapay'),
	'cancel_url' => site_url('addfunds?cancel=true'),
	'metadata' => [
		'txnid'   => $txnid
	]
];


      $curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($requestData),
    CURLOPT_HTTPHEADER => [
        "donia-apikey: " . $apiKey,
        "content-type: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);
if ($err) {
    errorExit("cURL Error #:" . $err);
} 
$result = json_decode($response, true);
if ($result['status']) {
	$order_id = $txnid;
	$insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
	$insert->execute(array("c_id" => $user['client_id'], "amount" => $amount, "code" => $paymentCode, "method" => $method_id, "date" => date("Y.m.d H:i:s"), "ip" => GetIP(), "extra" => $order_id));
	if ($insert) {
		$payment_url = $result['payment_url'];
	}
} else {
	echo $result['message'];
	exit();
}
echo '<div class="dimmer active" style="min-height: 400px;">
	<div class="loader"></div>
	<div class="dimmer-content">
		<center>
			<h2>Please do not refresh this page</h2>
		</center>
		<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin:auto;background:#fff;display:block;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">
			<circle cx="50" cy="50" r="32" stroke-width="8" stroke="#e15b64" stroke-dasharray="50.26548245743669 50.26548245743669" fill="none" stroke-linecap="round">
				<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>
			</circle>
			<circle cx="50" cy="50" r="23" stroke-width="8" stroke="#f8b26a" stroke-dasharray="36.12831551628262 36.12831551628262" stroke-dashoffset="36.12831551628262" fill="none" stroke-linecap="round">
				<animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;-360 50 50"></animateTransform>
			</circle>
		</svg>
		<form action="' . $payment_url . '" method="get" name="doniapayForm" id="pay">
			<script type="text/javascript">
				document.getElementById("pay").submit();
			</script>
		</form>
	</div>
</div>';
