<?php
declare (strict_types=1);

namespace app\record\controller;


use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Asr\V20190614\AsrClient;
use TencentCloud\Asr\V20190614\Models\CreateRecTaskRequest;

use TencentCloud\Asr\V20190614\Models\DescribeTaskStatusRequest;
use think\facade\Db;


class Index
{
    public static $SecretId = "AKIDLWQ5TaPyGI4apoXzA4h26tPdcJjHZbMq";
    public static $SecretKey = "EBrGOFp78sLYU9YgX7QSDhuqVtzoMddN";
    public static $Table = 't_batch';
    public static $TableAudio = 't_audio';

    public function index()
    {

        return '您好！这是一个[record]示例应用';
    }

    //录音文件识别  {"Data":{"TaskId":2056948721},"RequestId":"e8dbcd57-9633-4917-a294-fefaa6a73d2f"}
    public static function record($data)
    {

        $TaskId = "";


        try {


            $cred = new Credential(self::$SecretId, self::$SecretKey);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("asr.tencentcloudapi.com");

            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new AsrClient($cred, "", $clientProfile);

            $req = new CreateRecTaskRequest();

            $params = array(
                "EngineModelType" => "8k_zh",
                "ChannelNum" => 2,
                "ResTextFormat" => 0,
                "SourceType" => 1,
                "Data" => $data
            );
            $req->fromJsonString(json_encode($params));

            $resp = $client->CreateRecTask($req);
            $jsonStr = $resp->toJsonString();
            $jsonObj = json_decode($jsonStr, true);


            // var_dump($jsonObj);


            $TaskId = $jsonObj['Data']['TaskId'];
        } catch (TencentCloudSDKException $e) {
            //  var_dump($e);

        }
        return $TaskId;

    }

    public static function GetRecordOcrResult($taskId)
    {
        $ret = '';
        $taskId = (int)$taskId;

        try {

            $cred = new Credential(self::$SecretId, self::$SecretKey);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("asr.tencentcloudapi.com");

            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new AsrClient($cred, "", $clientProfile);

            $req = new DescribeTaskStatusRequest();

            $params = array(
                "TaskId" => $taskId
            );
            $req->fromJsonString(json_encode($params));

            $resp = $client->DescribeTaskStatus($req);
            $jsonStr = $resp->toJsonString();
            $jsonObj = json_decode($jsonStr, true);

            if ($taskId == $jsonObj['Data']['TaskId']) {

                $ret = $jsonObj['Data']['Result'];
            }

        } catch (TencentCloudSDKException $e) {

        }

        return $ret;

    }

    public function GetRecordResult()
    {
        $taskId = (int)input("taskId");

        try {

            $cred = new Credential(self::$SecretId, self::$SecretKey);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("asr.tencentcloudapi.com");

            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new AsrClient($cred, "", $clientProfile);

            $req = new DescribeTaskStatusRequest();

            $params = array(
                "TaskId" => $taskId
            );
            $req->fromJsonString(json_encode($params));

            $resp = $client->DescribeTaskStatus($req);

            print_r($resp->toJsonString());
        } catch (TencentCloudSDKException $e) {
            echo $e;
        }


    }

    //接受zip文件并存储一个批次
    public function UpdateAudioZip()
    {
        $loginId = 1;
        //请求客户端ip
        $loginIp = $_SERVER["REMOTE_ADDR"];

        $post = $_POST;
        $files = $_FILES;
        if (empty($files['zip'])) {

            self::IResult(0001, '', 'zip压缩文件缺失');
        }
        $file = $files['zip'];
        //$file['name']  //文件名
        //$file['tmp_name'] 临时文件地址
        //$file['type']   "application/zip"

        $md5 = md5_file($file['tmp_name']);

        $save_path = "audio/" . $md5 . '/';
        if (!file_exists($save_path)) {
            mkdir($save_path);
        }


        $save_path = $save_path . '/' . $file['name'];
        if ($file['type'] != "application/x-zip-compressed") {
            self::IResult(0001, '', '文件格式仅限 zip');

        }
        if (!move_uploaded_file($file['tmp_name'], $save_path)) {

            self::IResult(0001, '', 'zip文件处理失败');
        }

        //是否重复
        $Brepet = self::DsqlBatchByLoginIdAndMd5(1, $md5);
        if ($Brepet) {
            self::IResult(0001, '', '文件已重复');
        }

        //写入批次记录
        $Badd = self::XsqlBatch($file['name'], $md5, 1, $loginIp);
        if (!$Badd) {
            self::IResult(0001, '', '失败');
        }


//        $extract_path= self::ZipReadAndBase64E($save_path);
//        var_dump($extract_path);
        self::IResult(200, '', '成功');
    }

