if ($method_name == 'doniapay') {
    $transactionId = $_REQUEST['ids'] ?? $_REQUEST['transactionId'] ?? '';

    if (empty($transactionId)) {
        $up_response = file_get_contents('php://input');
        $up_response_decode = json_decode($up_response, true);
        $transactionId = $up_response_decode['ids'] ?? $up_response_decode['transactionId'] ?? '';
    }

    if (empty($transactionId)) {
        die('Direct access is not allowed.');
    }

    $apikey = $extras['api_key'];
    $apiUrl = 'https://api.doniapay.com/v2/order/synchronize/confirm';

    $post_data = [
        'transaction_id' => $transactionId
    ];

    $headers = [
        'Content-Type: application/json',
        'X-Signature-Key: ' . $apikey
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($post_data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    if (!empty($response)) {
        $data = json_decode($response, true);

        $meta = json_decode($data['dn_mt'] ?? $data['metadata'] ?? '{}', true);
        $txnid = $meta['txnid'] ?? '';

        if (empty($txnid)) {
            die('Transaction Metadata Missing.');
        }

        if (isset($data['status']) && in_array($data['status'], ['Paid', 'COMPLETED', 1, 'success'])) {
            $paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:txnid");
            $paymentDetails->execute(["txnid" => $txnid]);

            if ($paymentDetails->rowCount()) {
                $paymentDetails = $paymentDetails->fetch(PDO::FETCH_ASSOC);
                
                $row = $conn->prepare("SELECT * FROM clients WHERE client_id=:id");
                $row->execute(array("id" => $paymentDetails["client_id"]));
                $user = $row->fetch(PDO::FETCH_ASSOC);

                if (countRow(['table' => 'payments', 'where' => ['client_id' => $user['client_id'], 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $txnid]])) {
                    
                    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra');
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
                        $bonus_amount = 0;
                    }

                    $conn->beginTransaction();
                    try {
                        $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id');
                        $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

                        $balanceUpdate = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id');
                        $balanceUpdate->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

                        $report = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date');
                        
                        if ($payment_bonus) {
                            $insertBonus = $conn->prepare("INSERT INTO payments SET client_id=:client_id, client_balance=:client_balance, payment_amount=:payment_amount, payment_method=:payment_method, payment_status=:status, payment_delivery=:delivery, payment_note=:payment_note, payment_create_date=:payment_create_date, payment_extra=:payment_extra, bonus=:bonus");
                            $insertBonus->execute([
                                "client_id" => $payment['client_id'], 
                                "client_balance" => ($payment['balance'] + $amount - $bonus_amount),
                                "payment_amount" => $bonus_amount, 
                                "payment_method" => 1, 
                                'status' => 3, 
                                'delivery' => 2, 
                                "payment_note" => "Bonus added", 
                                "payment_create_date" => date('Y-m-d H:i:s'), 
                                "payment_extra" => "Bonus added for previous payment",
                                "bonus" => 1
                            ]);

                            $report->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment made with ' . $method['method_name'] . ' (Included ' . $payment_bonus['bonus_amount'] . '% bonus).', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                        } else {
                            $report->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                        }

                        $conn->commit();
                    } catch (Exception $e) {
                        $conn->rollBack();
                    }
                }
            } else {
                die("Payment Not Found");
            }
        } else {
            $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE payment_extra=:payment_extra AND payment_status=:pending');
            $update->execute(['payment_status' => 2, 'payment_extra' => $txnid, 'pending' => 1]);
        }
        header('Location:' . site_url('addfunds?success=true'));
        exit;
    }
    exit('Invalid Response from Gateway');
}
