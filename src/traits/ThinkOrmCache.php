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
        $always = config('database.cache_always') ?? false;
        if ($always){
            return self::cacheAlways($key, $cacheExpTime)->where($pk, '=', $id)->find();
        }
        return self::cache($key, $cacheExpTime)->where($pk, '=', $id)->find();
    }

    /**
     * @param $id
     * @param array $option ['cachePk' => '自定义主键']
     * @param $getDb
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function getRedisCacheOption($id, array $option = [], $getDb = false)
    {
        list($key, $pk, $cacheExpTime) = self::getCacheKey(self::getModel(), $id, $option);
        if ($getDb) {
            self::delKey($key);
        }
        $always = config('database.cache_always') ?? false;
        if ($always){
            return self::cacheAlways($key, $cacheExpTime)->where($pk, '=', $id)->find();
        }
        return self::cache($key, $cacheExpTime)->where($pk, '=', $id)->find();
    }

    /**
     * @param $where array // 查询条件
     * @param $orderBy string // 排序
     * @param bool $getDb
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function getRedisCacheByWhere(array $where, string $orderBy = 'id ASC', bool $getDb = false)
    {
        list($key, $pk, $cacheExpTime) = self::getCacheKey(self::getModel(), null, ['where' => $where, 'orderBy' => $orderBy]);
        if ($getDb) {
            self::delKey($key);
        }
        $always = config('database.cache_always') ?? false;
        if ($always){
            return self::cacheAlways($key, $cacheExpTime)->where($where)->order($orderBy)->find();
        }
        return self::cache($key, $cacheExpTime)->where($where)->order($orderBy)->find();
    }

    //删除缓存
    public static function delCache($model, $option = [])
    {
        list($key) = self::getCacheKey($model, null, $option);
        return self::delKey($key);
    }
    
    //redis删除
    private static function delKey($key)
    {
        return Cache::store(config('database.cache_store'))->delete($key);
    }

    //获取redis键和主pk
    private static function getCacheKey($model, $id = null, $option = [])
    {
        $pk = $model->cachePk ?? 'id';
        if (isset($option['cachePk']) && !empty($option['cachePk'])) {
            $pk = $option['cachePk'];
        }
        $cacheExpTime = $model->cacheExpTime ?? config('thinkorm.cache_exptime');
        if (isset($option['cacheExpTime']) && !empty($option['cacheExpTime'])) {
            $cacheExpTime = $option['cacheExpTime'];
        }
        if (isset($option['where']) && $option['where']) {
            return ['orm_' . $model->getTable() . '_' . md5(json_encode($option)), null, $cacheExpTime];
        }
        return ['orm_' . $model->getTable() . '_' . $pk . '_' . (is_null($id) ? $model->$pk : $id), $pk, $cacheExpTime];
    }
}