<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

/**
 * 日志书写
 * @staticvar string $__APP_LOG_PID__
 * @param type $content
 * @param type $level
 * @param type $log_name
 */
if (!function_exists("WriteLog")) {
    function WriteLog($content, $level = '', $logFileName = '') {
        $logFileName = str_replace('/', '_', $logFileName).".log";
        $host_name = function_exists('gethostname') ? gethostname() : php_uname('n');
        $logStr = '['.date("Y-m-d H:i:s").']'.'[' . $hostName . ']' . '[PID:' . getmypid() . ']' . $level. ' ';
        $logStr .= is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content;
        $log_dir = '../storage/api_logs/' . date("Y-m-d");
        if(!is_dir($log_dir)){ $create_dir_result = mkdir($log_dir, 0777, true); }
        $log_file = $log_dir. DIRECTORY_SEPARATOR . $logFileName; 
        \File::append(storage_path($log_file), $logStr . "\n");
    }
}

//校验手机号码
if (!function_exists("CheckPhone")) {
    function CheckPhone($phone_no){
        $pattern = "/^(1)\d{10}$/"; 
        if (preg_match($pattern, $phone_no)){
            return TRUE;
        }else{
            return FALSE;
        } 
    }
}

//获取任意长度的随机数
if (!function_exists("GetRandomString")) {
    function GetRandomString($no = 20){
        $data = "ABCDEFGHJKLMNOPQRSTUVWXYZabcdefghjklmnopqrstuvwxyz";
        $code = "";
        for ($i = 0; $i < $no; $i++) {
            $code .= substr($data, rand(0, strlen($data)), 1);
        }
        return $code;
    }
}

if (!function_exists("GetUriParams")) {
    function GetUriParams($query){
        $return_data = [];
        $query_list = explode("&", $query);
        foreach($query_list AS $query_str){
            $temp_list = explode("=", $query_str);
            $return_data[$temp_list[0]] = $temp_list[1];
        }
        return $return_data;
    }
}




