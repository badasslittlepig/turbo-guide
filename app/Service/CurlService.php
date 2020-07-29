<?php
namespace App\Service;

use function App\Helpers\WriteLog;
/**
 * CURL逻辑封装
 * author  wangsong 2019-11-05
 */
class CurlService {
    
    //curl
    public function curlGetData($url, $httpMethod = "GET", $postFields = null, $headers = null, $cookie_str = '') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $post_str = is_array($postFields) ? self::_getPostHttpBody($postFields) : $postFields;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_str);
        curl_setopt($ch, CURLOPT_COOKIE, $cookie_str);
        curl_setopt($ch, CURLOPT_TIMEOUT, 200);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3000);
        
        //https request
        if (strlen($url) > 5 && strtolower(substr($url, 0, 5)) == "https") {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        if (is_array($headers) && 0 < count($headers)) {
            $httpHeaders = self::_getHttpHearders($headers);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            echo "Speicified endpoint or uri is not valid.", "SDK.ServerUnreachable";
        }
        curl_close($ch);
        $log_data= [
            "uri"           => $url,
            "head_data"     => $headers,
            "request_data"  => $postFields,
            "response"      => $body
        ];
        WriteLog($log_data, "notice", $_SERVER["REQUEST_URI"]);
        return $body;
    }

    private function _getPostHttpBody($postFildes) {
        $content = "";
        foreach ($postFildes as $apiParamKey => $apiParamValue) {
            $content .= "$apiParamKey=" . urlencode($apiParamValue) . "&";
        }
        return substr($content, 0, -1);
    }

    private function _getHttpHearders($headers) {
        $httpHeader = array();
        foreach ($headers as $key => $value) {
            array_push($httpHeader, $key . ":" . $value);
        }
        return $httpHeader;
    }
}
