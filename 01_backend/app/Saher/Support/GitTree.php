<?php

namespace App\Saher\Support;

/**
 * SAHER-GATE-002 — **شجرةُ ما على القرص الآن.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * تُحسب بفهرسٍ مؤقّتٍ (`GIT_INDEX_FILE`) ثمّ `git write-tree`، فتُطابق
 * تماماً شجرةَ الالتزام الذي سيُنشأ من هذا المحتوى.
 *
 * **ولا يُمسّ فهرسُ العمل** — وهذا شرطُ §2.1: ساهرٌ راصدٌ لا تابع، وأداةُ
 * رصدٍ تُفسد `git add` الذي يجري بجانبها تُعطّل العمل الذي جاءت ترصده.
 *
 * **وكلُّ ما هنا يُرجع `null` عند التعذّر ولا يرمي.** فحسابُ شجرةٍ فشل
 * لا يجوز أن يُسقط البوّابةَ نفسَها.
 */
final class GitTree
{
    /** شجرةُ المحتوى على القرص — أو `null` إن تعذّر. */
    public static function ofWorkingTree(?string $root = null): ?string
    {
        $root = $root ?? base_path('..');

        if (! is_dir($root . '/.git')) {
            $root = base_path();

            if (! is_dir($root . '/.git')) {
                return null;
            }
        }

        $index = tempnam(sys_get_temp_dir(), 'saher-idx-');

        if ($index === false) {
            return null;
        }

        @unlink($index);   // `git read-tree` يريد مساراً لا ملفّاً فارغاً

        try {
            $env = 'GIT_INDEX_FILE=' . escapeshellarg($index) . ' ';
            $cd = 'cd ' . escapeshellarg($root) . ' && ';

            self::run($cd . $env . 'git read-tree HEAD');
            self::run($cd . $env . 'git add -A');

            $tree = self::run($cd . $env . 'git write-tree');

            return preg_match('~^[0-9a-f]{40}$~', (string) $tree) ? $tree : null;
        } finally {
            @unlink($index);
        }
    }

    /** شجرةُ التزامٍ بعينه. */
    public static function ofCommit(string $sha, ?string $root = null): ?string
    {
        $root = $root ?? base_path('..');

        $out = self::run('cd ' . escapeshellarg($root)
            . ' && git rev-parse ' . escapeshellarg($sha) . '^{tree}');

        return preg_match('~^[0-9a-f]{40}$~', (string) $out) ? $out : null;
    }

    /**
     * آخرُ التزاماتٍ على الفرع الجاري.
     *
     * @return list<array{sha:string,tree:string,subject:string,author:string,at:string}>
     */
    public static function recentCommits(int $limit = 40, ?string $root = null): array
    {
        $root = $root ?? base_path('..');

        $out = self::run('cd ' . escapeshellarg($root)
            . ' && git log --no-merges -n ' . (int) $limit
            . ' --format=%H%x09%T%x09%an%x09%aI%x09%s');

        if ($out === null) {
            return [];
        }

        $rows = [];

        foreach (explode("\n", $out) as $line) {
            $p = explode("\t", $line, 5);

            if (count($p) === 5 && preg_match('~^[0-9a-f]{40}$~', $p[0])) {
                $rows[] = ['sha' => $p[0], 'tree' => $p[1],
                    'author' => $p[2], 'at' => $p[3], 'subject' => $p[4]];
            }
        }

        return $rows;
    }

    public static function currentBranch(?string $root = null): ?string
    {
        $out = self::run('cd ' . escapeshellarg($root ?? base_path('..'))
            . ' && git rev-parse --abbrev-ref HEAD');

        return $out !== '' ? $out : null;
    }

    private static function run(string $cmd): ?string
    {
        $out = @shell_exec($cmd . ' 2>/dev/null');

        return $out === null || $out === false ? null : trim($out);
    }
}

