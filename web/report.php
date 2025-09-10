<?php
// weekly_report.php

$dbHost = '127.0.0.1';
$dbName = 'time_tracking';
$dbUser = 'root';
$dbPass = 'Aashu@10';
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$week = isset($_GET['week']) ? intval($_GET['week']) : null;

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

if (!$year || !$week) {
    $dt = new DateTime("now");
    $week = intval($dt->format("W"));
    $year = intval($dt->format("o")); // ISO year
}

// --- Weekly summary query ---
$sqlSummary = "
WITH logged AS (
  SELECT
    user_id,
    YEAR(login_time) AS year,
    WEEK(login_time, 3) AS iso_week,
    SUM(TIMESTAMPDIFF(SECOND, login_time, IFNULL(logout_time, NOW()))) AS logged_seconds
  FROM sessions
  GROUP BY user_id, YEAR(login_time), WEEK(login_time, 3)
),
idle AS (
  SELECT
    s.user_id,
    YEAR(ie.idle_start) AS year,
    WEEK(ie.idle_start, 3) AS iso_week,
    SUM(ie.duration_seconds) AS idle_seconds
  FROM idle_events ie
  JOIN sessions s ON s.session_id = ie.session_id
  GROUP BY s.user_id, YEAR(ie.idle_start), WEEK(ie.idle_start, 3)
)
SELECT
  l.user_id,
  l.year,
  l.iso_week,
  ROUND(l.logged_seconds/3600, 2) AS logged_hours,
  ROUND(IFNULL(i.idle_seconds,0)/3600, 2) AS idle_hours,
  ROUND((l.logged_seconds - IFNULL(i.idle_seconds,0))/3600, 2) AS productive_hours,
  IFNULL(i.idle_seconds,0) / NULLIF(l.logged_seconds,0) AS idle_ratio
FROM logged l
LEFT JOIN idle i
  ON i.user_id = l.user_id AND i.year = l.year AND i.iso_week = l.iso_week
WHERE l.year = :year AND l.iso_week = :week
ORDER BY idle_ratio DESC, l.user_id;
";

$stmt = $pdo->prepare($sqlSummary);
$stmt->execute([':year' => $year, ':week' => $week]);
$summaryRows = $stmt->fetchAll();

// --- Detailed sessions query ---
$sqlDetails = "
SELECT 
  s.session_id,
  s.user_id,
  s.machine_id,
  s.login_time,
  s.logout_time,
  ROUND(TIMESTAMPDIFF(SECOND, s.login_time, IFNULL(s.logout_time, NOW()))/3600, 2) AS logged_hours,
  ROUND(s.total_idle_seconds/3600, 2) AS idle_hours,
  ROUND((TIMESTAMPDIFF(SECOND, s.login_time, IFNULL(s.logout_time, NOW())) - s.total_idle_seconds)/3600, 2) AS productive_hours
FROM sessions s
WHERE YEAR(s.login_time) = :year AND WEEK(s.login_time, 3) = :week
ORDER BY s.login_time DESC;
";

$stmt2 = $pdo->prepare($sqlDetails);
$stmt2->execute([':year' => $year, ':week' => $week]);
$detailRows = $stmt2->fetchAll();

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Workforce Report — Week <?php echo htmlspecialchars($week) . ' / ' . htmlspecialchars($year); ?></title>
<style>
  body { font-family: Arial, sans-serif; padding: 20px; }
  table { border-collapse: collapse; width: 100%; max-width: 1200px; margin-bottom: 30px; }
  th, td { padding: 8px 10px; border: 1px solid #ddd; text-align: left; }
  th { background: #f2f2f2; }
  tr.highlight { background: #ffe6e6; } /* >20% idle */
  .ratio { white-space: nowrap; }
  .summary { margin-bottom: 12px; }
  h2 { margin-top: 40px; }
</style>
</head>
<body>
<h1>Workforce Report — ISO Week <?php echo htmlspecialchars($week) . ' / ' . htmlspecialchars($year); ?></h1>

<div class="summary">
  <form method="get" action="">
    Year: <input name="year" type="number" value="<?php echo htmlspecialchars($year); ?>" style="width:80px"/>
    Week: <input name="week" type="number" value="<?php echo htmlspecialchars($week); ?>" style="width:60px"/>
    <button type="submit">Show</button>
  </form>
</div>

<h2>Weekly Summary</h2>
<?php if (count($summaryRows) === 0): ?>
  <p>No summary data found for this week.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>User</th>
        <th>Logged (hrs)</th>
        <th>Idle (hrs)</th>
        <th>Productive (hrs)</th>
        <th>Idle %</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($summaryRows as $r):
          $idle_ratio = isset($r['idle_ratio']) ? floatval($r['idle_ratio']) : 0.0;
          $highlight = ($idle_ratio > 0.20) ? 'highlight' : '';
      ?>
      <tr class="<?php echo $highlight; ?>">
        <td><?php echo htmlspecialchars($r['user_id']); ?></td>
        
        <td><?php echo number_format(floatval($r['logged_hours']), 2); ?></td>
        <td><?php echo number_format(floatval($r['idle_hours']), 2); ?></td>
        <td><?php echo number_format(floatval($r['productive_hours']), 2); ?></td>
        <td class="ratio"><?php echo number_format($idle_ratio * 100, 1) . '%'; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Detailed Sessions</h2>
<?php if (count($detailRows) === 0): ?>
  <p>No sessions found for this week.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Session ID</th>
        <th>User</th>
        <th>Machine</th>
        <th>Login Time</th>
        <th>Logout Time</th>
        <th>Logged (hrs)</th>
        <th>Idle (hrs)</th>
        <th>Productive (hrs)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($detailRows as $d): ?>
      <tr>
        <td><?php echo htmlspecialchars($d['session_id']); ?></td>
        <td><?php echo htmlspecialchars($d['user_id']); ?></td>
        <td><?php echo htmlspecialchars($d['machine_id']); ?></td>
        <td><?php echo (new DateTime($d['login_time']))->format('Y-m-d H:i:s'); ?></td>
        <td><?php echo $d['logout_time'] ? (new DateTime($d['logout_time']))->format('Y-m-d H:i:s') : 'N/A'; ?></td>
        <td><?php echo number_format(floatval($d['logged_hours']), 2); ?></td>
        <td><?php echo number_format(floatval($d['idle_hours']), 2); ?></td>
        <td><?php echo number_format(floatval($d['productive_hours']), 2); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>

  </table>
<?php endif; ?>

</body>
</html>
