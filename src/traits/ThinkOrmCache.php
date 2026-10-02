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
        if (is_array($pk)) {
            $values = self::normalizeCacheValues($pk, $id);
            $where = [];
            foreach ($values as $field => $value) {
                $where[] = [$field, '=', $value];
            }
            // 组合键只缓存存在的记录，避免新增记录后仍命中空缓存。
            return self::cache($key, $cacheExpTime)->where($where)->find();
        }
        $always = config('database.cache_always') ?? false;
        if ($always){
            return self::cacheAlways($key, $cacheExpTime)->where($pk, '=', $id)->find();
        }
        return self::cache($key, $cacheExpTime)->where($pk, '=', $id)->find();
    }

    //删除缓存
    public static function delCache($model, $option = [])
    {
        list($key, $pk) = self::getCacheKey($model, null, $option);
        if (is_array($pk)) {
            $keys = [$key];
            $origin = $model->getOrigin();
            if (!empty($origin)) {
                $keys[] = self::getCompositeCacheKey($model, $pk, $origin, false);
            }
            $deleted = 0;
            foreach (array_unique($keys) as $cacheKey) {
                $deleted += self::delKey($cacheKey);
            }
            return $deleted;
        }
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
        $cacheExpTime = $model->cacheExpTime ?? config('thinkorm.cache_exptime') ?? config('database.cache_exptime');
        if (isset($option['cacheExpTime']) && !empty($option['cacheExpTime'])) {
            $cacheExpTime = $option['cacheExpTime'];
        }
        if (isset($option['where']) && $option['where']) {
            return ['orm_' . $model->getTable() . '_' . md5(json_encode($option)), null, $cacheExpTime];
        }
        if (is_array($pk)) {
            $values = is_null($id) ? $model->getData() : $id;
            $key = self::getCompositeCacheKey($model, $pk, $values, !is_null($id));
            return [$key, $pk, $cacheExpTime];
        }
        return ['orm_' . $model->getTable() . '_' . $pk . '_' . (is_null($id) ? $model->$pk : $id), $pk, $cacheExpTime];
    }

    private static function getCompositeCacheKey($model, array $fields, $values, bool $exact = true): string
    {
        $values = self::normalizeCacheValues($fields, $values, $exact);
        // serialize 保留字段边界，避免直接拼接值产生歧义，也支持非 UTF-8 字符串。
        return 'orm_' . $model->getTable() . '_composite_' . hash('sha256', serialize($values));
    }

    private static function normalizeCacheValues(array $fields, $values, bool $exact = true): array
    {
        if (count($fields) < 2) {
            throw new \InvalidArgumentException('组合 cachePk 至少需要两个字段');
        }
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                throw new \InvalidArgumentException('cachePk 字段名必须是非空字符串');
            }
        }
        if (count(array_unique($fields)) !== count($fields)) {
            throw new \InvalidArgumentException('cachePk 字段名不能重复');
        }
        if (!is_array($values) || ($exact && count($values) !== count($fields))) {
            throw new \InvalidArgumentException('组合缓存参数必须包含且仅包含 cachePk 配置的字段');
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $values)) {
                throw new \InvalidArgumentException('缺少组合缓存字段：' . $field);
            }
            if (!is_int($values[$field]) && !is_string($values[$field])) {
                throw new \InvalidArgumentException('组合缓存字段值必须是整数或字符串：' . $field);
            }
            // 数据库返回的数字字符串与调用方传入的整数使用同一个键。
            $normalized[$field] = (string) $values[$field];
        }
        return $normalized;
    }
}
