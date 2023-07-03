# 基于thinkphp6的跨项目共享数据缓存的composer包
此包仅适用于thinkphp6和thinkphp8框架使用，同时提供适用于webman的包[点这里](https://github.com/kylin987/webman-thinkorm-redis-cache)

## 安装
```
composer require kylin987/think-orm-redis-cache
```

## 使用
### 1、引入：
```
参考test/model/User.php和Mini.php文件
```
### 2、配置：
```
1、修改config/database.php，添加以下3个配置

//数据库缓存store
    'cache_store'       => 'ormCache',
    //缓存时间
    'cache_exptime' => 172800,
    //空数据是否仍然缓存
    'cache_always' => true,

2、修改config/cache.php，增加一个缓存store，名字ormCache和上面的配置保持一致，下面的配置根据需求自行配置

// ormredis缓存
'ormCache'  =>  [
    // 驱动方式
    'type'   => 'redis',
    // 服务器地址
    'host'   => env('cache.redis_host','127.0.0.1'),
    //端口
    'port'   => env('cache.redis_port','6379'),
    //密码
    'password' => env('cache.redis_password',123456),
    //
    'select' => 5,
],
```
### 2、使用：
```
//获取数据
$id = 10;
$user = User::getRedisCache($id);

//更新数据
//正常使用模型更新数据即可，也可以手动清理缓存触发后续的更新缓存
$id = 10;
$user = User::getRedisCache($id);
User::delCache($user);
```