    //拉取识别结果
    public static function TaskGetOcrResult()
    {
        $list = Db::table(self::$TableAudio)
            ->where([
                'State' => 0
            ])->select();

        self::JsonXlh($list);
        foreach ($list as $K => $v) {
            $v_task_id = $v['TaskId'];
            $v_audio_id = $v["AudioId"];
//            var_dump($v, $v_task_id);


            $ocr_result = self:: GetRecordOcrResult($v_task_id);
            $rt_warn_count = 0;//计算敏感词次数
            $rt_key_words = "";
            self::JcKeyWord($ocr_result, $rt_warn_count, $rt_key_words);
            $rt_key_words = implode(",", $rt_key_words);
            self::UsqlAudioOcrResultByAudioId($v_audio_id, $ocr_result);
            self::UsqlAudioOcrStateByAudioId($v_audio_id, 1);
            self::UsqlAudioOcrWarnCountByAudioId($v_audio_id, $rt_warn_count, $rt_key_words);
        }


    }

    public static function JcKeyWord($txt, &$warnCount, &$keywords)
    {
        $keywords = file_get_contents("keyword.txt");
//       var_dump($keywords);
        $kw_list = explode(",", $keywords);
//        $warnCount=0;
        $keywords = array();
        foreach ($kw_list as $k => $v) {
            $fd_res = strpos($txt, $v);
            if ($fd_res !== false) {
                $warnCount++;
                $isCz = false;
                foreach ($keywords as $kk => $kv) {

                    if ($kv == $v) {
                        $isCz = true;

                        break;
                    }
                }
                if (!$isCz) {
                    array_push($keywords, $v);
                }







            }

        }

    }

    //定时处理录音  上传腾讯云识别
    public static function TaskAudio()
    {

        $result = Db::table(self::$Table)
            ->where([
                'State' => 0
            ])
            ->select();
        self::JsonXlh($result);

        //  array(8) {
        //  ["BatchId"]=>
        //  int(14)
        //  ["Md5"]=>
        //  string(32) "29f123e2d3443e3efb4bcbed54a191f1"
        //   ["FileName"]=>
        //  string(40) "7fd18552-6414-4d68-8e1b-8988b2882c2b.zip"
        //   ["FileCount"]=>
        //  NULL
        //  ["WarnCount"]=>
        //  NULL
        //  ["State"]=>
        //  int(0)
        //  ["LoginId"]=>
        //  string(1) "1"
        //  ["LoginIp"]=>
        //  string(13) "192.168.1.131"
        //}


        foreach ($result as $k => $v) {
            $v_md5 = $v['Md5'];
            $v_file_name = $v['FileName'];
            $v_batch_id = $v['BatchId'];


            //目录不存在 则创建目录
            $zip_path = "audio/" . $v_md5 . "/" . $v_file_name;
            $extract_path = "audio/" . $v_md5 . "/extract";
            if (!file_exists($extract_path)) {
                mkdir($extract_path);
            }


            //文件数量
            $f_count = self::File_Count($extract_path);
            if ($f_count <= 0) {
                //解压文件
                self::Zip_Extract($zip_path, $extract_path);
                $f_count = self::File_Count($extract_path);
            }


            //更新批次
            self::UsqlBatchFileCount($v_batch_id, $f_count);

//


            //上传
            self::UsqlBatchState($v_batch_id, 1);
            $file_array = glob($extract_path . "/*.*");
            foreach ($file_array as $fk => $fv) {
                $base64_data = base64_encode(file_get_contents($fv));

                $task_id = self::record($base64_data);

                $audio_name = basename($fv);

                if ($task_id != "") {
                    //写入数据库
                    self::XsqlAudio($v_batch_id, $task_id, $audio_name);
                } else {
                    //识别提交失败的 也写入
                    self::XsqlAudio($v_batch_id, $task_id, $audio_name, 2);
                }


            }

            self::UsqlBatchState($v_batch_id, 2);

//            var_dump($v, $f_count);
        }


    }

