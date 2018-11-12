<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

$gateway = getGatewayVariables($gatewayModuleName);

if (!$gateway["type"]) {
    die("Module Not Activated"); // Checks gateway module is active before accepting callback
}

// Get Returned Variables
$requestBody = file_get_contents("php://input");

$key = $gateway['private_key'];
$checksum = hash_hmac("sha256", $requestBody, $key);

if ($checksum === $_SERVER["HTTP_COOLPAY_CHECKSUM_SHA256"]) {
    $request = json_decode($requestBody);
    $operation = end($request->operations);
    $invoiceid = $request->order_id;

    // Strip prefix if any
    $prefix = '';
    if (isset($gateway['prefix'])) {
        $invoiceid = substr($invoiceid, strlen($gateway['prefix']));
    }

    $transid = $request->id;
    $amount = ($operation->amount / 100.0);

    // In order to find any added fee, we must find the original order amount in the database
    $table = "tblinvoices";
    $fields = "id,total";
    $where = array("id" => $invoiceid);
    $result = select_query($table, $fields, $where);
    $data = mysql_fetch_array($result);
    $id = $data['id'];
    $amount_orig = $data['total'];

    // Now calculate the fee
    $fee = $amount - $amount_orig;

    $invoiceid = checkCbInvoiceID($invoiceid, $gateway["name"]); // Checks invoice ID is a valid invoice number or ends processing

    checkCbTransID($transid); // Checks transaction number isn't already in the database and ends processing if it does

    // Request is accepted, its authorize and cp status is ok, make order paid
    if ($request->accepted && $operation->type=='authorize' && $operation->cp_status_code == "20000") {
        $values = array();
        $adminuser = $gateway['whmcs_adminname']; // We need the admin username for api commands
        $command = "addinvoicepayment";
        $values["invoiceid"] = $invoiceid;
        $values["transid"] = $transid;
        $values["amount"] = $amount;
        $values["fee"] = $fee;
        $values["gateway"] = $gatewayModuleName;
        $results = localAPI($command,$values,$adminuser);

        // Add the fee to the invoice
        if ($fee>0) {
            $command = "updateinvoice";
            $values["invoiceid"] = $invoiceid;
            $values["newitemdescription"] = array("Payment fee");
            $values["newitemamount"] = array($fee);
            $values["newitemtaxed"] = array("0");
            $results = localAPI($command,$values,$adminuser);
        }

        logTransaction($gateway["name"], $_POST, "Successful"); // Save to Gateway Log: name, data array, status
    } else {
        logTransaction($gateway["name"], $_POST, "Unsuccessful"); // Save to Gateway Log: name, data array, status
    }
} else {
    logTransaction($gateway["name"], $_POST, "Bad private key in callback, check configuration"); // Save to Gateway Log: name, data array, status
}