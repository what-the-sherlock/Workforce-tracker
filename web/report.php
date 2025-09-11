<?php
// weekly_report.php

$dbHost = '127.0.0.1';
$dbName = 'time_tracking';
$dbUser = 'root';
$dbPass = 'Aashu@10';
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$week = isset($_GET['week']) ? intval($_GET['week']) : null;
$userFilter = isset($_GET['user']) ? trim($_GET['user']) : '';
$machineFilter = isset($_GET['machine']) ? trim($_GET['machine']) : '';

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
  ROUND((l.logged_seconds - IFNULL(i.idle_seconds,0))/3600, 2) AS productive_hours
FROM logged l
LEFT JOIN idle i
  ON i.user_id = l.user_id AND i.year = l.year AND i.iso_week = l.iso_week
WHERE l.year = :year AND l.iso_week = :week
ORDER BY l.user_id;
";
$stmt = $pdo->prepare($sqlSummary);
$stmt->execute([':year' => $year, ':week' => $week]);
$summaryRows = $stmt->fetchAll();

// --- Apply filter on summary (for charts too) ---
if ($userFilter) {
    $summaryRows = array_filter($summaryRows, fn($r) => $r['user_id'] === $userFilter);
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Workforce Tracker Dashboard</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>
</head>
<body class="bg-light">
<div class="container py-4">

  <h1 class="mb-3 text-center">Workforce Tracker</h1>
  <h4 class="text-center text-muted">Year <?= htmlspecialchars($year) ?> | Week <?= htmlspecialchars($week) ?></h4>

  <!-- Filters -->
  <form method="get" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
    <div class="col-md-2">
      <input type="number" name="year" placeholder="Year" value="<?= htmlspecialchars($year) ?>" class="form-control">
    </div>
    <div class="col-md-2">
      <input type="number" name="week" placeholder="Week" value="<?= htmlspecialchars($week) ?>" class="form-control">
    </div>
    <div class="col-md-3">
      <input type="text" name="user" placeholder="User (optional)" value="<?= htmlspecialchars($userFilter) ?>" class="form-control">
    </div>
    <div class="col-md-3">
      <input type="text" name="machine" placeholder="Machine (optional)" value="<?= htmlspecialchars($machineFilter) ?>" class="form-control">
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary w-100">Apply</button>
    </div>
  </form>

  <!-- Weekly Summary -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold">Weekly Summary</div>
    <div class="card-body table-responsive">
      <table id="summaryTable" class="table table-striped table-sm">
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
            $idle_ratio = $r['logged_hours'] > 0 ? round(($r['idle_hours'] / $r['logged_hours']) * 100, 1) : 0;
        ?>
          <tr>
            <td><?= htmlspecialchars($r['user_id']) ?></td>
            <td><?= $r['logged_hours'] ?></td>
            <td><?= $r['idle_hours'] ?></td>
            <td><?= $r['productive_hours'] ?></td>
            <td><?= $idle_ratio ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" onclick="exportCSV()">Export CSV</button>
        <button class="btn btn-sm btn-outline-danger" onclick="exportPDF()">Export PDF</button>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row">
    <div class="col-md-6">
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold">Logged vs Idle Hours</div>
        <div class="card-body" style="height:280px;">
          <canvas id="hoursChart"></canvas>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card shadow-sm mb-4">
        <div class="card-header fw-bold">Productivity %</div>
        <div class="card-body" style="height:280px;">
          <canvas id="productivityChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Data from PHP
const summaryData = <?= json_encode(array_values($summaryRows)) ?>;

const labels = summaryData.map(r => r.user_id);
const logged = summaryData.map(r => parseFloat(r.logged_hours));
const idle = summaryData.map(r => parseFloat(r.idle_hours));
const productive = summaryData.map(r => parseFloat(r.productive_hours));

// Chart 1: Logged vs Idle
new Chart(document.getElementById('hoursChart'), {
  type: 'bar',
  data: {
    labels: labels,
    datasets: [
      { label: 'Logged', data: logged, backgroundColor: 'rgba(54,162,235,0.7)' },
      { label: 'Idle', data: idle, backgroundColor: 'rgba(255,99,132,0.7)' }
    ]
  },
  options: { responsive: true, maintainAspectRatio: false }
});

// Chart 2: Productivity %
new Chart(document.getElementById('productivityChart'), {
  type: 'doughnut',
  data: {
    labels: ['Productive', 'Idle'],
    datasets: [{
      data: [
        productive.reduce((a,b)=>a+b,0), 
        idle.reduce((a,b)=>a+b,0)
      ],
      backgroundColor: ['rgba(75,192,192,0.7)','rgba(255,159,64,0.7)']
    }]
  },
  options: { responsive: true, maintainAspectRatio: false }
});

// Export CSV
function exportCSV() {
  let rows = [];
  document.querySelectorAll("#summaryTable tr").forEach(tr => {
    let row = [];
    tr.querySelectorAll("th,td").forEach(td => row.push(td.innerText));
    rows.push(row.join(","));
  });
  let blob = new Blob([rows.join("\n")], { type: "text/csv" });
  let a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "summary.csv";
  a.click();
}

// Export PDF
function exportPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  doc.text("Weekly Summary", 10, 10);
  doc.autoTable({ html: '#summaryTable' });
  doc.save("summary.pdf");
}
</script>
</body>
</html>
