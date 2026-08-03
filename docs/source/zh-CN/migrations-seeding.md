# 迁移与数据填充

## 创建迁移

```bash
php vendor/bin/plato make:migration create_users
php vendor/bin/plato migrate
php vendor/bin/plato migrate:status
php vendor/bin/plato migrate:rollback
```

```php
<?php

use plato\database\blueprint;
use plato\database\migration;
use plato\database\schema;

return new class extends migration {
    public function up(): void
    {
        schema::create('user', function (blueprint $table): void {
            $table->big_increments('id');
            $table->string('email', 190)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        schema::drop_if_exists('user');
    }
};
```

每次 migrate 是一个 batch；rollback 回退最后一批。迁移器使用服务端锁防止两个发布进程同时执行，并在运行前加载全部文件。

`schema` 支持建表、改表、删除、重命名和存在性检查。`blueprint` 提供常见列、索引与修饰符，MySQL 与 ClickHouse grammar 各自编译可支持的 DDL。DDL 是否能回滚由数据库引擎决定，不能假设事务能撤销结构变更。

## Seeder

```bash
php vendor/bin/plato make:seeder base_roles
php vendor/bin/plato db:seed --class=base_roles
```

Seeder 是显式、可重复运行的数据任务。框架不提供 model factory；业务夹具和领域默认数据应由宿主项目定义。
