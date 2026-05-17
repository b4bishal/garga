<?php
// stock.php

// Paths
$stockFile = __DIR__ . "/stock.json";
$stockOutFile = __DIR__ . "/stock-out.json";
$stockNameFile = __DIR__ . "/stock-name.json";
$suppliersFile = __DIR__ . "/suppliers.json";
$stockExpensesFile = __DIR__ . "/stock-expenses.json"; // NEW

// Load stocks
$stocks = file_exists($stockFile) ? json_decode(file_get_contents($stockFile), true) : [];
if (!is_array($stocks)) $stocks = [];

// Ensure stock-out file exists/load
$stockOut = file_exists($stockOutFile) ? json_decode(file_get_contents($stockOutFile), true) : [];
if (!is_array($stockOut)) $stockOut = [];

// Load stock names
$stockNames = file_exists($stockNameFile) ? json_decode(file_get_contents($stockNameFile), true) : [];
if (!is_array($stockNames)) $stockNames = [];

// Load suppliers
$suppliers = file_exists($suppliersFile) ? json_decode(file_get_contents($suppliersFile), true) : [];
if (!is_array($suppliers)) $suppliers = [];

// Load stock expenses - NEW
$stockExpenses = file_exists($stockExpensesFile) ? json_decode(file_get_contents($stockExpensesFile), true) : [];
if (!is_array($stockExpenses)) $stockExpenses = [];

// Messages for UI
$errorMsg = "";
$successMsg = "";

/**
 * Safe helper to write stocks and optional stock-out array
 */
function save_stocks($stockFile, $stocks, $stockOutFile = null, $stockOut = null, $stockExpensesFile = null, $stockExpenses = null) {
    file_put_contents($stockFile, json_encode($stocks, JSON_PRETTY_PRINT));
    if ($stockOutFile !== null && $stockOut !== null) {
        file_put_contents($stockOutFile, json_encode($stockOut, JSON_PRETTY_PRINT));
    }
    if ($stockExpensesFile !== null && $stockExpenses !== null) {
        file_put_contents($stockExpensesFile, json_encode($stockExpenses, JSON_PRETTY_PRINT));
    }
}

// =========================
// Handle Add New Stock
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock'])) {
    $product_name = trim($_POST['product_name'] ?? '');
    $seller_name  = trim($_POST['seller_name'] ?? '');
    $quantity     = (float)($_POST['quantity'] ?? 0);
    $unit_price   = (float)($_POST['unit_price'] ?? 0);
    // prefer provided total_price if non-empty, else compute
    $total_price  = (isset($_POST['total_price']) && trim($_POST['total_price']) !== '') ? (float)$_POST['total_price'] : ($quantity * $unit_price);
    $remarks      = trim($_POST['remarks'] ?? '');

    $stockId = time();
    $dateAdded = date("Y-m-d");

    $newStock = [
        "id" => $stockId,
        "product_name" => $product_name,
        "seller_name" => $seller_name,
        "quantity" => $quantity,
        "unit_price" => $unit_price,
        "total_price" => $total_price,
        "remarks" => $remarks,
        "date_added" => $dateAdded
    ];

    // NEW: Also save to stock-expenses.json
    $newExpense = [
        "id" => $stockId,
        "product_name" => $product_name,
        "seller_name" => $seller_name,
        "quantity" => $quantity,
        "unit_price" => $unit_price,
        "total_bill" => $total_price,
        "remarks" => $remarks,
        "date" => $dateAdded
    ];

    $stocks[] = $newStock;
    $stockExpenses[] = $newExpense;
    
    save_stocks($stockFile, $stocks, null, null, $stockExpensesFile, $stockExpenses);
    
    // Redirect to avoid form resubmission and show fresh page
    header("Location: stock.php");
    exit();
}

