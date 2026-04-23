if ($_GET && isset($_GET["success"])) :
    $success = 1;
    $successText = "Your payment paid successfully";
endif;

if ($_GET && isset($_GET["cancel"])) :
    $error = 1;
    $errorText = "Your payment cancelled successfully";
endif;

elseif ($method_id == 133) :
$apiKey = $extra['api_key'];
$apiUrl = "https://api.doniapay.com/order/synchronize/prepare";

$final_amount = $amount * $extra['exchange_rate'];
$txnid = substr(hash('sha256', mt_rand() . microtime()), 0, 20);

$rawData = [
    "dn_su"  => site_url('addfunds?success=true'),
    "dn_cu"  => site_url('addfunds?cancel=true'),
    "dn_wu"  => site_url('payment/doniapay'),
    "dn_am"  => round($final_amount, 2),
    "dn_cn"  => isset($user['username']) ? $user['username'] : 'Customer',
    "dn_ce"  => $user['email'],
    "dn_mt"  => json_encode(["txnid" => $txnid]),
    "dn_rt"  => "GET"
];

$payload = base64_encode(json_encode($rawData));
$signature = hash_hmac('sha256', $payload, $apiKey);

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode(['dp_payload' => $payload]),
    CURLOPT_HTTPHEADER => [
        "X-Signature-Key: " . $apiKey,
        "donia-signature: " . $signature,
        "Content-Type: application/json"
    ],
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    errorExit("cURL Error #:" . $err);
}

$result = json_decode($response, true);

if (isset($result['status']) && $result['status'] == 'success') {
    $order_id = $txnid;
    $insert = $conn->prepare("INSERT INTO payments SET client_id=:c_id, payment_amount=:amount, payment_privatecode=:code, payment_method=:method, payment_create_date=:date, payment_ip=:ip, payment_extra=:extra");
    $insert->execute(array(
        "c_id" => $user['client_id'], 
        "amount" => $amount, 
        "code" => $paymentCode, 
        "method" => $method_id, 
        "date" => date("Y.m.d H:i:s"), 
        "ip" => GetIP(), 
        "extra" => $order_id
    ));
    
    if ($insert) {
        $payment_url = $result['payment_url'];
    }
} else {
    echo $result['message'] ?? "Payment initialization failed";
    exit();
}

echo '<div class="dimmer active" style="min-height: 400px;">
    <div class="loader"></div>
    <div class="dimmer-content">
        <center>
            <h2>Please do not refresh this page</h2>
        </center>
        <svg xmlns="http://www.w3.org/2000/svg" style="margin:auto;background:#fff;display:block;" width="200px" height="200px" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r="32" stroke-width="8" stroke="#e15b64" stroke-dasharray="50.26 50.26" fill="none" stroke-linecap="round">
                <animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>
            </circle>
            <circle cx="50" cy="50" r="23" stroke-width="8" stroke="#f8b26a" stroke-dasharray="36.12 36.12" fill="none" stroke-linecap="round">
                <animateTransform attributeName="transform" type="rotate" dur="1s" repeatCount="indefinite" keyTimes="0;1" values="0 50 50;-360 50 50"></animateTransform>
            </circle>
        </svg>
        <form action="' . $payment_url . '" method="get" name="doniapayForm" id="pay"></form>
        <script type="text/javascript">
            document.getElementById("pay").submit();
        </script>
    </div>
</div>';
