<?php

namespace Aex\PhpAsync;

use Aex\PhpAsync\Helper\FileTree;
use Aex\PhpAsync\Helper\Command;

class PhpAsync
{
    private $config = [];
    private $socket_pool_max = 1;
    private $socket_pool = [1,2];
    public $curr_file = 'test.php';
    public $data = [];
    public $sid = 1;
    public $client_step = 'start';
    public $lockFiles = []; // 加锁

    public function __construct($config = []){
        $this->config['ip'] = '127.0.0.1';
        $this->config['port'] = 8313;
        $this->config['server-async-path'] = dirname(__DIR__).DIRECTORY_SEPARATOR.'test_code'.DIRECTORY_SEPARATOR.'server'.DIRECTORY_SEPARATOR; // 绝对路径以/(DIRECTORY_SEPARATOR)结尾
        $this->config['client-async-path'] = dirname(__DIR__).DIRECTORY_SEPARATOR.'test_code'.DIRECTORY_SEPARATOR.'client'.DIRECTORY_SEPARATOR; // 绝对路径以/(DIRECTORY_SEPARATOR)结尾
        $this->config['duplicate_name_suffix'] = '.pasync'; // 副本文件后缀
        $this->config['command_separator'] = '(::)'; // 命令分隔符

        if(!empty($config) && is_array($config)){
            $this->config = array_merge($this->config,$config);
        }
    }

