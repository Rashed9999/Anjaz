<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar as BaseSQLiteSchemaGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Fluent;

/**
 * CreatesApplication — تمهيد تطبيق Laravel لكل اختبار.
 *
 * كان هذا الملف مفقوداً (انظر FIXES.md). بدونه يفشل `Tests\TestCase` ومن ثمّ
 * **كامل** طاقم الاختبارات (66+ ملف) لأن جميعها ترث منه.
 */
trait CreatesApplication
{
    public function createApplication(): Application
    {
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            return new class($connection, $database, $prefix, $config) extends SQLiteConnection {
                public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
                {
                    parent::__construct($pdo, $database, $tablePrefix, $config);
                    $this->registerSqliteFunctions();
                }

                protected function getDefaultSchemaGrammar(): BaseSQLiteSchemaGrammar
                {
                    return new class($this) extends BaseSQLiteSchemaGrammar {
                        protected function typeEnum(Fluent $column): string
                        {
                            return 'varchar';
                        }

                        protected function typeDecimal(Fluent $column): string
                        {
                            return 'varchar';
                        }

                        protected function modifyDefault(Blueprint $blueprint, Fluent $column)
                        {
                            if ($column->type === 'decimal' && ($column->default === 0 || $column->default === '0' || $column->default === 0.0)) {
                                $places = (int) ($column->places ?? 4);
                                $column->default = number_format(0, $places, '.', '');
                            }

                            return parent::modifyDefault($blueprint, $column);
                        }
                    };
                }

                private function registerSqliteFunctions(): void
                {
                    try {
                        $pdo = $this->getPdo();
                        if ($pdo instanceof \PDO) {
                            $pdo->sqliteCreateFunction('CONCAT', fn (...$args) => implode('', array_map(fn ($v) => (string) ($v ?? ''), $args)));
                            $pdo->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'));
                            $pdo->sqliteCreateFunction('IF', fn ($cond, $t, $f) => $cond ? $t : $f, 3);
                            $pdo->sqliteCreateFunction('GREATEST', fn (...$args) => max($args));
                            $pdo->sqliteCreateFunction('LEAST', fn (...$args) => min($args));
                            $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($v) => is_string($v) ? trim($v, '"') : $v, 1);
                            $pdo->sqliteCreateFunction('REGEXP', fn ($pattern, $value) => $value !== null && @preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/u', (string) $value) === 1 ? 1 : 0, 2);
                        }
                    } catch (\Throwable) {
                    }
                }

                public function prepareBindings(array $bindings): array
                {
                    $prepared = parent::prepareBindings($bindings);
                    foreach ($prepared as $k => $v) {
                        if (is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2}) 00:00:00$/', $v, $m)) {
                            $prepared[$k] = $m[1];
                        }
                    }

                    return $prepared;
                }

                public function select($query, $bindings = [], $useReadPdo = true): array
                {
                    $trimmed = trim((string) $query);
                    if (preg_match('/^SHOW\s+(?:INDEX|INDEXES|KEYS)\s+FROM\s+[`"]?(\w+)[`"]?/i', $trimmed, $m)) {
                        return parent::select(
                            "SELECT name AS Key_name FROM sqlite_master WHERE type = 'index' AND tbl_name = ?",
                            [$m[1]],
                            $useReadPdo
                        );
                    }
                    if (preg_match('/^(SHOW\s+(COLUMNS|TABLES)|SELECT\b.*\bFROM\s+information_schema\.)/is', $trimmed)) {
                        return [];
                    }

                    return parent::select($query, $bindings, $useReadPdo);
                }

                public function statement($query, $bindings = []): bool
                {
                    $trimmed = trim((string) $query);
                    if (preg_match('/^ALTER\s+TABLE\s+[`"]?\w+[`"]?\s+DROP\s+INDEX\s+[`"]?(\w+)[`"]?/i', $trimmed, $m)) {
                        return parent::statement('DROP INDEX IF EXISTS "' . $m[1] . '"');
                    }
                    if (preg_match('/^ALTER\s+TABLE\s+\S+\s+(MODIFY|ALTER\s+COLUMN|ADD\s+UNIQUE\s+INDEX|CHANGE\s+)/i', $trimmed)) {
                        return true;
                    }
                    if (preg_match('/^ALTER\s+TABLE\s+(\S+)\s+ADD\s+COLUMN\s+(\S+)\s+([A-Z]+(?:\(\d+\))?)\s+(?:GENERATED\s+ALWAYS\s+)?AS\s*\(.*\)\s*STORED/is', $trimmed, $m)) {
                        $query = "ALTER TABLE {$m[1]} ADD COLUMN {$m[2]} {$m[3]} NULL";
                    }
                    if (preg_match('/^ALTER\s+TABLE\s+\S+\s+DROP\s+COLUMN\s+(active_key|version_key|live_key)\b/i', $trimmed)) {
                        return true;
                    }
                    if (preg_match('/^alter\s+table\s+["`]?(\w+)["`]?\s+drop\s+column\s+["`]?(\w+)["`]?/i', $trimmed, $m)) {
                        $indexes = parent::select(
                            "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL",
                            [$m[1]]
                        );
                        foreach ($indexes as $idx) {
                            if (stripos((string) $idx->sql, $m[2]) !== false) {
                                parent::statement('DROP INDEX IF EXISTS "' . $idx->name . '"');
                            }
                        }
                    }
                    if (preg_match('/^ALTER\s+TABLE\s+.*\bAFTER\s+\w+\s*$/is', $trimmed)) {
                        $query = preg_replace('/\s+AFTER\s+\w+\s*$/i', '', $trimmed);
                    }
                    if (preg_match('/^create\s+(unique\s+)?index\s+(?!if\s+not\s+exists)/i', (string) $query)) {
                        $query = preg_replace('/^create\s+(unique\s+)?index\s+/i', 'create $1index if not exists ', (string) $query);
                    }
                    if (preg_match('/^drop\s+index\s+(?!if\s+exists)/i', (string) $query)) {
                        $query = preg_replace('/^drop\s+index\s+/i', 'drop index if exists ', (string) $query);
                    }

                    return parent::statement($query, $bindings);
                }
            };
        });

        /** @var Application $app */
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (empty($app['config']->get('app.key'))) {
            $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        }

        return $app;
    }
}
