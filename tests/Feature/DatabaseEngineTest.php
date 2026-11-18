<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The shop needs InnoDB: orders, stock and coupons are protected by transactions and row locks (`lockForUpdate`), which MyISAM does not have. A host whose
 * MySQL defaults to MyISAM used to make the first migration fail (error 1071, "max key length is 1000 bytes") and, worse, would have worked without any lock.
 */
class DatabaseEngineTest extends TestCase
{
    public function test_the_database_connections_ask_for_innodb_whatever_the_server_defaults_to(): void
    {
        $this->assertSame('InnoDB', config('database.connections.mysql.engine'));
        $this->assertSame('InnoDB', config('database.connections.mariadb.engine'));
    }

    public function test_every_table_of_the_app_is_innodb(): void
    {
        $others = DB::table('information_schema.tables')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_type', 'BASE TABLE')
            ->where('engine', '!=', 'InnoDB')
            ->pluck('engine', 'table_name')
            ->all();

        $this->assertSame([], $others, 'A table that is not InnoDB has no transactions and no row locks.');
    }
}
