<?php
// Add beside your existing db.php, login.php, logout.php and test.php.
// This page reads the existing tables; no database migration is needed.
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/db.php';

function dh_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function dh_initial($name) {
    return preg_match('/\S/u', (string) $name, $match) ? $match[0] : '?';
}

$user_id = (int) $_SESSION['user_id'];
$user = null;
$households = [];
$active_household = null;
$members = [];
$page_error = '';
$notice = '';
if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
}

try {
    // Use current database values, not a possibly stale display name in a session.
    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // This query is the access boundary: only this user's households are loaded.
    $stmt = $pdo->prepare(
        'SELECT h.id, h.name, h.join_code, hm.role
         FROM household_members hm
         JOIN households h ON h.id = hm.household_id
         WHERE hm.user_id = ? ORDER BY h.name, h.id'
    );
    $stmt->execute([$user_id]);
    $households = $stmt->fetchAll();

    // Switching affects this session only. Verify both CSRF and membership.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['dashboard_csrf'], $token)) {
            http_response_code(403);
            $notice = 'Your request could not be verified. Refresh the page and try again.';
        } else {
            $requested = $_POST['household_id'] ?? '';
            $permitted = false;
            if (is_string($requested) && ctype_digit($requested)) {
                foreach ($households as $household) {
                    if ((string) $household['id'] === $requested) {
                        $_SESSION['active_household_id'] = $household['id'];
                        $permitted = true;
                        break;
                    }
                }
            }
            if ($permitted) {
                // Redirect prevents form resubmission when refreshing.
                header('Location: dashboard.php', true, 303);
                exit;
            }
            http_response_code(403);
            $notice = 'You can only switch to a household you belong to.';
        }
    }

    // A removed membership or stale session must never expose another household.
    $active_id = $_SESSION['active_household_id'] ?? null;
    foreach ($households as $household) {
        if ((string) $household['id'] === (string) $active_id) {
            $active_household = $household;
            break;
        }
    }
    if (!$active_household && $households) {
        $active_household = $households[0];
        $_SESSION['active_household_id'] = $active_household['id'];
    }
    if (!$active_household) {
        unset($_SESSION['active_household_id']);
    } else {
        // Recheck membership in the query as well as selecting from the allowed list.
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, hm.role
             FROM household_members hm
             JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = ?
             AND EXISTS (
                 SELECT 1 FROM household_members viewer
                 WHERE viewer.household_id = hm.household_id AND viewer.user_id = ?
             )
             ORDER BY CASE WHEN hm.role = ? THEN 0 ELSE 1 END, u.name, u.id'
        );
        $stmt->execute([$active_household['id'], $user_id, 'owner']);
        $members = $stmt->fetchAll();
    }
} catch (PDOException $exception) {
    error_log('Dormhub dashboard query failed: ' . $exception->getMessage());
    http_response_code(500);
    $page_error = 'We could not load your household. Please try again or check the database setup.';
    $active_household = null;
    $households = [];
    $members = [];
}
$display_name = $user ? $user['name'] : 'Roommate';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home base · Dormhub</title>
    <link rel="stylesheet" href="assets/dormhub-dashboard.css">
    <script src="assets/dormhub-dashboard.js" defer></script>
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">⌂</span>Dorm<span>hub</span></a>
    <p class="sidebar-label">YOUR SHARED SPACE</p>
    <nav aria-label="Main navigation">
        <a class="nav-link active" href="dashboard.php" aria-current="page"><span aria-hidden="true">⌂</span> Home base</a>
        <a class="nav-link" href="test.php?tab=households"><span aria-hidden="true">▦</span> Households</a>
        <span class="nav-link unavailable"><span aria-hidden="true">✓</span> Chores <small>Planned</small></span>
        <span class="nav-link unavailable"><span aria-hidden="true">▧</span> Shopping <small>Planned</small></span>
        <span class="nav-link unavailable"><span aria-hidden="true">◷</span> Calendar <small>Planned</small></span>
        <span class="nav-link unavailable"><span aria-hidden="true">◌</span> Messages <small>Planned</small></span>
    </nav>
    <div class="sidebar-footer"><span class="phase">SPRINT 1 · FOUNDATION</span><p>One home. One team.</p><a href="logout.php" class="logout">Log out</a></div>
