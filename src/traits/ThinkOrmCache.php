<?php

namespace Kylin987\ThinkOrm\RedisCache\traits;


use think\facade\Cache;

trait ThinkOrmCache
{
    public static $cacheExpTime = 172800;   //2天

    //获取主键缓存
    public static function getCache($id, $getDb = false)
    {
        list($key, $pk) = self::getCacheKey(self::getModel(), $id);
        if ($getDb) {
            self::delKey($key);
        }
        $cacheExpTime = config('database.cache_exptime') ?? self::$cacheExpTime;
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
        return ['orm_' . $model->getTable() . '_' . (is_null($id) ? $model->$pk : $id), $pk];
    }
}