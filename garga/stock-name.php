<?php
// stock-name.php

// Paths
$stockNameFile = __DIR__ . "/stock-name.json";

// Load stock names
$stockNames = file_exists($stockNameFile) ? json_decode(file_get_contents($stockNameFile), true) : [];
if (!is_array($stockNames)) $stockNames = [];

// Messages for UI
$errorMsg = "";
$successMsg = "";

/**
 * Save stock names to JSON file
 */
function save_stock_names($stockNameFile, $stockNames) {
    file_put_contents($stockNameFile, json_encode($stockNames, JSON_PRETTY_PRINT));
}

// =========================
// Handle Add New Stock Name
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock_name'])) {
    $stock_name = trim($_POST['stock_name'] ?? '');
    $size = trim($_POST['size'] ?? '');
    $quality = trim($_POST['quality'] ?? '');

    $newStockName = [
        "id" => time(),
        "stock_name" => $stock_name,
        "size" => $size,
        "quality" => $quality,
        "date_added" => date("Y-m-d")
    ];

    $stockNames[] = $newStockName;
    save_stock_names($stockNameFile, $stockNames);
    header("Location: stock-name.php");
    exit();
}

// =========================
// Handle Delete Stock Name
// =========================
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stockNames = array_values(array_filter($stockNames, fn($s) => (string)$s['id'] !== (string)$id));
    save_stock_names($stockNameFile, $stockNames);
    header("Location: stock-name.php");
    exit();
}

// =========================
// Handle Search
// =========================
$search = trim($_GET['search'] ?? '');

$contains = function($haystack, $needle) {
    if ($needle === '') return false;
    return (stripos((string)$haystack, (string)$needle) !== false);
};

$matches_search = function($s, $searchTerm) use ($contains) {
    if ($searchTerm === '') return true;
    foreach ($s as $k => $v) {
        if ($v === null) continue;
        if (is_array($v) || is_object($v)) continue;
        if ($contains($v, $searchTerm)) return true;
    }
    
    if (!empty($s['date_added']) && $contains($s['date_added'], $searchTerm)) return true;
    
    if (!empty($s['date_added'])) {
        $dt = DateTime::createFromFormat('Y-m-d', $s['date_added']);
        if ($dt) {
            if ($contains($dt->format('d-m-Y'), $searchTerm)) return true;
        }
    }
    
    if (!empty($s['id']) && is_numeric($s['id'])) {
        $formatted1 = date("d-m-Y H:i", $s['id']);
        $formatted2 = date("Y-m-d", $s['id']);
        if ($contains($formatted1, $searchTerm)) return true;
        if ($contains($formatted2, $searchTerm)) return true;
    }
    
    return false;
};

$filteredStockNames = array_values(array_filter($stockNames, function($s) use ($matches_search, $search) {
    return $matches_search($s, $search);
}));

// =========================
// Handle Export to XLS
// =========================
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $filename = "stock_names_" . date("Ymd_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF";

    echo "<table border='1'><thead>";
    echo "<tr>";
    echo "<th>Stock Name</th>";
    echo "<th>Size</th>";
    echo "<th>Quality</th>";
    echo "<th>Date Added</th>";
    echo "</tr>";
    echo "</thead><tbody>";

    foreach ($filteredStockNames as $s) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($s['stock_name']) . "</td>";
        echo "<td>" . htmlspecialchars($s['size']) . "</td>";
        echo "<td>" . htmlspecialchars($s['quality']) . "</td>";
        echo "<td>" . (!empty($s['date_added']) ? htmlspecialchars($s['date_added']) : (is_numeric($s['id']) ? date("Y-m-d", $s['id']) : '-')) . "</td>";
        echo "</tr>";
    }

    echo "</tbody></table>";
    exit();
}

include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Stock Names Management - Garga Copy Udhyog</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { position: absolute; top: 0; left: 0; width: 100%; }
    table { width: 100%; border-collapse: collapse; }
    table, th, td { border: 1px solid black; }
    th, td { padding: 5px; text-align: left; }
    .print-logo { width: 100px; margin-bottom: 10px; }
    .no-print { display: none !important; }
}
.print-logo { width: 100px; margin-bottom: 10px; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
<h2 class="mb-4">📋 Stock Names Management</h2>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if ($successMsg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<!-- Add New Stock Name -->
<div class="card mb-4">
<div class="card-header">Add New Stock Name</div>
<div class="card-body">
<form method="post" class="row g-2">
    <div class="col-md-4">
        <input type="text" name="stock_name" class="form-control" placeholder="Stock Name *" required>
    </div>
    <div class="col-md-4">
        <input type="text" name="size" class="form-control" placeholder="Size *" required>
    </div>
    <div class="col-md-4">
        <input type="text" name="quality" class="form-control" placeholder="Quality *" required>
    </div>

    <div class="col-12">
        <button type="submit" name="add_stock_name" class="btn btn-primary">➕ Add Stock Name</button>
    </div>
</form>
</div>
</div>

<!-- Search + Print + Export -->
<div class="d-flex mb-3">
    <form method="get" class="d-flex flex-grow-1">
        <input type="text" name="search" class="form-control me-2" placeholder="Search anything in table" value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-secondary me-2">🔍 Search</button>
    </form>

    <button type="button" class="btn btn-info me-2" onclick="printStockNames()">🖨️ Print</button>

    <?php
    $exportUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?export=xls' . ($search ? '&search=' . urlencode($search) : '');
    ?>
    <a href="<?= $exportUrl ?>" class="btn btn-success">📤 Export</a>
</div>

<!-- Stock Names Table -->
<div class="card mt-3" id="printArea">
<div class="card-body p-0">
<div class="text-center mb-3">
<img src="assets/images/logo.png" class="print-logo" alt="Logo"><br>
<h3>Garga Copy Udhyog</h3>
<h4>Stock Names List</h4>
<p><?= date("d M Y") ?></p>
<hr>
</div>
<table class="table table-striped mb-0" id="stockNamesTable">
<thead class="table-dark">
<tr>
<th>Stock Name</th>
<th>Size</th>
<th>Quality</th>
<th>Date Added</th>
<th class="no-print">Action</th>
</tr>
</thead>
<tbody>
<?php if(count($filteredStockNames) > 0): ?>
    <?php foreach($filteredStockNames as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['stock_name']) ?></td>
            <td><?= htmlspecialchars($s['size']) ?></td>
            <td><?= htmlspecialchars($s['quality']) ?></td>
            <td><?= !empty($s['date_added']) ? htmlspecialchars($s['date_added']) : (is_numeric($s['id']) ? date("Y-m-d", $s['id']) : '-') ?></td>
            <td class="no-print">
                <a href="?delete=<?= urlencode($s['id']) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this stock name?')">Delete</a>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
<tr><td colspan="5" class="text-center">No stock names found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<script>
function printStockNames() {
    window.print();
}
</script>

</div> <!-- container -->
<?php include 'footer.php'; ?>
</body>
</html>
