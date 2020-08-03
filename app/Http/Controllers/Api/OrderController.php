<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Service\CurlService;
use Illuminate\Support\Facades\Cache;
use function App\Helpers\WriteLog;

class OrderController extends BaseController {
    private $curl_service;
    private $write_service;
    //对方 已支付已取消  ---- 6
    //    未支付已取消  ---- 5
    
    private $pay_status_change = ["NOTPAY" => 1, "PAYED" => 2];
    private $order_status_change = ["DONE"=>4,"NOTPAY"=>1,"PAYED"=>2,"WAIT_BUYER_CONFIRM"=>3];
    private $express_status_change = ["DONE"=>1, "PENDING"=>0, "PARTAIL"=>2];
    private $notice_open_id = [
        "ok_TasyOkq8A0TgJzsZjTZDP4Y3g",
        "ok_Tas1JFVoB-gothrwjCMvatUxM",
        "ok_Tas0sgOXfy5X0DrQmQR3PjCQA",
        "ok_Tasz5KTXQ0BCqq5dPiwvMKC8Q"
    ];

    public function __construct() {
        parent::__construct();
        $this->curl_service = new CurlService();
    }
    
    //订单检测
    public function OrderCheck(){
        $this->_getInnisfreeOrderList(0);
    }
    
    //获取悦诗风吟订单列表
    private function _getInnisfreeOrderList($page_no){
        $get_uri = "https://wmp.amorepacific.com.cn/api/systemlink/caodong/order/list?";
        $get_data = [
            "company_id"        => 2,
            "time_end_begin"    => time()- 6*60,
            "page"              => $page_no + 1,
            "pageSize"          => 3,
        ];
        $get_data["time_start_begin"] = $get_data["time_end_begin"] - 10*60;
        $get_data["sign"] = $this->_makeSign($get_data);
        $get_uri .= http_build_query($get_data);
        $result_json_str = $this->curl_service->curlGetData($get_uri, "GET", null, null, "");
        $result_data = json_decode($result_json_str, TRUE);
        $order_list = json_decode($result_data["data"], TRUE);
//        if(!$this->_verifySign($order_list)){
//            //书写日志 
//            $log_data= [
//                "msg_id"        => md5($result_json_str),
//                "uri"           => $get_uri,
//                "response"      => $result_json_str
//            ];
//            $log_data["error_msg"] = "check_sign_fail";
//            WriteLog($log_data, "notice", $_SERVER["REQUEST_URI"]);
//            //通知
//            
//            exit;
//        }
     
        $order_code_array = [];
        $order_check_list = [];
        foreach($order_list["list"] as $order_info){
            $order_code_array[] = $order_info["order_id"];
            $order_check_list[$order_info["order_id"]] = [
                "pay_status"        => $this->pay_status_change[$order_info["pay_status"]],
                "express_status"    => $this->express_status_change[$order_info["delivery_status"]],
            ];
            if(isset($this->order_status_change[$order_info["order_status_des"]])){
                $order_check_list[$order_info["order_id"]]["order_status"] = $this->order_status_change[$order_info["order_status_des"]];
            }else if($order_info["order_status_des"] == "CANCEL"){
                if($order_info["pay_status"] == "PAYED"){
                    $order_check_list[$order_info["order_id"]]["order_status"] = 6;
                }else if($order_info["pay_status"] == "NOTPAY"){
                    $order_check_list[$order_info["order_id"]]["order_status"] = 5;
                }
            }
        }
        //订单校验
        $field_list = ["order_code", "order_status", "pay_status", "express_status"];
        $exist_order_list = DB::table("t_order")->select($field_list)->whereIn("order_code", $order_code_array)->get();

        $notice_str_list = [];
        $need_retry_order = [];
        if(!empty($exist_order_list)){
            foreach($exist_order_list as $exist_order_info){
                if($order_check_list[$exist_order_info->order_code]["order_status"] != ""){
                    $temp_str = "";
                    if($exist_order_info->order_status != $order_check_list[$exist_order_info->order_code]["order_status"]){
                        $temp_str = $this->_supplementOrder($exist_order_info->order_code, "订单状态不同步");
                    }
                    if($exist_order_info->pay_status != $order_check_list[$exist_order_info->order_code]["pay_status"]){
                        $temp_str = $this->_supplementOrder($exist_order_info->order_code, "支付状态不同步");
                    }
                    if($exist_order_info->express_status != $order_check_list[$exist_order_info->order_code]["express_status"]){
                        $temp_str = $this->_supplementOrder($exist_order_info->order_code, "快递状态不同步");
                    }
                    if($temp_str != ""){
                        $notice_str_list[] = $temp_str;
                        $notice_result_list = explode("--", $temp_str);
                        if(array_pop($notice_result_list) != "处理成功"){
                            $need_retry_order[] = $exist_order_info->order_code;;
                        }
                    }
                    unset($order_check_list[$exist_order_info->order_code]);
                }
            }
        }
        
        if(!empty($order_check_list)){
            $temp_str = "";
            foreach($order_check_list as $order_code => $order_check_info){
                $temp_str = $this->_supplementOrder($order_code, "在草动中订单未找到");
                if($temp_str != ""){
                    $notice_str_list[] = $temp_str;
                    $notice_result_list = explode("--", $temp_str);
                    if(array_pop($notice_result_list) != "处理成功"){
                        $need_retry_order[] = $order_code;
                    }                    
                }
            }
        }
        
        //测试微信号通知
        if(!empty($notice_str_list)){
            $exist_cache = json_decode(Cache::get("caodong_order_fail"), TRUE);
            if(!empty($exist_cache)){$need_retry_order = array_unique(array_merge($need_retry_order, $exist_cache));}
            Cache::forever("caodong_order_fail", json_encode($need_retry_order));
            $this->_wechatCompanyNotice($notice_str_list);
        }
        
        //分页判断
        if($order_list["pager"]["count"] > $order_list["pager"]["page_no"]*$order_list["pager"]["page_size"]){
            $this->_getInnisfreeOrderList($page_no + 1);
        }
    }
    
