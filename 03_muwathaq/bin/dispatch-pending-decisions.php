<?php

declare(strict_types=1);

use Muwathaq\CoreDecisionClient;
use Muwathaq\Database;
use function Muwathaq\now;

require __DIR__.'/../src/bootstrap.php';

$db = Database::pdo();
$cases = $db->query("SELECT * FROM muwathaq_cases WHERE core_delivery_status = 'pending' AND decision IS NOT NULL ORDER BY updated_at ASC LIMIT 100")->fetchAll();
foreach ($cases as $case) {
    try {
        CoreDecisionClient::deliver($case);
        $db->prepare('UPDATE muwathaq_cases SET core_delivery_status="delivered",core_delivered_at=?,core_last_error=NULL,updated_at=? WHERE id=?')->execute([now(), now(), $case['id']]);
        echo "Delivered {$case['case_ulid']}\n";
    } catch (Throwable $error) {
        $db->prepare('UPDATE muwathaq_cases SET core_last_error=?,updated_at=? WHERE id=?')->execute([mb_substr($error->getMessage(), 0, 500), now(), $case['id']]);
        fwrite(STDERR, "Failed {$case['case_ulid']}: {$error->getMessage()}\n");
    }
}
