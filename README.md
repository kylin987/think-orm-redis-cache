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

### 固定组合键缓存

模型可以把 `cachePk` 配置为多个字段。组合必须唯一，建议建立数据库联合唯一索引；
原来的单字段配置和调用方式不变。

```php
class TenantUser extends \think\Model
{
    use \Kylin987\ThinkOrm\RedisCache\traits\ThinkOrmCache;

    protected $cachePk = ['tenant_id', 'user_id'];

    public static function onAfterUpdate(\think\Model $model): void
    {
        self::delCache($model);
    }

    public static function onAfterDelete(\think\Model $model): void
    {
        self::delCache($model);
    }
}

$user = TenantUser::getRedisCache(['tenant_id' => 10, 'user_id' => 20]);

// 第二个参数为 true 时删除缓存并重新查询数据库。
$user = TenantUser::getRedisCache(['tenant_id' => 10, 'user_id' => 20], true);

// 先读取完整模型再更新；组合字段变化时 delCache 会清理新旧两个键。
$user->save(['user_id' => 21]);

TenantUser::delCache($user);
$user->delete();
```

- 参数必须包含且仅包含模型配置的字段，字段顺序不影响缓存键，只支持等值查询一条记录。
- 字段值只接受整数或字符串，`0` 合法；整数 `10` 与字符串 `'10'` 使用相同缓存键。
- 组合查询只缓存存在的记录，不受 `cache_always` 配置影响。
- 组合清理返回删除成功的键数；单字段清理继续返回原缓存驱动的结果。
- 缓存时间按模型 `cacheExpTime`、旧配置 `thinkorm.cache_exptime`、
  文档配置 `database.cache_exptime` 的顺序读取，保留已有配置的优先级。
- 更新、删除和手动清理时，模型的当前数据及非空原始数据必须包含全部组合字段；
  不要通过只含主键和修改字段的局部模型更新组合缓存记录。
- 直接 `where()->update()` / `where()->delete()` 的批量操作不会触发模型事件，
  需要调用方主动清理受影响记录的新旧组合缓存。
- 两个平台的组合缓存键规则相同。共享缓存时，应配置相同的字段顺序，
  并使用一致的字段值表示（不要交替使用 `'010'` 和 `10`，
  或大小写不同但数据库认为相同的字符串）。

### 2.x 升级说明

`getRedisCacheOption()` 和 `getRedisCacheByWhere()` 已移除，因为任意条件缓存
无法通过模型字段可靠清理。升级前应把这些调用改为数据库查询，
或按业务自行实现有明确失效规则的缓存；`getRedisCache()` 和 `delCache()` 继续保留。

### 本地回归检查

安装依赖并启用 PDO SQLite 后，运行 `php tests/composite-cache.php`。
检查使用临时 SQLite 和文件缓存，不连接业务 Redis 或数据库。
