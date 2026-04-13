<?php
/**
 * Site header — only included when not in EMBED_MODE
 */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$navItems = [
    'index'    => ['label' => 'タイマー', 'href' => '/'],
    'stats'    => ['label' => '統計',     'href' => '/stats.php'],
    'settings' => ['label' => '設定',     'href' => '/settings.php'],
    'help'     => ['label' => '使い方',   'href' => '/help.php'],
];
?>
<header class="site-header">
    <div class="site-header__inner">
        <a href="/" class="site-header__logo">コマタイマー</a>
        <nav class="site-header__nav">
            <?php foreach ($navItems as $page => $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>"
                   class="site-header__nav-link<?= $currentPage === $page ? ' is-active' : '' ?>">
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="site-header__user">
            <?= htmlspecialchars($currentUser['nickname'] ?? 'もちさん') ?>
        </div>
    </div>
</header>
