<?php

define('DS', str_replace('\\', '/', DIRECTORY_SEPARATOR));
define('ROOT_PATH', dirname(__FILE__));
// include(ROOT_PATH . DS . 'Utils/AlepayUtils.php');
include(ROOT_PATH . DS . './Utils/AlepayUtils.php');
error_log('ROOT_PATH '. ROOT_PATH);
/*
 * Alepay class
 * Implement with Alepay service
 */

class Alepay {

    protected $alepayUtils;
    protected $encryptKey = "";
    protected $checksumKey = "";
    protected $apiKey = "";
    protected $callbackUrl = "";
    protected $isTestMode = true;

    protected $baseURL = array();

    protected $env = 'sanbox';
    protected $URI = array(
        'requestPayment' => '/request-payment',
        'requestOrder' => '/checkout/v1/request-order',
        'calculateFee' => '/checkout/v1/calculate-fee',
        'getTransactionInfoV1' => '/checkout/v1/get-transaction-info',
        'requestCardLink' => '/checkout/v1/request-profile',
        'getTransactionInfo' => '/get-transaction-info',
        'tokenizationPayment' => '/checkout/v1/request-tokenization-payment',
        'tokenizationPaymentDomestic' => '/checkout/v1/request-tokenization-payment-domestic',
        'cancelCardLink' => '/checkout/v1/cancel-profile',
        'requestCardLinkDomestic' => '/alepay-card-domestic/request-profile',
        'getListBanks' => '/get-list-banks',
    );


    public function __construct($opts) {
  // header('Access-Control-Allow-Origin: *');
        // header("Access-Control-Allow-Credentials: true");
        // header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
        // header('Access-Control-Max-Age: 1000');
        // header('Access-Control-Allow-Headers: Content-Type, Content-Range, Content-Disposition, Content-Description');

        /*
        * Require curl and json extension
        */

        if (!function_exists('curl_init')) {
            throw new \Exception('Alepay needs the CURL PHP extension.');
        }
        if (!function_exists('json_decode')) {
            throw new \Exception('Alepay needs the JSON PHP extension.');
        }

        // set KEY
        if (isset($opts) && !empty($opts["apiKey"])) {
            $this->apiKey = $opts["apiKey"];
        } else {
            throw new \Exception("API key is required !");
        }
        if (isset($opts) && !empty($opts["encryptKey"])) {
            $this->encryptKey = $opts["encryptKey"];
        } else {
            throw new \Exception("Encrypt key is required !");
        }
        if (isset($opts) && !empty($opts["checksumKey"])) {
            $this->checksumKey = $opts["checksumKey"];
        } else {
            throw new \Exception("Checksum key is required !");
        }
        if (isset($opts) && !empty($opts["callbackUrl"])) {
            $this->callbackUrl = $opts["callbackUrl"];
        }

        if (isset($opts) && !empty($opts["is_test_mode"])) {
            $this->isTestMode = $opts["is_test_mode"];
        }

        if (isset($opts) && !empty($opts["base_urls"])) {
            $this->baseURL = $opts["base_urls"];
        }

        if ($this->isTestMode) {
            $this->env = 'sanbox';
        } else {
            $this->env = 'live';
        }

        error_log('before init alepay utility');
        $this->alepayUtils = new \AlepayUtils();
        error_log('after init alepay utility');
    }

     /**
     * getListBanksRequest - get bankCode domestic for ATM, Internetbanking, QR code
     */
    public function getListBanksRequest($data)
    {
        $url = $this->baseURL[$this->env] . $this->URI['getListBanks'];
        $data['tokenKey'] = $this->apiKey;
        $resolve = $this->sendRequestToAlepay($data, $url);
        return $resolve;
    }

    /*
    * sendOrder - Send order information to Alepay service
    * @param array|null $data
    */

    public function sendOrderToAlepayDomesticATM($data)
    {
        error_log(__METHOD__);
        $url = $this->baseURL[$this->env] . $this->URI['requestPayment'];
        if ($this->env == 'sanbox') {
            $url = $this->baseURL['sanbox']['v3'] . $this->URI['requestPayment'];
        }
        $data['tokenKey'] = $this->apiKey;
        $signature = $this->alepayUtils->makeSignature($data, $this->checksumKey);
        $data['signature'] = $signature;
        error_log('sendOrderToAlepayDomestic ' . $url);
        $result = $this->sendRequestToAlepayV3($data, $url);
        return $result;
    }

