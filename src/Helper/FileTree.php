<?php
namespace Aex\PhpAsync\Helper;

class FileTree{
    public $result = [];

    /**
     * @param string $path 要遍历的文件路径，最好绝对路径，一定要以 /（DIRECTORY_SEPARATOR） 结尾 否则不能遍历
     * @param string $pattern 匹配的文件
     * @param int $max_level 递归最大的深度
     * @param int $curr_level 当前递归的深度
     * @return array
     */
    public function fileList($path = './',$pattern = '{,.}[!.,!..]*',$max_level = 99,$curr_level = 0){
        $curr_level++;
        if($curr_level > $max_level){
            return $this->result;
        }
        $list = glob($path.$pattern,GLOB_BRACE);
        foreach($list as $l){
            if(!is_dir($l)){
                $this->result[] = str_replace('\\','/',$l);
            }else{
                if(substr($l,0,1) == '.'){
                    continue;
                }
                $this->fileList($l.DIRECTORY_SEPARATOR,$pattern,$max_level,$curr_level);
            }
        }
        return $this->result;
    }

    public function fileMD5($file_path){
        return md5_file($file_path);
    }

    public static function mkMutiDir($dir){
    if(!is_dir($dir)){
        if(!self::mkMutiDir(dirname($dir))){
            return false;
        }
        if(!mkdir($dir,0755)){
            return false;
        }
    }
    return true;
}

}