<?php
namespace App\Service;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use \Firebase\JWT\JWT;

/**
 * TOKEN逻辑封装
 * author  wangsong 2019-11-05
 */
class TokenService {

    //code方式获取token
    public function GetTokenByCode(array $request_data) {
        $condition = [];

        $condition[] = ["oauth_auth_code.client_id", "=", $request_data["client_id"]];
        $condition[] = ["oauth_auth_code.code", "=", $request_data["code"]];

        $field_list = [
            "oauth_auth_code.expires_at", 
            "oauth_auth_code.user_id", 
            "oauth_client.revoked", 
            "oauth_client.id AS client_id",
            "oauth_client.token_expire",
            "user.passport_id"
        ];
        $code_info = DB::table("oauth_auth_code")
                ->select($field_list)
                ->join("oauth_client", function($join){
                    $join->on("oauth_client.user_id", "=", "oauth_auth_code.user_id")->where("oauth_client.revoked", "=", 2);
                }, null, null, "left")
                ->leftjoin("user", "oauth_auth_code.user_id", "=", "user.id")
                ->where($condition)
                ->first();
        if (empty($code_info) || time() > $code_info->expires_at) { return ['status_code' => 2001, 'msg' => "code已过期！"]; exit; }
        if ($code_info->revoked == 1) { return ['status_code' => 2001, 'msg' => "此应用已被禁用，请联系运营人员！"]; exit; }

        $create_token_info = [
            "passport_id"   => $code_info->passport_id,
            "client_id"     => $code_info->client_id,
            "user_id"       => $code_info->user_id,
            "token_expire"  => $code_info->token_expire
        ];
        $return_data = self::_createToken($create_token_info);
        if ($return_data["status_code"] == 200) {
            $code_condition = [];
            $code_condition[] = ["client_id", "=", $request_data["client_id"]];
            $code_condition[] = ["code", "=", $request_data["code"]];
            DB::table("oauth_auth_code")->where($code_condition)->update(["revoked" => 1]);
        }
        return $return_data;
    }

    //密码方式获取Token
    public function GetTokenByPassword(array $request_data) {
        $condition = [];
        $condition[] = ["user.mobile", "=", $request_data["username"]];
        if (!empty($request_data["client_id"])) { $condition[] = ["oauth_client.id", "=", $request_data["client_id"]]; }

        $field_list = ["oauth_client.revoked", "oauth_client.token_expire", "oauth_client.user_id", "user.passport_id", "user.password", "user.block_status"];
        $user_info = DB::table("user")->select()->leftjoin("oauth_client", "user.id", "=", "oauth_client.user_id")->where($condition)->first();
        if (empty($user_info) || !password_verify($input["password"], $user_info->password)) { return response()->json(['status_code' => 2001, 'msg' => "用户名或者密码错误！"]); exit; }
        if ($user_info->block_status == 1) { return response()->json(['status_code' => 2002, 'msg' => "账号已被锁，请联系客服人员处理！"]); exit; }
        if ($user_info->revoked == 1) { return response()->json(['status_code' => 2002, 'msg' => "此应用已被禁用，请联系运营人员！"]); exit; }

        $create_token_info = [
            "passport_id"   => $user_info->passport_id,
            "client_id"     => $request_data["client_id"],
            "user_id"       => $user_info->user_id,
            "token_expire"  => $user_info->token_expire
        ];
        $return_data = self::_createToken($create_token_info);
        return $return_data;
    }

    //client方式获取token
    public function GetTokenByClient(array $request_data) {
        $condition = [];
        $condition[] = ["oauth_client.id", "=", $request_data["client_id"]];
        $condition[] = ["oauth_client.secret", "=", $request_data["client_secret"]];

        $field_list = ["oauth_client.revoked", "oauth_client.token_expire", "oauth_client.user_id", "user.passport_id"];
        $user_info = DB::table("oauth_client")
                ->select($field_list)
                ->leftjoin("user", "user.id", "=", "oauth_client.user_id")
                ->where($condition)
                ->first();
        if ($user_info->revoked == 1) { return ['status_code' => 2001, 'msg' => "此应用已被禁用，请联系运营人员！"]; }
        $create_token_info = [
            "passport_id"   => $user_info->passport_id,
            "client_id"     => $request_data["client_id"],
            "user_id"       => $user_info->user_id,
            "token_expire"  => $user_info->token_expire
        ];
        $return_data = self::_createToken($create_token_info);
        return $return_data;
    }