    public function fireServer(){
        $server = socket_create(AF_INET,SOCK_STREAM,SOL_TCP);
        socket_bind($server,$this->config['ip'],$this->config['port']);
        socket_listen($server,5);

        $allSockets = [$server];

        while(true){
            $copySockets = $allSockets;
            if(socket_select($copySockets,$write,$except,0) === false){
                exit('socket error');
            }

            if(in_array($server,$copySockets)){
                $client = socket_accept($server);   //接收客户端连接
                $sid = array_shift($this->socket_pool);
                if($sid){
                    $msg = '|@-shakeSuccess'."-@|\n";
                    socket_write($client,$msg,strlen($msg)); //握手成功
                    $remoteInfo = socket_getpeername($client,$remoteAddr,$remotePort);
                    $clientKey = md5($remoteAddr.':'.$remotePort);
                    echo PHP_EOL;
                    echo 'client connected : '.$remoteAddr.':'.$remotePort;
                    $clientInfo = [
                        'sid' => $sid,
                        'ip' => $remoteAddr,
                        'port' => $remotePort,
                        'step' => 'start',
                        'file' => '', // 要操作的文件,只是文件名（相对路径）
                        'duplicate_file' => '', // 副本文件名称（绝对路径）
                        'file_handler' => '', // 操作的文件句柄
                        'created_at' => time(),
                        'updated_at' => time(),
                    ];
                    $this->data[$clientKey] = $clientInfo;
                }else{
                    $msg = '|@-reachMaxConnections'."-@|\n";
                    socket_write($client,$msg,strlen($msg));
                }

                $allSockets[] = $client;

                //把服务端的socket移除
                $k = array_search($server,$copySockets);
                unset($copySockets[$k]);
            }

            foreach($copySockets as $s){
                $remoteInfo = socket_getpeername($s,$remoteAddr,$remotePort);
                $clientKey = md5($remoteAddr.':'.$remotePort);
                if(!isset($this->data[$clientKey])){
                    socket_write($s,"|@-serverLostSocket-@|\n");
                    sleep(0.5);
                    $k = array_search($s,$allSockets);
                    unset($allSockets[$k]);
                    socket_close($s);
                    continue;
                }
                $this->data[$clientKey]['updated_at'] = time();

                $buf = @socket_read($s,1024,PHP_NORMAL_READ); //获取客户端消息
                //echo PHP_EOL.'------- active socket post data -------'.PHP_EOL;
                //echo $buf.'------- active socket post data -------'.PHP_EOL;
                if(strlen($buf) < 1){ //意味着客户端主动关闭了链接
                    echo PHP_EOL;
                    echo 'client shutdown!!!';
                    $k = array_search($s,$allSockets);
                    unset($allSockets[$k]);
                    $this->socket_pool[] = $this->data[$clientKey]['sid'];
                    unset($this->data[$clientKey]);
                    socket_close($s);
                    continue;
                }

                if($this->data[$clientKey]['step'] == 'deleteFile'){
                    // TODO 接受命令： stop,fileComplete,fileName  (完善。。。)
                    $commandParse = Command::getCommand($buf,$this->config['command_separator']);
                    if(is_array($commandParse)){
                        $command = $commandParse['command'];
                    }else{
                        $command = $commandParse;
                    }

                    if(is_array($commandParse)){
                        if($command == 'stop'){
                            socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                        }elseif ($command == 'fileName'){
                            $fileName = array_shift($commandParse['value']);
                            if(!empty($fileName)){
                                if(in_array($fileName,$this->lockFiles)){
                                    socket_write($s,"|@-fileLock-@|\n");
                                }else{
                                    $this->lockFiles[] = $fileName;
                                    $this->data[$clientKey]['file'] = $fileName;

                                    //echo PHP_EOL;
                                    if(unlink($this->config['server-async-path'].$fileName)){
                                        //echo 'SUCCESS DELETE';
                                    }else{
                                        //echo 'FAILED DELETE';
                                    }
                                    echo PHP_EOL;
                                    echo 'LOG::INFO::delete file:'.$this->config['server-async-path'].$fileName;
                                    socket_write($s,"|@-fileNameAfter-@|\n");
                                }
                            }else{
                                socket_write($s,"|@-paramsError-@|\n");
                            }
                        }elseif ($command == 'fileComplete'){
                            $fileName = $this->data[$clientKey]['file'];

                            $k = '@Nothing@';
                            echo PHP_EOL;
                            foreach($this->lockFiles as $lfk => $lfv){
                                if($lfv == $fileName){
                                    $k = $lfk;
                                    break;
                                }
                            }
                            if($k !== '@Nothing@'){
                                unset($this->lockFiles[$k]);
                            }

                            $this->data[$clientKey]['step'] = 'start';
                            $this->data[$clientKey]['file'] = '';

                            echo 'one file async complete (Return done) - '.$fileName;
                            socket_write($s,"|@-done-@|\n");
                        }else{
                            echo PHP_EOL;
                            echo 'unexpected command - 103';
                            socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                        }
                    }else{
                        echo PHP_EOL;
                        echo 'unexpected command - 104';
                        socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                    }

                }elseif($this->data[$clientKey]['step'] == 'addFile'){
                    // TODO 接受命令： stop,fileComplete,fileName  (完善。。。)
                    $commandParse = Command::getCommand($buf,$this->config['command_separator']);
                    if(is_array($commandParse)){
                        $command = $commandParse['command'];
                    }else{
                        $command = $commandParse;
                    }

                    if(is_array($commandParse)){
                        if($command == 'stop'){
                            socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                        }elseif ($command == 'fileName'){
                            $fileName = array_shift($commandParse['value']);
                            if(!empty($fileName)){
                                if(in_array($fileName,$this->lockFiles)){
                                    socket_write($s,"|@-fileLock-@|\n");
                                }else{
                                    $this->lockFiles[] = $fileName;
                                    $this->data[$clientKey]['file'] = $fileName;

                                    // READY WRITE CONTENT
                                    $this->data[$clientKey]['duplicate_file'] = $this->config['server-async-path'].$fileName.$this->config['duplicate_name_suffix'];
                                    FileTree::mkMutiDir(dirname($this->data[$clientKey]['duplicate_file']));
                                    $this->data[$clientKey]['file_handler'] = fopen($this->data[$clientKey]['duplicate_file'], "at");
                                    if(!$this->data[$clientKey]['file_handler']){
                                        echo PHP_EOL;
                                        echo 'LOG::INFO::open file error:'.$this->data[$clientKey]['file'];
                                        socket_write($s,"|@-fileOpenError-@|\n");
                                        continue;
                                    }else{
                                        socket_write($s,"|@-fileNameAfter-@|\n");
                                    }
                                }
                            }else{
                                socket_write($s,"|@-paramsError-@|\n");
                            }
                        }elseif ($command == 'fileComplete'){
                            fclose($this->data[$clientKey]['file_handler']);
                            if(file_exists($this->config['server-async-path'].$this->data[$clientKey]['file'])){
                                unlink($this->config['server-async-path'].$this->data[$clientKey]['file']);
                                rename($this->data[$clientKey]['duplicate_file'],$this->config['server-async-path'].$this->data[$clientKey]['file']);
                            }else{
                                rename($this->data[$clientKey]['duplicate_file'],$this->config['server-async-path'].$this->data[$clientKey]['file']);
                            }

                            $fileName = $this->data[$clientKey]['file'];
                            $k = '@Nothing@';
                            echo PHP_EOL;
                            foreach($this->lockFiles as $lfk => $lfv){
                                if($lfv == $fileName){
                                    $k = $lfk;
                                    break;
                                }
                            }
                            if($k !== '@Nothing@'){
                                unset($this->lockFiles[$k]);
                            }

                            $this->data[$clientKey]['step'] = 'start';
                            $this->data[$clientKey]['file'] = '';
                            $this->data[$clientKey]['duplicate_file'] = '';
                            $this->data[$clientKey]['file_handler'] = '';

                            echo 'one file async complete (Return done) - '.$fileName;
                            socket_write($s,"|@-done-@|\n");
                        }else{
                            echo PHP_EOL;
                            echo 'unexpected command - 103';
                            socket_write($s,"|@-done-@|\n");
                        }
                    }else{
                        fwrite($this->data[$clientKey]['file_handler'], $buf);
                    }
                }elseif($this->data[$clientKey]['step'] == 'updateFile'){
                    // TODO 接受命令： stop,fileComplete,fileName  (完善。。。)
                    $commandParse = Command::getCommand($buf,$this->config['command_separator']);
                    if(is_array($commandParse)){
                        $command = $commandParse['command'];
                    }else{
                        $command = $commandParse;
                    }

                    if(is_array($commandParse)){
                        if($command == 'stop'){
                            socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                        }elseif ($command == 'fileName'){
                            $fileName = array_shift($commandParse['value']);
                            if(!empty($fileName)){
                                if(in_array($fileName,$this->lockFiles)){
                                    socket_write($s,"|@-fileLock-@|\n");
                                }else{
                                    $this->lockFiles[] = $fileName;
                                    $this->data[$clientKey]['file'] = $fileName;

                                    // READY WRITE CONTENT
                                    $this->data[$clientKey]['duplicate_file'] = $this->config['server-async-path'].$fileName.$this->config['duplicate_name_suffix'];
                                    FileTree::mkMutiDir(dirname($this->data[$clientKey]['duplicate_file']));
                                    $this->data[$clientKey]['file_handler'] = fopen($this->data[$clientKey]['duplicate_file'], "at");
                                    if(!$this->data[$clientKey]['file_handler']){
                                        echo PHP_EOL;
                                        echo 'LOG::INFO::open file error:'.$this->data[$clientKey]['file'];
                                        socket_write($s,"|@-fileOpenError-@|\n");
                                        continue;
                                    }else{
                                        socket_write($s,"|@-fileNameAfter-@|\n");
                                    }
                                }
                            }else{
                                socket_write($s,"|@-paramsError-@|\n");
                            }
                        }elseif ($command == 'fileComplete'){
                            fclose($this->data[$clientKey]['file_handler']);
                            if(file_exists($this->config['server-async-path'].$this->data[$clientKey]['file'])){
                                unlink($this->config['server-async-path'].$this->data[$clientKey]['file']);
                                rename($this->data[$clientKey]['duplicate_file'],$this->config['server-async-path'].$this->data[$clientKey]['file']);
                            }else{
                                rename($this->data[$clientKey]['duplicate_file'],$this->config['server-async-path'].$this->data[$clientKey]['file']);
                            }

                            $fileName = $this->data[$clientKey]['file'];
                            $k = '@Nothing@';
                            echo PHP_EOL;
                            foreach($this->lockFiles as $lfk => $lfv){
                                if($lfv == $fileName){
                                    $k = $lfk;
                                    break;
                                }
                            }
                            if($k !== '@Nothing@'){
                                unset($this->lockFiles[$k]);
                            }

                            $this->data[$clientKey]['step'] = 'start';
                            $this->data[$clientKey]['file'] = '';
                            $this->data[$clientKey]['duplicate_file'] = '';
                            $this->data[$clientKey]['file_handler'] = '';

                            echo 'one file async complete (Return done) - '.$fileName;
                            socket_write($s,"|@-done-@|\n");
                        }else{
                            echo PHP_EOL;
                            echo 'unexpected command - 103';
                            socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                        }
                    }else{
                        fwrite($this->data[$clientKey]['file_handler'], $buf);
                    }
                }else{
                    // TODO 接受命令： stop,deleteFile,addFile,updateFile    (完善。。。)
                    $commandParse = Command::getCommand($buf,$this->config['command_separator']);
                    if(is_array($commandParse)){
                        $command = $commandParse['command'];
                    }else{
                        $command = $commandParse;
                    }

                    if(is_array($commandParse)){
                        if($command == 'stop'){
                            socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                        }elseif($command == 'deleteFile'){
                            // before to do
                            $this->data[$clientKey]['step'] = 'deleteFile';
                            socket_write($s,"|@-commandDone-@|\n");
                            // after to do
                        }elseif($command == 'addFile'){
                            // before to do
                            $this->data[$clientKey]['step'] = 'addFile';
                            socket_write($s,"|@-commandDone-@|\n");
                            // after to do
                        }elseif($command == 'updateFile'){
                            // before to do
                            $this->data[$clientKey]['step'] = 'updateFile';
                            socket_write($s,"|@-commandDone-@|\n");
                            // after to do
                        }else{
                            echo PHP_EOL;
                            echo 'undefined command - 100';
                            socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                        }
                    }else{
                        echo PHP_EOL;
                        echo 'undefined command - 101';
                        socket_write($s,"|@-done-@|\n"); // 要求客户端主动关闭
                    }
                }
            }
        }
    }

