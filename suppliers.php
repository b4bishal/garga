<?php
// suppliers.php

// Paths
$suppliersFile = __DIR__ . "/suppliers.json";

// Load suppliers
$suppliers = file_exists($suppliersFile) ? json_decode(file_get_contents($suppliersFile), true) : [];
if (!is_array($suppliers)) $suppliers = [];

// Messages for UI
$errorMsg = "";
$successMsg = "";

/**
 * Save suppliers to JSON file
 */
function save_suppliers($suppliersFile, $suppliers) {
    file_put_contents($suppliersFile, json_encode($suppliers, JSON_PRETTY_PRINT));
}

// =========================
// Handle Add New Supplier
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supplier'])) {
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $pan = trim($_POST['pan'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    $newSupplier = [
        "id" => time(),
        "supplier_name" => $supplier_name,
        "address" => $address,
        "contact_number" => $contact_number,
        "pan" => $pan,
        "email" => $email,
        "remarks" => $remarks,
        "date_added" => date("Y-m-d")
    ];

    $suppliers[] = $newSupplier;
    save_suppliers($suppliersFile, $suppliers);
    header("Location: suppliers.php");
    exit();
}

// =========================
// Handle Delete Supplier
// =========================
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $suppliers = array_values(array_filter($suppliers, fn($s) => (string)$s['id'] !== (string)$id));
    save_suppliers($suppliersFile, $suppliers);
    header("Location: suppliers.php");
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

$filteredSuppliers = array_values(array_filter($suppliers, function($s) use ($matches_search, $search) {
    return $matches_search($s, $search);
}));

// =========================
// Handle Export to XLS
// =========================
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $filename = "suppliers_" . date("Ymd_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF";

    echo "<table border='1'><thead>";
    echo "<tr>";
    echo "<th>Supplier Name</th>";
    echo "<th>Address</th>";
    echo "<th>Contact Number</th>";
    echo "<th>PAN</th>";
    echo "<th>Email</th>";
    echo "<th>Remarks</th>";
    echo "<th>Date Added</th>";
    echo "</tr>";
    echo "</thead><tbody>";

    foreach ($filteredSuppliers as $s) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($s['supplier_name']) . "</td>";
        echo "<td>" . htmlspecialchars($s['address']) . "</td>";
        echo "<td>" . htmlspecialchars($s['contact_number']) . "</td>";
        echo "<td>" . htmlspecialchars($s['pan']) . "</td>";
        echo "<td>" . htmlspecialchars($s['email']) . "</td>";
        echo "<td>" . htmlspecialchars($s['remarks']) . "</td>";
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
<title>Suppliers Management - Garga Copy Udhyog</title>
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
<h2 class="mb-4">👥 Suppliers Management</h2>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if ($successMsg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<!-- Add New Supplier -->
<div class="card mb-4">
<div class="card-header">Add New Supplier</div>
<div class="card-body">
<form method="post" class="row g-2">
    <div class="col-md-6">
        <input type="text" name="supplier_name" class="form-control" placeholder="Supplier Name *" required>
    </div>
    <div class="col-md-6">
        <input type="text" name="address" class="form-control" placeholder="Address *" required>
    </div>
    
    <div class="col-md-4">
        <input type="text" name="contact_number" class="form-control" placeholder="Contact Number *" required>
    </div>
    <div class="col-md-4">
        <input type="text" name="pan" class="form-control" placeholder="PAN (optional)">
    </div>
    <div class="col-md-4">
        <input type="email" name="email" class="form-control" placeholder="Email (optional)">
    </div>
    
    <div class="col-md-12">
        <input type="text" name="remarks" class="form-control" placeholder="Remarks (optional)">
    </div>

    <div class="col-12">
        <button type="submit" name="add_supplier" class="btn btn-primary">➕ Add Supplier</button>
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

    <button type="button" class="btn btn-info me-2" onclick="printSuppliers()">🖨️ Print</button>

    <?php
    $exportUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?export=xls' . ($search ? '&search=' . urlencode($search) : '');
    ?>
    <a href="<?= $exportUrl ?>" class="btn btn-success">📤 Export</a>
</div>

<!-- Suppliers Table -->
<div class="card mt-3" id="printArea">
<div class="card-body p-0">
<div class="text-center mb-3">
<img src="assets/images/logo.png" class="print-logo" alt="Logo"><br>
<h3>Garga Copy Udhyog</h3>
<h4>Suppliers List</h4>
<p><?= date("d M Y") ?></p>
<hr>
</div>
<table class="table table-striped mb-0" id="suppliersTable">
<thead class="table-dark">
<tr>
<th>Supplier Name</th>
<th>Address</th>
<th>Contact Number</th>
<th>PAN</th>
<th>Email</th>
<th>Remarks</th>
<th>Date Added</th>
<th class="no-print">Action</th>
</tr>
</thead>
<tbody>
<?php if(count($filteredSuppliers) > 0): ?>
    <?php foreach($filteredSuppliers as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['supplier_name']) ?></td>
            <td><?= htmlspecialchars($s['address']) ?></td>
            <td><?= htmlspecialchars($s['contact_number']) ?></td>
            <td><?= htmlspecialchars($s['pan']) ?></td>
            <td><?= htmlspecialchars($s['email']) ?></td>
            <td><?= htmlspecialchars($s['remarks']) ?></td>
            <td><?= !empty($s['date_added']) ? htmlspecialchars($s['date_added']) : (is_numeric($s['id']) ? date("Y-m-d", $s['id']) : '-') ?></td>
            <td class="no-print">
                <a href="?delete=<?= urlencode($s['id']) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this supplier?')">Delete</a>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
<tr><td colspan="8" class="text-center">No suppliers found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<script>
function printSuppliers() {
    window.print();
}
</script>

</div> <!-- container -->
<?php include 'footer.php'; ?>
</body>
</html>