    //补单
    private function _supplementOrder($order_sn, $reason_str){
        $return_str = "订单号：".$order_sn."--".$reason_str."--";
        $post_uri = "https://cd-wmp.amorepacific.com.cn/manager/order/external/pull/codes?orderCodes=".$order_sn;
        $post_data = ["orderCodes" => $order_sn ];
        $post_header = ["token"=>"954f85f6-f095-491a-ba3a-c397eb93b6c1"];
        $result_json_str = $this->curl_service->curlGetData($post_uri, "POST", $post_data, $post_header, "");
        $result_data = json_decode($result_json_str, TRUE);
        if($result_data["code"] == "SUCCESS"){
            $return_str .= $result_data["message"];
            return $return_str;
        }else{
           return $return_str.$result_data["message"]; 
        }
    }

    //企业微信通知
    private function _wechatCompanyNotice($notice_str_list){
        $post_data = [
            "template_id"   => "j_5djw2rJ0quIFRlrltYZBLMhMyF3Zyh11ITmAGuxss",
            "url"           => "",
            "data"          => ["content"=>["value"=>"\r\n".implode("\r\n", $notice_str_list)]]
        ];
        foreach($this->notice_open_id as $open_id){
            $post_data["touser"] = $open_id;
            $get_uri = "http://www.skyshappiness.com/index.php?m=Admin&c=Task&a=getWechatToken";
            $access_token = $this->curl_service->curlGetData($get_uri, "GET", null, null, "");
            $uri = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$access_token;
            $curl_data = $this->curl_service->curlGetData($uri, "POST", json_encode($post_data, JSON_UNESCAPED_UNICODE), null, "");
            $log_data = [];
            $log_data["post_data"] = $post_data;
            $log_data["return_data"] = $curl_data;
            writeLog($log_data, "notice", "wechat_customer_notice");
        }
    }

    
    //制作签名
    private function _makeSign($post_data){
        ksort($post_data);
        $need_str = '';
        foreach ($post_data as $key => $value) {
          if(is_array($value)){ $value = json_encode($value);}
          if($value){ $str .= $key ."=".$value; }
        }
        $str .= "token=".\config('common.innisfree_token');
        $signed_str = strtoupper(md5(trim($str)));
        return $signed_str;
    }
    
    //签名解密
    private function  _verifySign($receive_data){
        $check_signed_str = $receive_data["sign"];
        unset($receive_data["sign"]);
        $signed_str = $this->_makeSign($receive_data);
        if($check_signed_str == $signed_str){
            return TRUE;
        }else{
            return FALSE;
        }
    }
    
    //重试机制
    public function RetryOrder(){
        $notice_str_list = [];
        $need_retry_order = [];
        $retry_order_list = json_decode(Cache::pull("caodong_order_fail"), TRUE);
        if(!empty($retry_order_list)){
            foreach($retry_order_list as $order_code){
                $temp_str = "";
                $temp_str = $this->_supplementOrder($order_code, "第一次失败重试");
                if($temp_str != ""){ 
                    $notice_str_list[] = $temp_str;
                    $notice_result_list = explode("--", $temp_str);
                    if(array_pop($notice_result_list) != "处理成功"){
                        $need_retry_order[] = $order_code;
                    }
                }
            }
        }
        
        //测试微信号通知
        if(!empty($notice_str_list)){
            $exist_cache = json_decode(Cache::get("caodong_order_fail"), TRUE);
            if(!empty($exist_cache)){$need_retry_order = array_unique(array_merge($need_retry_order, $exist_cache));}
            Cache::forever("caodong_order_fail", json_encode($need_retry_order));
            $this->_wechatCompanyNotice($notice_str_list);
        }
    }
}
