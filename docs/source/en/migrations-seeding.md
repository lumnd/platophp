# Migrations and Seeding

## Creating migrations

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

Each migrate run creates one batch; rollback reverts the latest batch. The migrator uses a server-side lock to prevent concurrent deploys and loads all migration files before running any of them.

`schema` creates, alters, drops, renames, and inspects tables. `blueprint` supplies common columns, indexes, and modifiers. MySQL and ClickHouse grammars compile the DDL their engines support. Whether DDL is transactional depends on the database engine; do not assume a transaction can reverse structural changes.

## Seeders

```bash
php vendor/bin/plato make:seeder base_roles
php vendor/bin/plato db:seed --class=base_roles
```

Seeders are explicit, repeatable data tasks. The framework has no model factory; domain fixtures and product defaults belong to the host application.
