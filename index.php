<?php
/**
 * Main timer page
 * EMBED_MODE: defined by embed.php — suppresses header/footer
 */

require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/logger.php';

$config      = load_config();
$currentUser = get_koma_user();
$komaCount   = (int)$config['koma_count'];
$today       = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$session     = load_session($today);

// Build koma state map by slot
$komaMap = [];
foreach ($session['koma'] as $k) {
    $komaMap[(int)$k['id']] = $k;
}

$isEmbed = defined('EMBED_MODE') && EMBED_MODE;

function status_label(string $status): string {
    return match($status) {
        'running'      => '実行中',
        'paused'       => '一時停止',
        'completed'    => '完了',
        'overtime'     => '超過中',
        'overtime_max' => '超過完了',
        default        => '未開始',
    };
}

function status_class(string $status): string {
    return match($status) {
        'running'      => 'is-running',
        'paused'       => 'is-paused',
        'completed'    => 'is-completed',
        'overtime'     => 'is-overtime',
        'overtime_max' => 'is-overtime-max',
        default        => '',
    };
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>コマタイマー</title>
    <link rel="stylesheet" href="/assets/css/timer.css">
</head>
<body>
<?php if (!$isEmbed): ?>
    <?php include __DIR__ . '/includes/header.php'; ?>
<?php endif; ?>

<main class="page-main">
    <?php if (!$isEmbed): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h1 class="page-title" style="margin:0;"><?= htmlspecialchars($today) ?> のコマ</h1>
            <span style="font-size:12px;color:var(--text-muted);">
                <?= htmlspecialchars($currentUser['nickname']) ?>
            </span>
        </div>
    <?php endif; ?>

    <!-- 前日の未完了コマエリア（JSで描画） -->
    <div id="prev-incomplete-area"></div>

    <div class="koma-grid" id="koma-grid">
        <?php for ($slot = 1; $slot <= $komaCount; $slot++):
            $k      = $komaMap[$slot] ?? null;
            $status = $k['status'] ?? 'idle';
            $sClass = status_class($status);
            $sLabel = status_label($status);
            $name   = $k['name'] ?? '';
            $projId = $k['project_id'] ?? '';
            $breakAfter = (bool)($k['break_after'] ?? false);
        ?>
        <div class="koma-card <?= $sClass ?>" id="koma-card-<?= $slot ?>" data-slot="<?= $slot ?>">

            <!-- Header row -->
            <div class="koma-card__header">
                <span class="koma-card__slot">コマ <?= $slot ?></span>
                <span class="koma-card__status-badge <?= htmlspecialchars($status) ?>" id="koma-status-<?= $slot ?>">
                    <?= htmlspecialchars($sLabel) ?>
                </span>
            </div>

            <!-- Name input -->
            <input
                type="text"
                class="koma-card__name-input"
                id="koma-name-<?= $slot ?>"
                placeholder="作業内容"
                value="<?= htmlspecialchars($name) ?>"
                data-slot="<?= $slot ?>"
            >

            <!-- Project ID input with datalist -->
            <input
                type="text"
                class="koma-card__project-input"
                id="koma-project-<?= $slot ?>"
                placeholder="#project/"
                value="<?= htmlspecialchars($projId) ?>"
                list="project-history-list"
                data-slot="<?= $slot ?>"
            >

            <!-- Progress bar -->
            <div class="koma-card__progress-wrap">
                <div class="koma-card__progress-bar">
                    <div class="koma-card__progress-fill" id="koma-fill-<?= $slot ?>" style="width:0%"></div>
                </div>
                <div class="koma-card__progress-labels">
                    <span class="koma-card__progress-pct" id="koma-pct-<?= $slot ?>">0%</span>
                    <span class="koma-card__overtime-label" id="koma-overtime-<?= $slot ?>" style="display:none"></span>
                </div>
            </div>

            <!-- Time display -->
            <div class="koma-card__time">
                <div>
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">経過</div>
                    <div class="koma-card__time-elapsed" id="koma-elapsed-<?= $slot ?>">0:00:00</div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">残り</div>
                    <div class="koma-card__time-remaining" id="koma-remaining-<?= $slot ?>">1:20:00</div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="koma-card__actions">
                <button class="btn btn-start"    id="btn-start-<?= $slot ?>"    data-slot="<?= $slot ?>">開始</button>
                <button class="btn btn-pause"    id="btn-pause-<?= $slot ?>"    data-slot="<?= $slot ?>" style="display:none">停止</button>
                <button class="btn btn-complete" id="btn-complete-<?= $slot ?>" data-slot="<?= $slot ?>">完了</button>
            </div>

            <!-- Break checkbox -->
            <label class="koma-card__break<?= $breakAfter ? ' is-checked' : '' ?>" id="koma-break-label-<?= $slot ?>">
                <input
                    type="checkbox"
                    id="koma-break-<?= $slot ?>"
                    data-slot="<?= $slot ?>"
                    <?= $breakAfter ? 'checked' : '' ?>
                >
                10分休憩
            </label>

        </div>
        <?php endfor; ?>
    </div>
</main>

<!-- Datalist for project history -->
<datalist id="project-history-list">
    <?php foreach ($currentUser['project_history'] as $ph): ?>
        <option value="<?= htmlspecialchars($ph) ?>">
    <?php endforeach; ?>
</datalist>

<?php if (!$isEmbed): ?>
    <?php include __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>

<script>
    // Collect yesterday's incomplete komas for the prev_incomplete area
    // (same logic as api/timer.php get_state, but PHP-side for initial render)
    <?php
    $prevIncomplete = [];
    $tz2 = new DateTimeZone('Asia/Tokyo');
    $yesterday = (new DateTime('-1 day', $tz2))->format('Y-m-d');
    $prevSession = load_session($yesterday);
    foreach ($prevSession['koma'] as $pk) {
        if (in_array($pk['status'], ['running', 'paused', 'overtime'])) {
            $prevIncomplete[] = ['date' => $yesterday, 'koma' => $pk];
        }
    }
    ?>
    window.KOMA_CONFIG = {
        komaCount:       <?= $komaCount ?>,
        komaDurationSec: <?= (int)$config['koma_duration_minutes'] * 60 ?>,
        maxDurationSec:  <?= (int)$config['max_duration_minutes'] * 60 ?>,
        today:           "<?= $today ?>",
        initialState:    <?= json_encode($session, JSON_UNESCAPED_UNICODE) ?>,
        prevIncomplete:  <?= json_encode($prevIncomplete, JSON_UNESCAPED_UNICODE) ?>,
    };
</script>
<script src="/assets/js/timer.js"></script>
</body>
</html>
