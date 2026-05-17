<?php
// stock-out.php

// Paths
$stockOutFile = __DIR__ . "/stock-out.json";
$stockFile = __DIR__ . "/stock.json"; // NEW: Load stock.json

// Load stock-out data
$stockOut = file_exists($stockOutFile) ? json_decode(file_get_contents($stockOutFile), true) : [];
if (!is_array($stockOut)) $stockOut = [];

// NEW: Load stock data
$stocks = file_exists($stockFile) ? json_decode(file_get_contents($stockFile), true) : [];
if (!is_array($stocks)) $stocks = [];

// =========================
// Handle Delete Stock-Out Item
// =========================
if (isset($_GET['delete'])) {
    $delId = (string)($_GET['delete']);
    
    // NEW: Find the stock-out record to get details before deleting
    $deletedRecord = null;
    foreach($stockOut as $so) {
        if((string)($so['id'] ?? '') === $delId) {
            $deletedRecord = $so;
            break;
        }
    }
    
    // NEW: If found, restore quantity to stock.json
    if($deletedRecord !== null) {
        $productName = $deletedRecord['product_name'] ?? '';
        $sellerName = $deletedRecord['seller_name'] ?? '';
        $quantityToRestore = (float)($deletedRecord['quantity'] ?? 0);
        
        // Find matching stock item and restore quantity
        $stockFound = false;
        foreach($stocks as &$stock) {
            if(($stock['product_name'] ?? '') === $productName && 
               ($stock['seller_name'] ?? '') === $sellerName) {
                $stock['quantity'] = (float)($stock['quantity'] ?? 0) + $quantityToRestore;
                $stock['total_price'] = $stock['quantity'] * (float)($stock['unit_price'] ?? 0);
                $stockFound = true;
                break;
            }
        }
        unset($stock);
        
        // Save updated stock.json
        if($stockFound) {
            file_put_contents($stockFile, json_encode($stocks, JSON_PRETTY_PRINT));
        }
    }
    
    // Remove from stock-out.json
    $stockOut = array_values(array_filter($stockOut, fn($s) => (string)($s['id'] ?? '') !== $delId));
    file_put_contents($stockOutFile, json_encode($stockOut, JSON_PRETTY_PRINT));
    
    // preserve search param when redirecting
    $redirect = 'stock-out.php' . (isset($_GET['search']) ? ('?search=' . urlencode($_GET['search'])) : '');
    header("Location: $redirect");
    exit();
}

// =========================
// Handle Search
// =========================
$search = trim($_GET['search'] ?? '');

// helper: case-insensitive contains
$contains = function($haystack, $needle) {
    if ($needle === '') return false;
    return (stripos((string)$haystack, (string)$needle) !== false);
};

// more robust date format checking / normalizing
$format_possible_dates = function($rawDate) {
    $out = [];
    if (empty($rawDate)) return $out;
    // try DateTime generic parse
    try {
        $dt = new DateTime($rawDate);
        $out[] = $dt->format('Y-m-d');           // 2025-08-09
        $out[] = $dt->format('d-m-Y');           // 09-08-2025
        $out[] = $dt->format('Y-m-d H:i:s');     // 2025-08-09 12:00:00
        $out[] = $dt->format('d-m-Y H:i:s');     // 09-08-2025 12:00:00
    } catch (Exception $e) {
        // if parsing fails, still return raw
        $out[] = (string)$rawDate;
    }
    return array_unique($out);
};

$matches_search = function($s, $term) use ($contains, $format_possible_dates) {
    if ($term === '') return true;
    $term = (string)$term;

    // Check common scalar fields
    $fields = [
        $s['product_name'] ?? '',
        $s['seller_name'] ?? '',
        $s['quantity'] ?? '',
        $s['unit_price'] ?? '',
        $s['total_price'] ?? '',
        $s['remarks'] ?? '',
        $s['date'] ?? '',
        $s['id'] ?? ''
    ];
    foreach ($fields as $v) {
        if ($v === null) continue;
        if ($contains($v, $term)) return true;
    }

    // Try parsing & formatting date field (if present)
    if (!empty($s['date'])) {
        $formats = $format_possible_dates($s['date']);
        foreach ($formats as $fv) {
            if ($contains($fv, $term)) return true;
        }
    }

    // If id is numeric timestamp, also check formatted time
    if (!empty($s['id']) && is_numeric($s['id'])) {
        $formatted1 = date("Y-m-d", $s['id']);
        $formatted2 = date("d-m-Y H:i", $s['id']);
        if ($contains($formatted1, $term) || $contains($formatted2, $term)) return true;
    }

    return false;
};

