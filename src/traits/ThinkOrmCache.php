<?php

namespace Kylin987\ThinkOrm\RedisCache\traits;


use think\facade\Cache;

trait ThinkOrmCache
{
    //获取主键缓存
    public static function getRedisCache($id, $getDb = false)
    {
        list($key, $pk, $cacheExpTime) = self::getCacheKey(self::getModel(), $id);
        if ($getDb) {
            self::delKey($key);
        }
        $always = config('database.cache_always') ?? true;
        $res = self::cache($key, $cacheExpTime, null, $always)->where($pk, '=', $id)->find();
        return $res;
    }

    //删除缓存
    public static function delCache($model)
    {
        list($key) = self::getCacheKey($model);
        return self::delKey($key);
    }
    
    //redis删除
    private static function delKey($key)
    {
        return Cache::store(config('database.cache_store'))->delete($key);
    }

    //获取redis键和主pk
    private static function getCacheKey($model, $id = null)
    {
        $pk = $model->cachePk ?? 'id';
        $cacheExpTime = $model->cacheExpTime ?? config('thinkorm.cache_exptime');
        return ['orm_' . $model->getTable() . '_' . (is_null($id) ? $model->$pk : $id), $pk, $cacheExpTime];
    }
}