    public static function UsqlAudioOcrResultByAudioId($audioId, $ocrResult)
    {

        return Db::table(self::$TableAudio)
            ->where([
                'AudioId' => $audioId
            ])
            ->update([
                'OcrResult' => $ocrResult
            ]);

    }

    public static function UsqlAudioOcrStateByAudioId($audioId, $state)
    {

        return Db::table(self::$TableAudio)
            ->where([
                'AudioId' => $audioId
            ])
            ->update([
                'State' => $state
            ]);

    }

    public static function UsqlAudioOcrWarnCountByAudioId($audioId, $count, $keywords)
    {

        return Db::table(self::$TableAudio)
            ->where([
                'AudioId' => $audioId
            ])
            ->update([
                'WarnCount' => $count,
                'KeyWords' => $keywords
            ]);

    }

    public static function XsqlAudio($batchId, $taskId, $audioName, $state = 0)
    {
        $result = Db::table("t_audio")
            ->insert([
                'BatchId' => $batchId,
                'TaskId' => $taskId,
                'State' => $state,
                'AudioName' => $audioName

            ]);
        return $result;

    }

    public function GetAudioListByBatchId()
    {
        $batchId = input("BatchId");

        $result = Db::table(self::$TableAudio)
            ->where([
                'BatchId' => $batchId
            ])->select();
        return self::IResult(200, $result, "成功");

    }


    //json序列化
    public static function JsonXlh(&$str)
    {
        $str = json_encode($str);
        $str = json_decode($str, true);
        return $str;
    }


    //写一条批次记录
    public static function XsqlBatch($fimeName, $md5, $loginId, $loginIp)
    {

        $result = Db::table("t_batch")
            ->insert([
                'FileName' => $fimeName,
                'LoginId' => $loginId,
                'LoginIp' => $loginIp,
                'State' => 0,
                'Md5' => $md5
            ]);
        return $result;
    }

    //更新批次状态值
    public static function UsqlBatchState($batchId, $state)
    {

        return Db::table(self::$Table)
            ->where([
                'BatchId' => $batchId
            ])
            ->update([
                'State' => $state
            ]);
    }

    //更新音频文件数量
    public static function UsqlBatchFileCount($batchId, $fileCOunt)
    {

        return Db::table(self::$Table)
            ->where([
                'BatchId' => $batchId
            ])
            ->update([
                'FileCount' => $fileCOunt
            ]);
    }


    //查该文件是否已经创建过批次
    public static function DsqlBatchByLoginIdAndMd5($loginId, $md5)
    {


        $result = DB::table(self::$Table)->where(
            [
                'LoginId' => $loginId,
                'Md5' => $md5
            ]
        )->count();
        if ($result >= 1) {
            return true;
        }

        return false;
    }

    public static function File_Count($file_path)
    {


        $file_array = glob($file_path);
        return sizeof($file_array);

    }

    //文件上传至腾讯云
    public static function File_UploadToTxClound()
    {

    }

    //解压文件
    public static function Zip_Extract($zip_path, $extract_path)
    {

        $zip = new \ZipArchive();
        $zip->open($zip_path);
        $zip->extractTo($extract_path);
        return $extract_path;

    }

    //通用返回
    public static function IResult($code = 200, $data, $msg = "")
    {

        $rt = array();
        $rt['code'] = $code;
        $rt['data'] = $data;
        $rt['msg'] = $msg;

        exit(json_encode($rt, JSON_UNESCAPED_UNICODE));

    }


    public function GetBatchList()
    {

        $result = Db::table(self::$Table)
            ->where([
                'LoginId' => 1
            ])
            ->select();

        return self::IResult(200, $result, "成功");


    }


    //Write Run Log.txt
    public function RWringRunLog($msg)
    {
        //TMD   /log.txt  liunx 反斜杠..和 windows不同...
        $write_log_path = __DIR__ . '/log.txt';
        $fo = fopen($write_log_path, "a+");
        $fread = '';
        if (filesize($write_log_path) > 0) {
            $fread = fread($fo, filesize($write_log_path));
        }

        fwrite($fo, date("Y-m-d D h:i:s ") . ":" . $msg . "\n");
        fclose($fo);
    }
}
