<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Service\CurlService;

class BaseController extends Controller {

    protected $now_date;
    protected $now_time_stamp;

    public function __construct() {
        error_reporting(0);
        date_default_timezone_set('Asia/Shanghai');
        $this->now_date = date("Y-m-d H:i:s");
        $this->now_time_stamp = time();
    }
    
    //校验权限
    public function CheckAppPermission ($request_app_key, $check_app_key){
        $config_access_app_key = json_decode(\config('common.sys_access_app_key'));
        if(($request_app_key != $check_app_key) && !in_array($request_app_key, $config_access_app_key)){
            echo json_encode(['status_code' => 20001, 'msg' => "此token没有权限做此操作！"]); exit; 
        }
    }

    // RequestToken 请求oauthToken
    public function RequestToken($clientID, $clientSecret) {
        $postParams = [
            'client_id' => $clientID,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ];
        $postHeader = ["Content-Type" => "application/x-www-form-urlencoded"];
        $postURL = \config('oauth.url') . 'oauth2/token';
        $curlData = CurlService::curlGetData($postURL, "POST", $postParams, $postHeader);

        $result_data = json_decode($curlData, TRUE);
        if ($result_data["status_code"] != 200) {
            return false;
        }
        $return_token = "Bearer " . $result_data["data"]["access_token"];
        return $return_token;
    }
}
