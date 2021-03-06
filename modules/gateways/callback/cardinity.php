<?php
/**
 * Cardinity Payment Gateway 3D Secure Callback handler for WHMCS
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';

//Autoload Cardinity SDK
require_once __DIR__ . '/../cardinity/vendor/autoload.php';

use \Cardinity\Client;
use \Cardinity\Method\Payment;
use \Cardinity\Method\Refund;
use \Cardinity\Exception;
use WHMCS\Database\Capsule;

$whmcs->load_function('gateway');
$whmcs->load_function('invoice');

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$pares = $_POST['PaRes'];

// MD parameter contains invoice ID and cardinity transaction ID, separated by comma
$params = explode(',', $_POST['MD']);

$invoiceId = $params[0];
$transactionId = $params[1];

/**
 * Validate Callback Invoice ID.
 *
 * Checks invoice ID is a valid invoice number. Note it will count an
 * invoice in any status as valid.
 *
 * Performs a die upon encountering an invalid Invoice ID.
 *
 * Returns a normalised invoice ID.
 *
 * @param int $invoiceId Invoice ID
 * @param string $gatewayName Gateway Name
 */
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

/**
 * Check Callback Transaction ID.
 *
 * Performs a check for any existing transactions with the same given
 * transaction number.
 *
 * Performs a die upon encountering a duplicate.
 *
 * @param string $transactionId Unique Transaction ID
 */
checkCbTransID($transactionId);

//Select the api credentials by gateway mode
$gatewayMode = $gatewayParams['gatewayMode'];
if ($gatewayMode == 'Test') {
    $consumer_key = $gatewayParams['testConsumerKey'];
    $consumer_secret = $gatewayParams['testConsumerSecret'];
} else {
    $consumer_key = $gatewayParams['liveConsumerKey'];
    $consumer_secret = $gatewayParams['liveConsumerSecret'];
}

//Create Cardinity Client
$client = Client::create([
    'consumerKey' => $consumer_key,
    'consumerSecret' => $consumer_secret,
]);

//Create Cardinity finalize method
$method = new Cardinity\Method\Payment\Finalize($transactionId, $pares);

$isVerificationSuccessful = false; //Will be returned on redirect to invoice, indicates if payment is successful

try {
    //Call Cardinity API
    $result = $client->call($method);

    //Get payment status
    $status = $result->getStatus();

    if ($status == 'approved') { //3D secure verification approved

        $isVerificationSuccessful = true;
        logTransaction($gatewayParams['name'], ['Invoice ID' => $invoiceId], 'Success');

        //Add a token of credit card for later use
        addCreditCardToken($invoiceId, $result->getId());

        //Add payment information to given invoice ID
        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $result->getAmount(),
            0, // Payment Fee
            $gatewayModuleName
        );
    }
} catch (Exception\Request $exception) {
    $transactionInformation = array(
        'Invoice ID' => $invoiceId,
        'Error: ' => $exception->getErrorsAsString(),
    );
    logTransaction($gatewayParams['name'], $transactionInformation, 'Failure');
} catch (Exception\Runtime $exception) {
    $transactionInformation = array(
        'Invoice ID' => $invoiceId,
        'Error: ' => $exception->getMessage(),
    );
    logTransaction($gatewayParams['name'], $transactionInformation, 'Failure');
}

callback3DSecureRedirect($invoiceId, $isVerificationSuccessful);

/**
 * Undocumented function
 *
 * @param [type] $invoiceId Invoice ID
 * @param [type] $remoteTokenID Cardinity Transaction ID
 * @return void
 */
function addCreditCardToken($invoiceId, $remoteTokenID)
{
    $user = Capsule::table('tblinvoiceitems')->select('userid')
        ->where('invoiceid', $invoiceId)
        ->first();

    Capsule::table('tblclients')
        ->where('id', $user->userid)
        ->update([
            "gatewayid" => $remoteTokenID
        ]);
}
