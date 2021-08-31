<?php

include(ROOT_PATH . DS . 'Crypt/RSA.php');

class AlepayUtils {

    function encryptData($data, $publicKey) {
        error_log(__METHOD__);
        $rsa = new Crypt_RSA();
        $rsa->loadKey($publicKey); // public key
        $rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_PKCS1);
        error_log('data'.print_r($data,true));
        $output = $rsa->encrypt($data);
        return base64_encode($output);
    }


    function decryptData($data, $publicKey) {
        $rsa = new Crypt_RSA();
        $rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_PKCS1);
        $ciphertext = base64_decode($data);
        $rsa->loadKey($publicKey); // public key
        $output = $rsa->decrypt($ciphertext);
        // $output = $rsa->decrypt($data);
        return $output;
    }

    function decryptCallbackData($data, $publicKey) {
        $decoded = base64_decode($data);
        error_log('decryptCallbackData IN utils');
        return $this->decryptData($decoded, $publicKey);
    }

    function makeSignature($data, $hash_key)
    {
        error_log(__METHOD__);
        $hash_data = '';
        ksort($data);
        $is_first_key = true;
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (!$is_first_key) {
                $hash_data .= '&' . $key . '=' . $value;
            } else {
                $hash_data .= $key . '=' . $value;
                $is_first_key = false;
            }
        }

        $signature = hash_hmac('sha256', $hash_data, $hash_key);
        return $signature;
    }

}