    public function fireClient(){
        set_time_limit(0);
        $fileList = [];
        $fileTreeLib = new FileTree();
        $clientFileArr = $fileTreeLib->fileList($this->config['client-async-path']);
        foreach($clientFileArr as $cf){
            $fileList[md5($cf)] = [
                'file'=>$cf,
                'time'=>filemtime($cf)
            ];
        }
        unset($fileTreeLib);

        echo PHP_EOL;
        echo 'start compare files ... ';
        while(true){
            $newFileList = [];
            $fileTreeLib = new FileTree();
            $clientFileArr = $fileTreeLib->fileList($this->config['client-async-path']);
            foreach($clientFileArr as $cf){
                $newFileList[md5($cf)] = [
                    'file'=>$cf,
                    'time'=>filemtime($cf)
                ];
            }
            unset($fileTreeLib);
            foreach($newFileList as $new_key => $new_file){
                if(array_key_exists($new_key,$fileList)){
                    $oriTime = $fileList[$new_key]['time'];
                    $newTime = $new_file['time'];
                    if($oriTime != $newTime){
                        // 更新
                        $this->updateFile($new_file['file']);
                        usleep(1000);
                    }
                }else{
                    // 新增
                    $this->addFile($new_file['file']);
                    usleep(1000);
                }
            }

            $diffArr = array_diff_key($fileList,$newFileList);
            foreach($diffArr as $diff_file){
                // 删除
                $this->deleteFile($diff_file['file']);
                usleep(1000);
            }
            $fileList = $newFileList;
            sleep(5);
        }
    }