// Filtered list to show
$filteredStockOut = array_values(array_filter($stockOut, fn($s) => $matches_search($s, $search)));

// =========================
// Handle XLS Export (only XLS, CSV removed)
// =========================
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $filename = "stock_out_" . date("Ymd_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    // BOM for Excel
    echo "\xEF\xBB\xBF";

    // Output an HTML table (Excel friendly)
    echo "<table border='1'><thead>";
    echo "<tr>";
    echo "<th>Product Name</th>";
    echo "<th>Seller Name</th>";
    echo "<th>Quantity</th>";
    echo "<th>Unit Price</th>";
    echo "<th>Total Price</th>";
    echo "<th>Remarks</th>";
    echo "<th>Date</th>";
    echo "</tr></thead><tbody>";

    foreach ($filteredStockOut as $s) {
        // Format date as YYYY-MM-DD if possible
        $dateCell = '';
        if (!empty($s['date'])) {
            try {
                $dt = new DateTime($s['date']);
                $dateCell = $dt->format('Y-m-d');
            } catch (Exception $e) {
                $dateCell = htmlspecialchars($s['date']);
            }
        }
        echo "<tr>";
        echo "<td>" . htmlspecialchars($s['product_name'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($s['seller_name'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($s['quantity'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($s['unit_price'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($s['total_price'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($s['remarks'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($dateCell) . "</td>";
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
<title>Stock Out - Garga Copy Udhyog</title>
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
<h2 class="mb-4">📤 Stock Out Records</h2>

<!-- Search + Print + Export -->
<div class="d-flex mb-3">
    <form method="get" class="d-flex flex-grow-1">
        <input type="text" name="search" class="form-control me-2" placeholder="Search anything in table" value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-secondary me-2">🔍 Search</button>
    </form>

    <button type="button" class="btn btn-info me-2 no-print" onclick="printStockOut()">🖨️ Print</button>

    <?php
    $base = htmlspecialchars($_SERVER['PHP_SELF']);
    $xlsUrl = $base . '?export=xls' . ($search ? '&search=' . urlencode($search) : '');
    ?>
    <a href="<?= $xlsUrl ?>" class="btn btn-success no-print">📤 Export XLS</a>
</div>

<!-- Stock Out Table -->
<div class="card mt-3" id="printArea">
<div class="card-body p-0">
<div class="text-center mb-3">
    <img src="assets/images/logo.png" class="print-logo" alt="Logo"><br>
    <h3>Garga Copy Udhyog</h3>
    <h4>Stock Out Records</h4>
    <p><?= date("d M Y") ?></p>
    <hr>
</div>

<table class="table table-striped mb-0" id="stockOutTable">
<thead class="table-dark">
<tr>
<th>Product Name</th>
<th>Seller Name</th>
<th>Quantity</th>
<th>Unit Price</th>
<th>Total Price</th>
<th>Remarks</th>
<th>Date</th>
<th class="no-print">Action</th>
</tr>
</thead>
<tbody>
<?php if (count($filteredStockOut) > 0): ?>
    <?php foreach ($filteredStockOut as $s): ?>
        <?php
            // format date to YYYY-MM-DD if possible, otherwise show raw
            $dateShown = '';
            if (!empty($s['date'])) {
                try {
                    $dt = new DateTime($s['date']);
                    $dateShown = $dt->format('Y-m-d');
                } catch (Exception $e) {
                    $dateShown = $s['date'];
                }
            }
        ?>
        <tr>
            <td><?= htmlspecialchars($s['product_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($s['seller_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($s['quantity'] ?? '') ?></td>
            <td><?= htmlspecialchars($s['unit_price'] ?? '') ?></td>
            <td><?= htmlspecialchars($s['total_price'] ?? '') ?></td>
            <td><?= htmlspecialchars($s['remarks'] ?? '') ?></td>
            <td><?= htmlspecialchars($dateShown) ?></td>
            <td class="no-print">
                <a href="?delete=<?= urlencode($s['id'] ?? '') ?><?= $search ? '&search=' . urlencode($search) : '' ?>" 
                   class="btn btn-sm btn-danger" 
                   onclick="return confirm('Delete this stock-out record? This will restore <?= htmlspecialchars($s['quantity'] ?? '') ?> units back to stock.')">
                   Delete
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr><td colspan="8" class="text-center">No stock-out records found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

</div> <!-- container -->

<script>
function printStockOut() {
    window.print();
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
