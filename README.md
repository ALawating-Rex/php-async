# php-async
[![license](https://img.shields.io/github/license/mashape/apistatus.svg)](https://github.com/ALawating-Rex/php-async/blob/master/LICENSE) [![LICENSE](https://img.shields.io/badge/license-Anti%20996-blue.svg)](https://github.com/996icu/996.ICU/blob/master/LICENSE)
###介绍
`php-async` 是基于 PHP 开发的，方便开发者直接在本地调试远程 **测试服务器** 代码的工具

###特性
- 使用简单
- 可配置
- 使用 socket 通讯

### 安装
> composer require aex/php-async ^1.0.0 -vvv

###使用

#### 服务端：(async_server.php)
	<?php
	require "../vendor/autoload.php";
	use Aex\PhpAsync\PhpAsync;
	
	$config = []; // 下面会说明各配置项
	$p = new PhpAsync($config);
	$p->fireServer();
	
	
> php async_server.php

#### 客户端 (async_client.php)
	<?php
	require "../vendor/autoload.php";
	use Aex\PhpAsync\PhpAsync;
	
	$config = []; // 下面会说明各配置项
	$p = new PhpAsync($config);
	$p->fireClient();
	
	
	
> php async_client.php

### 配置
#### 服务端
	$config = [
		'ip' => '0.0.0.0', // 监听的ip
		'port' => 8313, // 监听的端口
		'server-async-path' => '/code/test_code/' // 服务端同步目录的绝对路径，以/(DIRECTORY_SEPARATOR)结尾
		'duplicate_name_suffix' => '.pasync'; // 副本文件后缀（生成的临时文件，应避免是你项目会用到的后缀）
		'command_separator' => '(::)'; // 命令分隔符
	];
#### 客户端
	$config = [
		'ip' => '127.0.0.1', // 服务端ip
		'port' => 8313, // 服务端端口
		'client-async-path' => '/code/test_code/' // 客户端同步目录的绝对路径，以/(DIRECTORY_SEPARATOR)结尾
		'duplicate_name_suffix' => '.pasync'; // 副本文件后缀（生成的临时文件，应避免是你项目慧勇斗啊）
		'command_separator' => '(::)'; // 命令分隔符
	];

>说明： **此项目应该避免在生产环境使用，除非你非常了解他可能存在的问题，且能够快速恢复被修改的文件**

### 使用场景举例
>在开发微信项目的时候，很多api只能在外部服务器测试使用，在测试服务器直接修改代码又不能运行得心应手的IDE,如果服务器使用git
>本地代码每次修改都要提交并推送，即使只是修改了一行调试代码。服务器同时需要拉取代码，即使配置钩子，也要有个配置钩子的成本。
>使用 `php-async` 使得你每次本地修改，几乎都能立刻（间隔5秒）在测试环境看到调试效果。调试完成你可以使用 git revert 还原代码

###License
------------
`php-async` is licensed under [The MIT License (MIT)](LICENSE).