// =========================
// Handle Stock In / Stock Out
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // STOCK IN
    if (isset($_POST['stock_in'])) {
        $itemId = $_POST['item_id'] ?? '';
        $qty = (float)($_POST['quantity'] ?? 0);
        
        foreach ($stocks as &$s) {
            if ((string)$s['id'] === (string)$itemId) {
                $oldQuantity = (float)$s['quantity'];
                $s['quantity'] = $oldQuantity + $qty;
                $s['total_price'] = $s['quantity'] * (float)$s['unit_price'];
                
                // NEW: Add additional stock purchase expense
                $additionalExpense = [
                    "id" => time(),
                    "product_name" => $s['product_name'],
                    "seller_name" => $s['seller_name'],
                    "quantity" => $qty,
                    "unit_price" => $s['unit_price'],
                    "total_bill" => $qty * (float)$s['unit_price'],
                    "remarks" => "Stock In (Additional Purchase)",
                    "date" => date("Y-m-d")
                ];
                $stockExpenses[] = $additionalExpense;
                
                break;
            }
        }
        unset($s);
        
        save_stocks($stockFile, $stocks, null, null, $stockExpensesFile, $stockExpenses);
        header("Location: stock.php");
        exit();
    }

    // STOCK OUT
    if (isset($_POST['stock_out'])) {
        $itemId = $_POST['item_id'] ?? '';
        $qty = (float)($_POST['quantity'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        $found = false;
        foreach ($stocks as $key => &$s) {
            if ((string)$s['id'] === (string)$itemId) {
                $found = true;
                // If requested qty exceeds available, set error and do NOT change
                if ($qty > (float)$s['quantity']) {
                    $errorMsg = "Stock Out error: requested quantity ({$qty}) is greater than available ({$s['quantity']}).";
                } else {
                    // perform stock out
                    $s['quantity'] = (float)$s['quantity'] - $qty;
                    $s['total_price'] = $s['quantity'] * (float)$s['unit_price'];

                    // append to stock-out log
                    $stockOut[] = [
                        "id" => time(),
                        "product_name" => $s['product_name'],
                        "seller_name" => $s['seller_name'],
                        "quantity" => $qty,
                        "unit_price" => $s['unit_price'],
                        "total_price" => $qty * $s['unit_price'],
                        "remarks" => $notes,
                        "date" => date("Y-m-d H:i:s")
                    ];

                    // save both files and redirect
                    save_stocks($stockFile, $stocks, $stockOutFile, $stockOut);
                    header("Location: stock.php");
                    exit();
                }
                break;
            }
        }
        unset($s);
        if (!$found && !$errorMsg) {
            $errorMsg = "Stock Out error: selected item not found.";
        }
    }
}

// =========================
// Handle Delete Stock Item
// =========================
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // NEW: Also remove from stock-expenses.json
    $stockExpenses = array_values(array_filter($stockExpenses, fn($e) => (string)$e['id'] !== (string)$id));
    
    // Remove item from stock
    $stocks = array_values(array_filter($stocks, fn($s) => (string)$s['id'] !== (string)$id));
    
    save_stocks($stockFile, $stocks, null, null, $stockExpensesFile, $stockExpenses);
    header("Location: stock.php");
    exit();
}

// =========================
// Handle Search (keyword across all visible table fields)
// =========================
$search = trim($_GET['search'] ?? '');

// helper: case-insensitive contains
$contains = function($haystack, $needle) {
    if ($needle === '') return false;
    return (stripos((string)$haystack, (string)$needle) !== false);
};

$matches_search = function($s, $searchTerm) use ($contains) {
    if ($searchTerm === '') return true;
    // check each scalar property
    foreach ($s as $k => $v) {
        if ($v === null) continue;
        if (is_array($v) || is_object($v)) continue;
        if ($contains($v, $searchTerm)) return true;
    }

    // check date_added explicitly (YYYY-MM-DD)
    if (!empty($s['date_added']) && $contains($s['date_added'], $searchTerm)) return true;

    // check common alternate date formats:
    if (!empty($s['date_added'])) {
        $dt = DateTime::createFromFormat('Y-m-d', $s['date_added']);
        if ($dt) {
            if ($contains($dt->format('d-m-Y'), $searchTerm)) return true;
        }
    }

    // if id is a timestamp, check formatted timestamp too
    if (!empty($s['id']) && is_numeric($s['id'])) {
        $formatted1 = date("d-m-Y H:i", $s['id']);
        $formatted2 = date("Y-m-d", $s['id']);
        if ($contains($formatted1, $searchTerm)) return true;
        if ($contains($formatted2, $searchTerm)) return true;
    }

    return false;
};

// Build filteredStocks (hide zero-quantity items from display)
$filteredStocks = array_values(array_filter($stocks, function($s) use ($matches_search, $search) {
    if (!$matches_search($s, $search)) return false;
    return ((float)$s['quantity']) > 0;
}));

