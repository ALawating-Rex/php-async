<?php
namespace Aex\PhpAsync\Helper;

class Command{
    public static $commandList = [];

    /**
     * 判断返回是否为 command 是则提取不是原样返回
     * command 格式 |@-command-@| -- command中带参数： command::param1::param2
     * 命令加上参数总长度不应该超过 socket_read 设置的长度，否则命令会被分割，导致意外
     * 拼接命令可以实现，但是没必要做这个处理了
     * @param $response
     * @param string $preg
     * @return mixed
     */
    public static function getCommand($response,$separator = '(::)',$preg = ''){
        $command = $response;
        $response = substr($response,0,-1);
        if(empty($preg)) {
            $preg = '/\|@-(.*)-@\|$/';
        }
        if(preg_match($preg,$response,$commandArr)){
            $commandStr =  array_pop($commandArr);
            $commandStrParse = explode($separator,$commandStr);
            $command = array_shift($commandStrParse);
            // TODO 判断 command 是否存在，一个作用是白名单作用 另一个作用是确认确实是个 command
            // if(in_array($command,self::$commandList)){ return $command; }
            return [
                'command' => $command,
                'value' => $commandStrParse
            ];
        }else{
            // 不是command 格式 原样返回（可能是文件内容）
            return $command;
        }
    }
}