</aside>
<main>
    <header class="topbar"><span>My household <span class="slash">/</span> <b>Home base</b></span><div class="account"><span class="avatar small" aria-hidden="true"><?= dh_escape(dh_initial($display_name)) ?></span><span><?= dh_escape($display_name) ?></span></div></header>
    <div class="content">
        <div class="heading"><div><p class="eyebrow">WELCOME HOME</p><h1>Hey, <?= dh_escape($display_name) ?> <span aria-hidden="true">✦</span></h1><p class="subtitle">Your people. Your place. All together.</p></div><a class="button secondary" href="test.php?tab=households">Manage households</a></div>
        <?php if ($page_error): ?>
            <section class="error" role="alert"><h2>Something needs attention</h2><p><?= dh_escape($page_error) ?></p><a class="button secondary" href="dashboard.php">Try again</a></section>
        <?php else: ?>
            <?php if ($notice): ?><p class="error" role="alert"><?= dh_escape($notice) ?></p><?php endif; ?>
            <?php if (!$active_household): ?>
                <section class="empty panel"><span class="empty-icon" aria-hidden="true">⌂</span><p class="eyebrow">YOUR HOME BASE STARTS HERE</p><h2>Find your crew.</h2><p>You’re signed in, but you don’t belong to a household yet. Create one to start building your shared space.</p><a class="button" href="test.php?tab=households">Create a household</a><p class="fine">Already have an invite code? Joining with a code is the next feature to connect.</p></section>
            <?php else: ?>
                <section class="panel household-banner">
                    <div><p class="eyebrow">ACTIVE HOUSEHOLD</p><h2><?= dh_escape($active_household['name']) ?></h2><p><?= count($members) ?> <?= count($members) === 1 ? 'member' : 'members' ?> <span class="separator">·</span> Your role: <?= dh_escape(ucfirst($active_household['role'])) ?></p></div>
                    <?php if (count($households) > 1): ?>
                    <form class="switch-form" method="post" action="dashboard.php">
                        <input type="hidden" name="csrf_token" value="<?= dh_escape($_SESSION['dashboard_csrf']) ?>">
                        <label for="household_id">Switch household</label><div class="switch-controls"><select id="household_id" name="household_id"><?php foreach ($households as $household): ?><option value="<?= dh_escape($household['id']) ?>" <?= (string)$household['id'] === (string)$active_household['id'] ? 'selected' : '' ?>><?= dh_escape($household['name']) ?></option><?php endforeach; ?></select><button class="button secondary" type="submit">Switch</button></div>
                    </form>
                    <?php endif; ?>
                </section>
                <div class="main-grid">
                    <section class="panel crew"><div class="section-heading"><h2>Your crew</h2><span class="badge green">From your household</span></div><p class="muted">The roommates sharing this home with you.</p><ul class="member-list"><?php foreach ($members as $index => $member): ?><li><span class="avatar color-<?= $index % 4 ?>" aria-hidden="true"><?= dh_escape(dh_initial($member['name'])) ?></span><div class="member-name"><b><?= dh_escape($member['name']) ?></b><small><?= (int)$member['id'] === $user_id ? 'You' : 'Household member' ?></small></div><span class="badge <?= $member['role'] === 'owner' ? 'purple' : '' ?>"><?= dh_escape(ucfirst($member['role'])) ?></span></li><?php endforeach; ?></ul></section>
                    <section class="panel invite"><p class="eyebrow">BRING YOUR CREW TOGETHER</p><h2>Your household code</h2><p class="muted">This code was generated when your household was created.</p><div class="code-box"><code id="join-code"><?= dh_escape($active_household['join_code']) ?></code><button class="button secondary" type="button" id="copy-code">Copy code</button></div><p id="copy-status" class="copy-status" role="status" aria-live="polite"></p><div class="note"><b>Invite flow in progress</b><p>Code creation works. Your team still needs to connect the screen that accepts a code and joins this household.</p></div></section>
                </div>
                <div class="section-heading roadmap-heading"><div><p class="eyebrow">BUILDING THE NEXT LEVEL</p><h2>What’s coming to your home base</h2></div><span class="badge">Upcoming sprints</span></div>
                <div class="feature-grid">
                    <section class="panel feature"><span class="feature-icon mint" aria-hidden="true">✓</span><h3>Chores & quests</h3><p>Assign responsibilities and earn progress together.</p><span class="badge">Planned · Week 2</span></section>
                    <section class="panel feature"><span class="feature-icon amber" aria-hidden="true">▧</span><h3>Shared shopping</h3><p>Keep track of what you need and who is bringing it.</p><span class="badge">Planned · Week 2</span></section>
                    <section class="panel feature"><span class="feature-icon violet" aria-hidden="true">▦</span><h3>Room calendar</h3><p>Coordinate plans and reserve shared spaces.</p><span class="badge">Planned · Week 2</span></section>
                </div>
                <section class="panel reward-preview"><div><p class="eyebrow">A LITTLE TEAMWORK. A LITTLE XP.</p><h2>Level up yourself. Level up your home.</h2><p class="muted">Personal and household progress will arrive with the rewards system.</p></div><span class="badge purple">Planned · Rewards</span></section>
            <?php endif; ?>
        <?php endif; ?>
        <footer>Dormhub <span>Shared living, stronger together.</span></footer>
    </div>
</main>
</body>
</html>