// For selects (Stock In / Stock Out), show items (we'll show all items for Stock In and only available for Stock Out)
$allStocksForSelect = array_values($stocks);
$availableStocks = array_values(array_filter($stocks, fn($s) => ((float)$s['quantity']) > 0));

// =========================
// Handle Export to XLS (server-side) - excludes Delete/Action column
// =========================
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    // filename with timestamp
    $filename = "stock_in_hand_" . date("Ymd_His") . ".xls";
    // headers for excel
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    // BOM for utf-8
    echo "\xEF\xBB\xBF";

    // Output a simple HTML table (Excel will open it)
    echo "<table border='1'><thead>";
    echo "<tr>";
    echo "<th>Product Name</th>";
    echo "<th>Seller Name</th>";
    echo "<th>Quantity</th>";
    echo "<th>Unit Price</th>";
    echo "<th>Total Price</th>";
    echo "<th>Remarks</th>";
    echo "<th>Date Added</th>";
    echo "</tr>";
    echo "</thead><tbody>";

    foreach ($filteredStocks as $s) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($s['product_name']) . "</td>";
        echo "<td>" . htmlspecialchars($s['seller_name']) . "</td>";
        echo "<td>" . htmlspecialchars($s['quantity']) . "</td>";
        echo "<td>" . htmlspecialchars($s['unit_price']) . "</td>";
        echo "<td>" . htmlspecialchars($s['total_price']) . "</td>";
        echo "<td>" . htmlspecialchars($s['remarks']) . "</td>";
        echo "<td>" . (!empty($s['date_added']) ? htmlspecialchars($s['date_added']) : (is_numeric($s['id']) ? date("Y-m-d", $s['id']) : '-')) . "</td>";
        echo "</tr>";
    }

    echo "</tbody></table>";
    exit();
}

// Include navbar & continue to HTML output
include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Stock Management - Garga Copy Udhyog</title>
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
<h2 class="mb-4">📦 Stock Management</h2>