    public function addFile($file){
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connectRes = socket_connect($client, $this->config['ip'], $this->config['port']);
        if(!$connectRes){
            echo PHP_EOL;
            echo 'socket connect failed - '.$this->config['ip'].' - '.$this->config['port'];
            exit;
        }
        echo PHP_EOL;
        $relative_file = str_replace($this->config['client-async-path'],'',$file);
        echo 'add - '.$relative_file;

        while (true){
            $response = socket_read($client, 1024, PHP_NORMAL_READ);
            $commandParse = Command::getCommand($response,$this->config['command_separator']);
            if(is_array($commandParse)){
                $command = $commandParse['command'];
                if($command == 'shakeSuccess'){
                    // 发送新增命令
                    socket_write($client, "|@-addFile-@|\n");
                    continue;
                } elseif ($command == 'commandDone'){
                    // 发送新增文件路径
                    socket_write($client, "|@-fileName".$this->config['command_separator'].$relative_file."-@|\n");
                    continue;
                } elseif ($command == 'reachMaxConnections'){
                    echo PHP_EOL;
                    echo 'server reachMaxConnections';
                    break;
                } elseif ($command == 'done'){
                    break;
                } elseif ($command == 'serverLostSocket'){
                    echo PHP_EOL;
                    echo 'server lost socket , retry';
                    break;
                } elseif ($command == 'fileLock'){
                    echo PHP_EOL;
                    echo 'server already lock file , retry';
                    break;
                } elseif ($command == 'paramsError'){
                    echo PHP_EOL;
                    echo 'param error!';
                    break;
                } elseif($command == 'fileOpenError'){
                    echo PHP_EOL;
                    echo 'LOG::SYSTEM_ERROR::server open file error!!!';
                    break;
                } elseif ($command == 'fileNameAfter'){
                    $fp = fopen($file,"rt");
                    $buffer = 900;//每次读取 900 字节
                    while(!feof($fp)){
                        $str = fread($fp,$buffer);
                        socket_write($client, $str);
                        usleep(1000);
                    }
                    socket_write($client, "\n");
                    socket_write($client, "|@-fileComplete-@|\n");
                    continue;
                } else {
                    echo PHP_EOL;
                    echo 'Undefined command';
                    break;
                }

            }else{
            }
        }
        socket_close($client);
    }