    private function sendRequestToAlepayV3($data, $url)
    {

        error_log(print_r($data, true));
        $data_string = json_encode($data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));
        $result = curl_exec($ch);
        if (!$result) {
            error_log('Connection Failure');
        } else {
            error_log('Connection Successful');
        }
        curl_close($ch);
        return json_decode($result);
    }

    public function sendRequestOrderInternational($data)
    {

        $url = $this->baseURL[$this->env] . $this->URI['requestOrder'];
        if ($this->env == 'sanbox') {
            $url = $this->baseURL['sanbox']['v1'] . $this->URI['requestOrder'];
        }
        error_log('requestOrder ' . $url);
        error_log(print_r($data,true));
        // $data['token'] = $this->apiKey;
        $result = $this->sendRequestToAlepay($data, $url);
        return $result;
    }


    /*
     * sendOrder - Send order information to Alepay service
     * @param array|null $data
     */

    // public function sendOrderToAlepay($data) {
    //     // get demo data
    //     // $data = $this->createCheckoutData();
    //     $data['returnUrl'] = $this->callbackUrl;
    //     // $data['cancelUrl'] = 'http://' . $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . '/demo-alepay';
    //     $url = $this->baseURL[$this->env] . $this->URI['requestPayment'];
    //     $result = $this->sendRequestToAlepay($data, $url);
    //     if (isset($result) && $result->errorCode == '000') {
    //         $dataDecrypted = $this->alepayUtils->decryptData($result->data, $this->encryptKey);
    //         return json_decode($dataDecrypted);
    //     } else {
    //         return $result;
    //     }
    // }

    // public function sendOrderToAlepayDomestic($data) {
    //     // get demo data
    //     // $data = $this->createCheckoutDomesticData();
    //     $data = [];
    //     $data['returnUrl'] = $this->callbackUrl;
    //     //  $data['cancelUrl'] = 'http://' . $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . '/demo-alepay';
    //     $url = $this->baseURL[$this->env] . $this->URI['requestPayment'];
    //     $result = $this->sendRequestToAlepay($data, $url);
    //     if ($result->errorCode == '000') {
    //         $dataDecrypted = $this->alepayUtils->decryptData($result->data, $this->encryptKey);
    //         return json_decode($dataDecrypted);
    //     } else {
    //         echo json_encode($result);
    //     }
    // }

    /*
     * get transaction info from Alepay
     * @param array|null $data
     */

    public function getTransactionInfo($transactionCode) {

        $data = array('transactionCode' => $transactionCode);
        $url = $this->baseURL[$this->env] . $this->URI['getTransactionInfo'];
        $result = $this->sendRequestToAlepay($data, $url);
        return $result;
    }

    /*
     * sendCardLinkRequest - Send user's profile info to Alepay service
     * return: cardlink url
     * @param array|null $data
     */

    public function sendCardLinkRequest($data) {
      
        // $url = $this->baseURL[$this->env] . $this->URI['requestCardLink'];
        $url = $this->baseURL[$this->env] . $this->URI['requestCardLink'];
        if ($this->env == 'sanbox') {
            $url = $this->baseURL['sanbox']['v1'] . $this->URI['requestCardLink'];
        }

        $result = $this->sendRequestToAlepay($data, $url);
        if ($result->errorCode == '000') {
            $dataDecrypted = $this->alepayUtils->decryptData($result->data, $this->encryptKey);
            return json_decode($dataDecrypted);
        } else {
            return $result;
        }
    }

    public function sendCardLinkDomesticRequest() {
        // get demo data
        //$data = $this->createRequestCardLinkDataDomestic();
        $data= [];
        $url = $this->baseURL[$this->env] . $this->URI['requestCardLinkDomestic'];
        $result = $this->sendRequestToAlepay($data, $url);
        if ($result->errorCode == '000') {
            $dataDecrypted = $this->alepayUtils->decryptData($result->data, $this->encryptKey);
            return json_decode($dataDecrypted);
        } else {
            return $result;
        }
    }

    public function sendTokenizationPayment($tokenization) {

        //$data = $this->createTokenizationPaymentData($tokenization);
        $data = [];
        $url = $this->baseURL[$this->env] . $this->URI['tokenizationPayment'];
        $result = $this->sendRequestToAlepay($data, $url);
        if ($result->errorCode == '000') {
            $dataDecrypted = $this->alepayUtils->decryptData($result->data, $this->encryptKey);
            return json_decode($dataDecrypted);
        } else {
            return $result;
        }
    }

    public function sendTokenizationPaymentDomestic($tokenization) {
        //$data = $this->createTokenizationPaymentDomesticData($tokenization);
        $data = [];
        $url = $this->baseURL[$this->env] . $this->URI['tokenizationPaymentDomestic'];
        $result = $this->sendRequestToAlepay($data, $url);
        if ($result->errorCode == '000') {
            $dataDecrypted = $this->alepayUtils->decryptData($result->data, $this->encryptKey);
            return json_decode($dataDecrypted);
        } else {
            return $result;
        }
    }

    public function cancelCardLink($alepayToken) {
        $params = array('alepayToken' => $alepayToken);
        $url = $this->baseURL[$this->env] . $this->URI['cancelCardLink'];
        $result = $this->sendRequestToAlepay($params, $url);
        echo json_encode($result);
        if ($result->errorCode == '000') {
            $dataDecrypted = $this->alepayUtils->decryptData($result->data, $this->encryptKey);
            echo $dataDecrypted;
        }
    }

    private function sendRequestToAlepay($data, $url) {
        error_log(__METHOD__);
        error_log('before data' . print_r(json_encode($data),true));
        $dataEncrypt = $this->alepayUtils->encryptData(json_encode($data), $this->encryptKey);
        $checksum = md5($dataEncrypt . $this->checksumKey);
        $items = array(
            'token' => $this->apiKey,
            'data' => $dataEncrypt,
            'checksum' => $checksum
        );
        error_log(print_r($items,true));
        $data_string = json_encode($items);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string))
        );
        $result = curl_exec($ch);
        // echo $result;
        error_log(print_r($result,true));
        return json_decode($result);
    }

    public function return_json($error, $message = "", $data = array()) {
        header('Content-Type: application/json');
        echo json_encode(array(
            "error" => $error,
            "message" => $message,
            "data" => $data
        ));
    }

    public function decryptCallbackData($data) {
        error_log(__METHOD__);
        return $this->alepayUtils->decryptCallbackData($data, $this->encryptKey);
    }

}

?>