<!-- Flash messages -->
<?php if ($errorMsg): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if ($successMsg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<!-- Add New Stock -->
<div class="card mb-4">
<div class="card-header">Add New Stock Item</div>
<div class="card-body">
<form method="post" id="addStockForm" class="row g-2">
    <div class="col-md-4">
        <select name="product_name" class="form-select" required>
            <option value="">Select Product Name</option>
            <?php foreach ($stockNames as $sn): ?>
                <option value="<?= htmlspecialchars($sn['stock_name']) ?>">
                    <?= htmlspecialchars($sn['stock_name']) ?> - <?= htmlspecialchars($sn['size']) ?> (<?= htmlspecialchars($sn['quality']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <select name="seller_name" class="form-select" required>
            <option value="">Select Seller Name</option>
            <?php foreach ($suppliers as $sup): ?>
                <option value="<?= htmlspecialchars($sup['supplier_name']) ?>">
                    <?= htmlspecialchars($sup['supplier_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4"></div>

    <div class="col-md-3"><input type="number" step="any" name="quantity" id="quantity" class="form-control" placeholder="Quantity" required></div>
    <div class="col-md-3"><input type="number" step="any" name="unit_price" id="unit_price" class="form-control" placeholder="Unit Price" required></div>
    <div class="col-md-3"><input type="number" step="any" name="total_price" id="total_price" class="form-control" placeholder="Total Price (optional)"></div>
    <div class="col-md-3"><input type="text" name="remarks" class="form-control" placeholder="Remarks (optional)"></div>

    <div class="col-12">
        <button type="submit" name="add_stock" class="btn btn-primary">➕ Add Stock</button>
    </div>
</form>
</div>
</div>

<!-- Stock In / Stock Out -->
<div class="row mb-4">
<div class="col-md-6">
<div class="card">
<div class="card-header">Stock In</div>
<div class="card-body">
<form method="post">
    <div class="mb-2">
        <select name="item_id" class="form-select" required>
            <option value="">Select Item</option>
            <?php foreach ($allStocksForSelect as $s): ?>
                <option value="<?= htmlspecialchars($s['id']) ?>"><?= htmlspecialchars($s['product_name']) ?> - <?= htmlspecialchars($s['seller_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-2"><input type="number" step="any" name="quantity" class="form-control" placeholder="Quantity to Add" required></div>
    <button type="submit" name="stock_in" class="btn btn-success">➕ Stock In</button>
</form>
</div>
</div>
</div>

<div class="col-md-6">
<div class="card">
<div class="card-header">Stock Out</div>
<div class="card-body">
<form method="post" class="mb-2">
    <div class="mb-2">
        <select name="item_id" class="form-select" required>
            <option value="">Select Item</option>
            <?php foreach ($availableStocks as $s): ?>
                <option value="<?= htmlspecialchars($s['id']) ?>"><?= htmlspecialchars($s['product_name']) ?> - <?= htmlspecialchars($s['seller_name']) ?> (Available: <?= htmlspecialchars($s['quantity']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-2"><input type="number" step="any" name="quantity" class="form-control" placeholder="Quantity to Remove" required></div>
    <div class="mb-2"><input type="text" name="notes" class="form-control" placeholder="Notes (optional)"></div>
    <button type="submit" name="stock_out" class="btn btn-danger">➖ Stock Out</button>
</form>
<a href="stock-out.php" class="btn btn-warning w-100">Go to Stock Out Records</a>
</div>
</div>
</div>
</div>

<!-- Search + Print + Export -->
<div class="d-flex mb-3">
    <form method="get" class="d-flex flex-grow-1">
        <input type="text" name="search" class="form-control me-2" placeholder="Search anything in table" value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-secondary me-2">🔍 Search</button>
    </form>

    <button type="button" class="btn btn-info me-2" onclick="printStockIn()">🖨️ Print</button>

    <?php
    // Build export URL that preserves search
    $exportUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?export=xls' . ($search ? '&search=' . urlencode($search) : '');
    ?>
    <a href="<?= $exportUrl ?>" class="btn btn-success">📤 Export</a>
</div>

<!-- Stock Table -->
<div class="card mt-3" id="printArea">
<div class="card-body p-0">
<div class="text-center mb-3">
<img src="assets/images/logo.png" class="print-logo" alt="Logo"><br>
<h3>Garga Copy Udhyog</h3>
<h4>Stock In Hand</h4>
<p><?= date("d M Y") ?></p>
<hr>
</div>
<table class="table table-striped mb-0" id="stockTable">
<thead class="table-dark">
<tr>
<th>Product Name</th>
<th>Seller Name</th>
<th>Quantity</th>
<th>Unit Price</th>
<th>Total Price</th>
<th>Remarks</th>
<th>Date Added</th>
<th class="no-print">Action</th>
</tr>
</thead>
<tbody>
<?php if(count($filteredStocks) > 0): ?>
    <?php foreach($filteredStocks as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['product_name']) ?></td>
            <td><?= htmlspecialchars($s['seller_name']) ?></td>
            <td><?= htmlspecialchars($s['quantity']) ?></td>
            <td><?= htmlspecialchars($s['unit_price']) ?></td>
            <td><?= htmlspecialchars($s['total_price']) ?></td>
            <td><?= htmlspecialchars($s['remarks']) ?></td>
            <td><?= !empty($s['date_added']) ? htmlspecialchars($s['date_added']) : (is_numeric($s['id']) ? date("Y-m-d", $s['id']) : '-') ?></td>
            <td class="no-print">
                <a href="?delete=<?= urlencode($s['id']) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this stock?')">Delete</a>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
<tr><td colspan="8" class="text-center">No stock items found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<script>
// calculate total price (client-side) when adding new stock
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unit = parseFloat(document.getElementById('unit_price').value) || 0;
    document.getElementById('total_price').value = (quantity * unit).toFixed(2);
}
const qEl = document.getElementById('quantity');
const uEl = document.getElementById('unit_price');
const tEl = document.getElementById('total_price');
if (qEl) qEl.addEventListener('input', calculateTotal);
if (uEl) uEl.addEventListener('input', calculateTotal);
if (tEl) tEl.addEventListener('input', function(){
    const total = parseFloat(this.value) || 0;
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    if (quantity) {
        document.getElementById('unit_price').value = (total / quantity).toFixed(2);
    }
});

function printStockIn() {
    window.print();
}
</script>

</div> <!-- container -->
<?php include 'footer.php'; ?>
</body>
</html>