    public function updateFile($file){
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connectRes = socket_connect($client, $this->config['ip'], $this->config['port']);
        if(!$connectRes){
            echo 'socket connect failed - '.$this->config['ip'].' - '.$this->config['port'];
            exit;
        }
        echo PHP_EOL;
        $relative_file = str_replace($this->config['client-async-path'],'',$file);
        echo 'update - '.$relative_file;

        while (true){
            $response = socket_read($client, 1024, PHP_NORMAL_READ);
            $commandParse = Command::getCommand($response,$this->config['command_separator']);
            if(is_array($commandParse)){
                $command = $commandParse['command'];
                if($command == 'shakeSuccess'){
                    // 发送新增命令
                    socket_write($client, "|@-updateFile-@|\n");
                    continue;
                } elseif ($command == 'commandDone'){
                    // 发送新增文件路径
                    socket_write($client, "|@-fileName".$this->config['command_separator'].$relative_file."-@|\n");
                    continue;
                } elseif ($command == 'reachMaxConnections'){
                    echo PHP_EOL;
                    echo 'server reachMaxConnections';
                    break;
                } elseif ($command == 'done'){
                    break;
                } elseif ($command == 'serverLostSocket'){
                    echo PHP_EOL;
                    echo 'server lost socket , retry';
                    break;
                } elseif ($command == 'fileLock'){
                    echo PHP_EOL;
                    echo 'server already lock file , retry';
                    break;
                } elseif ($command == 'paramsError'){
                    echo PHP_EOL;
                    echo 'param error!';
                    break;
                } elseif($command == 'fileOpenError'){
                    echo PHP_EOL;
                    echo 'LOG::SYSTEM_ERROR::server open file error!!!';
                    break;
                } elseif ($command == 'fileNameAfter'){
                    $fp = fopen($file,"rt");
                    $buffer = 900;//每次读取 900 字节
                    while(!feof($fp)){//循环读取，直至读取完整个文件
                        //$str = "|@-fileNumber".$this->config['command_separator'].$number.$this->config['command_separator'].fread($fp,$buffer)."-@|\n";
                        $str = fread($fp,$buffer);
                        socket_write($client, $str);
                        usleep(1000); // 暂且以这种方式保证顺序不出错
                    }
                    socket_write($client, "\n");
                    socket_write($client, "|@-fileComplete-@|\n");
                    continue;
                } else {
                    echo PHP_EOL;
                    echo 'Undefined command';
                    break;
                }

            }else{
            }
        }
        socket_close($client);
    }

    public function deleteFile($file){
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connectRes = socket_connect($client, $this->config['ip'], $this->config['port']);
        if(!$connectRes){
            echo 'socket connect failed - '.$this->config['ip'].' - '.$this->config['port'];
            exit;
        }
        echo PHP_EOL;
        $relative_file = str_replace($this->config['client-async-path'],'',$file);
        echo 'delete - '.$relative_file;

        while (true){
            $response = socket_read($client, 1024, PHP_NORMAL_READ);
            $commandParse = Command::getCommand($response,$this->config['command_separator']);
            if(is_array($commandParse)){
                $command = $commandParse['command'];
                if($command == 'shakeSuccess'){
                    // 发送删除命令
                    socket_write($client, "|@-deleteFile-@|\n");
                    continue;
                } elseif ($command == 'commandDone'){
                    // 发送删除文件路径
                    socket_write($client, "|@-fileName".$this->config['command_separator'].$relative_file."-@|\n");
                    continue;
                } elseif ($command == 'reachMaxConnections'){
                    echo PHP_EOL;
                    echo 'server reachMaxConnections';
                    break;
                } elseif ($command == 'done'){
                    break;
                } elseif ($command == 'serverLostSocket'){
                    echo PHP_EOL;
                    echo 'server lost socket , retry';
                    break;
                } elseif ($command == 'fileLock'){
                    echo PHP_EOL;
                    echo 'server already lock file , retry';
                    break;
                } elseif ($command == 'paramsError'){
                    echo PHP_EOL;
                    echo 'param error!';
                    break;
                } elseif ($command == 'fileNameAfter'){
                    socket_write($client, "|@-fileComplete-@|\n");
                    continue;
                } else {
                    echo PHP_EOL;
                    echo 'Undefined command';
                    break;
                }

            }else{
            }
        }
        socket_close($client);
    }
}