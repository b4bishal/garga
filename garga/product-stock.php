<?php
// product-stock.php

// Paths
$productStockFile = __DIR__ . "/product-stock.json";

// Load product stock data
$productStocks = file_exists($productStockFile) ? json_decode(file_get_contents($productStockFile), true) : [];
if (!is_array($productStocks)) $productStocks = [];

// =========================
// Handle Add New Product Stock
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product_stock'])) {
    $totalQuantity = (float)$_POST['total_quantity'];
    $pricePerUnit = (float)$_POST['price_per_unit'];
    $totalValue = $totalQuantity * $pricePerUnit;
    $newProductStock = [
        "id" => time(),
        "copy_category" => $_POST['copy_category'],
        "price_per_unit" => $pricePerUnit,
        "total_quantity" => $totalQuantity,
        "total_value" => $totalValue,
        "remarks" => $_POST['remarks']
    ];
    $productStocks[] = $newProductStock;
    file_put_contents($productStockFile, json_encode($productStocks, JSON_PRETTY_PRINT));
    header("Location: product-stock.php");
    exit();
}

// =========================
// Handle Stock In
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stock_in'])) {
    $itemId = $_POST['item_id'];
    $qty = (float)$_POST['quantity'];
    foreach ($productStocks as &$p) {
        if ($p['id'] == $itemId) {
            $p['total_quantity'] += $qty;
            $p['total_value'] = $p['total_quantity'] * $p['price_per_unit'];
            break;
        }
    }
    file_put_contents($productStockFile, json_encode($productStocks, JSON_PRETTY_PRINT));
    header("Location: product-stock.php");
    exit();
}

// =========================
// Handle Delete
// =========================
if (isset($_GET['delete'])) {
    $delId = (string)$_GET['delete'];
    $productStocks = array_values(array_filter($productStocks, fn($p) => (string)($p['id'] ?? '') !== $delId));
    file_put_contents($productStockFile, json_encode($productStocks, JSON_PRETTY_PRINT));
    header("Location: product-stock.php");
    exit();
}

// =========================
// Handle Search
// =========================
$search = $_GET['search'] ?? '';
$filteredProductStocks = array_filter($productStocks, function($p) use ($search) {
    if ($search === '') return true;
    $fields = [
        $p['copy_category'] ?? '',
        $p['price_per_unit'] ?? '',
        $p['total_quantity'] ?? '',
        $p['total_value'] ?? '',
        $p['remarks'] ?? ''
    ];
    foreach ($fields as $f) {
        if (stripos((string)$f, $search) !== false) return true;
    }
    return false;
});

// =========================
// Handle Export XLS
// =========================
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $filename = "product_stock_" . date("Ymd_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo "Copy Category\tPrice per Unit\tTotal Quantity\tTotal Value\tRemarks\n";
    foreach ($filteredProductStocks as $p) {
        echo $p['copy_category'] . "\t" . $p['price_per_unit'] . "\t" . $p['total_quantity'] . "\t" . $p['total_value'] . "\t" . $p['remarks'] . "\n";
    }
    exit();
}

// Include navbar
include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Product Stock - Garga Copy Udhyog</title>
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
<script>
function calculateTotalValue() {
    const qty = parseFloat(document.getElementById('total_quantity').value) || 0;
    const price = parseFloat(document.getElementById('price_per_unit').value) || 0;
    document.getElementById('total_value').value = (qty * price).toFixed(2);
}

document.addEventListener('DOMContentLoaded', function() {
    const qtyInput = document.getElementById('total_quantity');
    const priceInput = document.getElementById('price_per_unit');
    qtyInput.addEventListener('input', calculateTotalValue);
    priceInput.addEventListener('input', calculateTotalValue);
});

function printProductStock() {
    window.print();
}
</script>
</head>
<body class="bg-light">
<div class="container my-4">
<h2 class="mb-4">📦 Product Stock Management</h2>

<!-- Add New Product Stock -->
<div class="card mb-4">
<div class="card-header">Add New Product Stock</div>
<div class="card-body">
<form method="post">
    <div class="mb-2"><input type="text" name="copy_category" class="form-control" placeholder="Copy Category" required></div>
    <div class="row mb-2">
        <div class="col"><input type="number" name="price_per_unit" id="price_per_unit" class="form-control" placeholder="Price per Unit" required></div>
        <div class="col"><input type="number" name="total_quantity" id="total_quantity" class="form-control" placeholder="Total Quantity" required></div>
        <div class="col"><input type="number" name="total_value" id="total_value" class="form-control" placeholder="Total Value" readonly></div>
    </div>
    <div class="mb-2"><input type="text" name="remarks" class="form-control" placeholder="Remarks (optional)"></div>
    <button type="submit" name="add_product_stock" class="btn btn-primary">➕ Add Product Stock</button>
</form>
</div>
</div>

<!-- Stock In -->
<div class="card mb-4">
<div class="card-header">Stock In</div>
<div class="card-body">
<form method="post">
    <div class="mb-2">
        <select name="item_id" class="form-select" required>
            <option value="">Select Item</option>
            <?php foreach ($productStocks as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['copy_category']) ?> (<?= $p['price_per_unit'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-2"><input type="number" name="quantity" class="form-control" placeholder="Quantity to Add" required></div>
    <button type="submit" name="stock_in" class="btn btn-success">➕ Stock In</button>
</form>
</div>
</div>

<!-- Search + Print + Export -->
<div class="d-flex mb-3">
<form method="get" class="flex-grow-1 me-2">
<input type="text" name="search" class="form-control" placeholder="Search keyword" value="<?= htmlspecialchars($search) ?>">
<button type="submit" class="btn btn-secondary ms-2">🔍 Search</button>
</form>
<button class="btn btn-info me-2" onclick="printProductStock()">🖨 Print</button>
<a href="product-stock.php?export=xls<?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-success me-2">⬇️ Export XLS</a>
</div>

<!-- Product Stock Table -->
<div class="card mt-3" id="printArea">
<div class="card-body p-0">
<div class="text-center mb-3">
<img src="assets/images/logo.png" class="print-logo" alt="Logo"><br>
<h3>Garga Copy Udhyog</h3>
<h4>Product Stock Records</h4>
<p><?= date("d M Y") ?></p>
<hr>
</div>

<table class="table table-striped mb-0">
<thead class="table-dark">
<tr>
<th>Copy Category</th>
<th>Price per Unit</th>
<th>Total Quantity</th>
<th>Total Value</th>
<th>Remarks</th>
<th class="no-print">Action</th>
</tr>
</thead>
<tbody>
<?php if(count($filteredProductStocks) > 0): ?>
<?php foreach($filteredProductStocks as $p): ?>
<tr>
<td><?= htmlspecialchars($p['copy_category']) ?></td>
<td><?= htmlspecialchars($p['price_per_unit']) ?></td>
<td><?= htmlspecialchars($p['total_quantity']) ?></td>
<td><?= htmlspecialchars($p['total_value']) ?></td>
<td><?= htmlspecialchars($p['remarks']) ?></td>
<td class="no-print">
    <a href="product-stock.php?delete=<?= $p['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this record?')">Delete</a>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="6" class="text-center">No product stock records found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

</div>
<?php include 'footer.php'; ?>
</body>
</html>