    //刷新token
    public function GetTokenByRefresh(array $request_data) {
        $condition = [];
        $condition[] = ["oauth_token.client_id", "=", $request_data["client_id"]];
        $condition[] = ["oauth_token.refresh_token", "=", $request_data["refresh_token"]];
        $field_list = ["oauth_token.refresh_expire_at", "oauth_token.refresh_token_status", "oauth_token.user_id", "user.passport_id", "oauth_client.revoked", "oauth_client.token_expire"];
        $refresh_info = DB::table("oauth_token")
                ->select($field_list)
                ->leftjoin("oauth_client", "oauth_client.id", "=", "oauth_token.client_id")
                ->leftjoin("user", "user.id", "=", "oauth_token.user_id")
                ->where($condition)
                ->first();
        if($refresh_info->refresh_token_status != 1){ return ['status_code' => 2001, 'msg' => "刷新code已失效！"]; exit; }
        if (empty($refresh_info) || time() > $refresh_info->refresh_expire_at) { return ['status_code' => 2001, 'msg' => "刷新token已过期！"]; exit; }
        if ($refresh_info->revoked == 1) { return ['status_code' => 2001, 'msg' => "此应用已被禁用，请联系运营人员！"]; exit; }
        
        $create_token_info = [
            "passport_id"   => $refresh_info->passport_id,
            "client_id"     => $request_data["client_id"],
            "user_id"       => $refresh_info->user_id,
            "token_expire"  => $refresh_info->token_expire
        ];
        $return_data = self::_createToken($create_token_info);
        if($return_data["status_code"] == 200){ DB::table("oauth_token")->where($condition)->update(["refresh_token_status" => 2, "update_at" => time()]); }
        return $return_data;
    }

    //签名数据处理
    private function _createToken(array $user_info) {
        $token_expire = ($user_info["token_expire"] >= 7200) ? $user_info["token_expire"] : (int) \config('common.oauth_token_expire');
        $refresh_expire = (int) \config('common.oauth_refresh_expire');
        $private_key_path = \config('common.oauth_private_key_path');
        $private_key = file_get_contents($private_key_path);

        $time_stamp = time();
        $token_data = [
            "client_id"  => $user_info["client_id"],
            "expired_at" => $time_stamp + $token_expire + 200,
            "custom"     => ["passport_id" => $user_info["passport_id"]]
        ];
        $jwt_code = JWT::encode($token_data, $private_key, 'RS256');
        $refresh_token = self::_getRefreshToken($user_info);
        
        $save_data = [
            "user_id"           => $user_info["user_id"],
            "client_id"         => $user_info["client_id"],
            "scope"             => "",
            "access_token"      => $jwt_code,
            "access_expire_at"  => $token_data["expired_at"],
            "refresh_token"     => $refresh_token,
            "refresh_expire_at" => $time_stamp + $refresh_expire + 600,
            "create_at"         => $time_stamp,
            "update_at"         => $time_stamp
        ];
        DB::table('oauth_token')->insert($save_data);
        $return_data = ["status_code" => 200, "msg" => "success", "data" => ["access_token" => $jwt_code, "refresh_token" => $refresh_token, "expires_in" => $token_expire, "token_type" => "Bearer"]];
        return $return_data;
    }

    //获取刷新token
    private function _getRefreshToken(array $user_info) {
        $user_info["time_stamp"] = time();
        $user_info["uuid"] = uniqid();
        $code_str = md5(implode($user_info));
        $exist_info = DB::table("oauth_token")->select("refresh_token")->where("refresh_token", "=", $code_str)->first();
        if (!empty($exist_info)) {
            return self::_getRefreshToken($user_info);
        } else {
            return $code_str;
        }
    }

    //ParseToken 解析token中的数据
    public function ParseToken($access_token) {
        $separator = '.';
        if (2 != substr_count($access_token, $separator)) { echo json_encode(['msg' => 'header头格式错误！', 'status' => 412, 'data' => '']); exit(); }
        list($header, $payload, $signature) = explode($separator, $access_token);
        $json_data = json_decode(base64_decode($payload));
        return $json_data;
    }
}
