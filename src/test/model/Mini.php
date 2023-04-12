<?php

namespace Kylin987\ThinkOrm\RedisCache\test\model;

use Kylin987\ThinkOrm\RedisCache\traits\ThinkOrmCache;
use think\Model;

class Mini extends Model
{
    use ThinkOrmCache;

    protected $cachePk = 'aapid';          //默认为id，如果id作为常用查询键，这一行可以省略


    //加入此行，自动更新缓存
    public static function onAfterUpdate(Model $model): void
    {
        self::delCache($model);
    }
}