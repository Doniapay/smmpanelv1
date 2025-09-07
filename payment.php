if ($method_name == 'doniapay') {
    $transactionId = $_REQUEST['transactionId'];
    if (empty($transactionId)) {
        $up_response = file_get_contents('php://input');
        $up_response_decode = json_decode($up_response, true);
        $transactionId = $up_response_decode['transactionId'];
    }

    if(empty($transactionId)){
        die('Direct access is not allowed.');
    }
    $apikey = $extras['api_key'];
$transaction_id = [
    'transaction_id' => $transactionId
];
$header   = array(
"api"               => $apikey,
"url"               => 'https://payment.doniapay.com/api/payment/verify',
);
$headers = array(
'Content-Type: application/json',
'donia-apikey: ' . $header['api'],
);

$url = $header['url'];
$curl = curl_init();

curl_setopt_array($curl, array(
CURLOPT_URL => $url,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_ENCODING => '',
CURLOPT_MAXREDIRS => 10,
CURLOPT_TIMEOUT => 0,
CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
CURLOPT_CUSTOMREQUEST => 'POST',
CURLOPT_POSTFIELDS => json_encode($transaction_id),
CURLOPT_HTTPHEADER => $headers
));

$response = curl_exec($curl);
curl_close($curl);


    if (!empty($response)) {
        $data = json_decode($response, true);

        if (!isset($data['status']) && !isset($data['metadata']['txnid'])) {
            die('Invalid Response.');
        }

        if (isset($data['status']) && $data['status'] == 'COMPLETED') {
            $me = json_decode($data['metadata'], true);
            $txnid = $me['txnid'];
            $paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:txnid");
            $paymentDetails->execute([
               "txnid" => $txnid
            ]);
          if ($paymentDetails->rowCount()) {
            $paymentDetails = $paymentDetails->fetch(PDO::FETCH_ASSOC);
            $row = $conn->prepare("SELECT * FROM clients WHERE client_id=:id");
            $row->execute(array("id" => $paymentDetails["client_id"]));
            $user = $row->fetch(PDO::FETCH_ASSOC);
            if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_method' => 71, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $txnid]])) {
                if ($data['status'] == 'COMPLETED') {
                    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                    $payment->execute(['extra' => $txnid]);
                    $payment = $payment->fetch(PDO::FETCH_ASSOC);
                    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                    if ($payment_bonus) {
                        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                        $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
                    } else {
                        $amount = $payment['payment_amount'];
                    }
                    $conn->beginTransaction();
                    $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                    $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                    $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");
                    if ($payment_bonus) {
                        $insert25->execute(array(
                            "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
                            "payment_amount" => $bonus_amount, "payment_method" =>  1, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
                            "bonus" => 1
                        ));
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }
                    if ($update && $balance) {
                        $conn->commit();
                        echo 'OK';
                    } else {
                        $conn->rollBack();
                        echo 'NO';
                    }
                } else {
                    $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
                    $update = $update->execute(['payment_status' => 2, 'client_id' => $user['client_id'], 'payment_method' => 71, 'payment_delivery' => 1, 'payment_extra' => $txnid]);
                }
            } else{
                errorExit("Payment Not Found");
            }
          }
        }
        header('Location:' . site_url('addfunds?success=true'));
    }
    exit('Invalid Request');
}
