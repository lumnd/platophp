<?php

use plato\database\db;
use plato\database\migration;

return new class extends migration
{
    public function up(): void
    {
        db::statement(
            'CREATE TABLE `#PB#_migration_verify_items` ('
            . '`id` int unsigned NOT NULL AUTO_INCREMENT,'
            . 'PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB'
        );
    }

    public function down(): void
    {
        db::statement('DROP TABLE IF EXISTS `#PB#_migration_verify_items`');
    }
};
