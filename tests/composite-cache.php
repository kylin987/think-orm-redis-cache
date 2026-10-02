<?php

// 独立回归检查：php tests/composite-cache.php
// 使用真实 ThinkPHP 缓存门面、文件缓存和临时 SQLite，不连接业务 Redis/数据库。
require getenv('THINK_CACHE_TEST_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require_once dirname((new ReflectionClass(\think\App::class))->getFileName(), 2) . '/helper.php';
require_once __DIR__ . '/../src/traits/ThinkOrmCache.php';

use Kylin987\ThinkOrm\RedisCache\traits\ThinkOrmCache;
use think\facade\Cache;
use think\Model;

class CompositeCacheFixture extends Model
{
    use ThinkOrmCache;

    protected $table = 'cache_users';
    protected $cachePk = ['tenant_id', 'user_id'];
    protected $cacheExpTime = 300;
    protected $autoWriteTimestamp = false;

    public static function onAfterUpdate(Model $model): void
    {
        self::delCache($model);
    }

    public static function onAfterDelete(Model $model): void
    {
        self::delCache($model);
    }
}

class SingleCacheFixture extends CompositeCacheFixture
{
    protected $cachePk = 'id';
}

class ConfigCacheFixture extends CompositeCacheFixture
{
    protected $cacheExpTime = null;
}

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function compositeKey($tenantId, $userId)
{
    return 'orm_cache_users_composite_' . hash('sha256', serialize([
        'tenant_id' => (string) $tenantId,
        'user_id' => (string) $userId,
    ]));
}

$temp = sys_get_temp_dir() . '/think-composite-cache-' . uniqid('', true);
mkdir($temp, 0700, true);

try {
    $app = new \think\App($temp);
    $app->config->set(['cache_store' => 'ormCache', 'cache_always' => true, 'cache_exptime' => 300], 'database');
    $app->config->set([
        'default' => 'ormCache',
        'stores' => ['ormCache' => ['type' => 'file', 'path' => $temp . '/cache/']],
    ], 'cache');
    $cache = Cache::store('ormCache');
    $db = new \think\DbManager();
    $db->setConfig([
        'default' => 'sqlite',
        'connections' => ['sqlite' => ['type' => 'sqlite', 'database' => '']],
    ]);
    $db->setCache($cache);
    Model::setDb($db);
    $db->execute('CREATE TABLE cache_users (id INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL, user_id TEXT NOT NULL, name TEXT, UNIQUE (tenant_id, user_id))');
    $db->table('cache_users')->insertAll([
        ['id' => 1, 'tenant_id' => '10', 'user_id' => '20', 'name' => 'first'],
        ['id' => 2, 'tenant_id' => '11', 'user_id' => '20', 'name' => 'second'],
    ]);

    $where = ['tenant_id' => 10, 'user_id' => 20];
    $getKey = new ReflectionMethod(CompositeCacheFixture::class, 'getCacheKey');
    $getKey->setAccessible(true);
    check($getKey->invoke(null, new ConfigCacheFixture(), $where)[2] === 300, 'TTL must fall back to documented database configuration');
    $app->config->set(['cache_exptime' => 111], 'thinkorm');
    check($getKey->invoke(null, new ConfigCacheFixture(), $where)[2] === 111, 'Legacy thinkorm TTL must keep its precedence');
    check($getKey->invoke(null, new CompositeCacheFixture(), $where)[2] === 300, 'Model TTL must keep highest precedence');
    check(CompositeCacheFixture::getRedisCache($where)->name === 'first', 'Composite query must match both fields');
    check(CompositeCacheFixture::getRedisCache(['tenant_id' => 11, 'user_id' => 20])->name === 'second', 'Different tenants must not share a key');
    check($cache->has(compositeKey(10, 20)), 'Composite key must match the Webman package format');

    $db->table('cache_users')->where('id', 1)->update(['name' => 'fresh']);
    check(CompositeCacheFixture::getRedisCache(['user_id' => '20', 'tenant_id' => '10'])->name === 'first', 'Order and integer/string differences must still hit the cache');
    $user = CompositeCacheFixture::getRedisCache($where, true);
    check($user->name === 'fresh', 'Forced refresh must read the database');

    // 新组合键也放入缓存，验证更新会同时删除两个实际存在的键。
    $cache->set(compositeKey(12, 21), [['id' => 99, 'tenant_id' => '12', 'user_id' => '21', 'name' => 'stale']], 300);
    $user->save(['tenant_id' => '12', 'user_id' => '21', 'name' => 'moved']);
    check(!$cache->has(compositeKey(10, 20)), 'Update event must clear the old key');
    check(!$cache->has(compositeKey(12, 21)), 'Update event must clear the new key');
    check(CompositeCacheFixture::getRedisCache($where) === null, 'Old combination must not return a moved record');
    $user = CompositeCacheFixture::getRedisCache(['tenant_id' => 12, 'user_id' => 21]);
    check($user->name === 'moved', 'New combination must return the current record');
    check(CompositeCacheFixture::delCache($user) === 1, 'Composite deletion must match the Webman package count');
    $user = CompositeCacheFixture::getRedisCache(['tenant_id' => 12, 'user_id' => 21]);
    $user->save(['name' => 'renamed']);
    check(!$cache->has(compositeKey(12, 21)), 'Unchanged combination must still be invalidated');
    $user = CompositeCacheFixture::getRedisCache(['tenant_id' => 12, 'user_id' => 21]);
    check($user->name === 'renamed', 'Updated payload must be visible');
    $user->delete();
    check(!$cache->has(compositeKey(12, 21)), 'Delete event must clear the cache');
    check(CompositeCacheFixture::getRedisCache(['tenant_id' => 12, 'user_id' => 21]) === null, 'Deleted record must not be returned');

    check(CompositeCacheFixture::getRedisCache(['tenant_id' => 0, 'user_id' => 0]) === null, 'Missing record should be null');
    check(!$cache->has(compositeKey(0, 0)), 'Composite query must not cache a missing record');
    $db->table('cache_users')->insert(['id' => 3, 'tenant_id' => '0', 'user_id' => '0', 'name' => 'created']);
    check(CompositeCacheFixture::getRedisCache(['tenant_id' => 0, 'user_id' => 0])->name === 'created', 'A newly inserted zero-valued combination must be visible');

    $db->table('cache_users')->insertAll([
        ['id' => 4, 'tenant_id' => 'a_b', 'user_id' => 'c', 'name' => 'left'],
        ['id' => 5, 'tenant_id' => 'a', 'user_id' => 'b_c', 'name' => 'right'],
    ]);
    check(CompositeCacheFixture::getRedisCache(['tenant_id' => 'a_b', 'user_id' => 'c'])->name === 'left', 'First delimited combination should match');
    check(CompositeCacheFixture::getRedisCache(['tenant_id' => 'a', 'user_id' => 'b_c'])->name === 'right', 'Field boundaries must not collide');

    foreach ([10, ['tenant_id' => 10], ['tenant_id' => 10, 'user_id' => 20, 'extra' => 1], ['tenant_id' => null, 'user_id' => 20], ['tenant_id' => false, 'user_id' => 20], ['tenant_id' => [], 'user_id' => 20]] as $values) {
        $rejected = false;
        try {
            CompositeCacheFixture::getRedisCache($values, true);
        } catch (InvalidArgumentException $exception) {
            $rejected = true;
        }
        check($rejected, 'Invalid composite parameters must be rejected');
    }

    $partialRejected = false;
    try {
        CompositeCacheFixture::delCache(new CompositeCacheFixture(['id' => 2, 'user_id' => 20]));
    } catch (InvalidArgumentException $exception) {
        $partialRejected = true;
    }
    check($partialRejected, 'Partial models must not silently clear the wrong key');
    $single = SingleCacheFixture::getRedisCache(2);
    check($single->name === 'second', 'Single-field query must remain compatible');
    check($cache->has('orm_cache_users_id_2'), 'Single-field key must remain compatible');
    check(SingleCacheFixture::delCache($single) === true, 'Single-field deletion result must remain compatible');
    check(SingleCacheFixture::getRedisCache(99) === null, 'Missing single-field record should be null');
    check($cache->has('orm_cache_users_id_99'), 'Single-field cache_always behavior must remain compatible');

    echo 'OK: ' . $checks . " checks\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($temp);
}
