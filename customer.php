<?php
// customer.php

$customerFile = __DIR__ . "/customers.json";

// Load customers
$customers = file_exists($customerFile) ? json_decode(file_get_contents($customerFile), true) : [];
if (!is_array($customers)) $customers = [];

// =========================
// Handle Delete Customer
if (isset($_GET['delete'])) {
    $deleteId = $_GET['delete'];
    $customers = array_values(array_filter($customers, fn($c) => $c['id'] != $deleteId));
    file_put_contents($customerFile, json_encode($customers, JSON_PRETTY_PRINT));
    header("Location: customer.php");
    exit();
}

// =========================
// Handle Add Customer
$errorMsg = '';
$successMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $pan = trim($_POST['pan'] ?? ''); // NEW
    $location = trim($_POST['location'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($name === '') {
        $errorMsg = "❌ Name is required!";
    } else {
        $customers[] = [
            "id" => time(),
            "name" => $name,
            "email" => $email,
            "phone" => $phone,
            "pan" => $pan, // NEW
            "location" => $location,
            "remarks" => $remarks,
            "date_added" => date("Y-m-d H:i:s")
        ];
        file_put_contents($customerFile, json_encode($customers, JSON_PRETTY_PRINT));
        $successMsg = "✅ Customer added successfully!";
        header("Location: customer.php");
        exit();
    }
}

// =========================
// Handle Search
$search = trim($_GET['search'] ?? '');
$contains = fn($haystack, $needle) => stripos((string)$haystack, (string)$needle) !== false;

$matches_search = function($c, $searchTerm) use ($contains) {
    if ($searchTerm === '') return true;
    foreach ($c as $k => $v) {
        if (is_array($v) || is_object($v)) continue;
        if ($contains($v, $searchTerm)) return true;
    }
    return false;
};

$filteredCustomers = array_values(array_filter($customers, fn($c) => $matches_search($c, $search)));

// =========================
// Handle Export XLS
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $filename = "customers_" . date("Ymd_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    // Added PAN column
    echo "Name\tEmail\tPhone\tPAN\tLocation\tRemarks\tDate Added\n";
    foreach ($filteredCustomers as $c) {
        echo ($c['name'] ?? '') . "\t"
           . ($c['email'] ?? '') . "\t"
           . ($c['phone'] ?? '') . "\t"
           . ($c['pan'] ?? '') . "\t"
           . ($c['location'] ?? '') . "\t"
           . ($c['remarks'] ?? '') . "\t"
           . ($c['date_added'] ?? '') . "\n";
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
<title>Customer Management - Garga Copy Udhyog</title>
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
.highlight { background-color: yellow; }
</style>
<script>
// Highlight search terms in table
function highlightSearch() {
    const search = "<?= addslashes($search) ?>";
    if (!search) return;
    const rows = document.querySelectorAll("#customerTable tbody tr");
    rows.forEach(row => {
        row.querySelectorAll("td").forEach(td => {
            const regex = new RegExp("(" + search.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ")", "gi");
            td.innerHTML = td.textContent.replace(regex, '<span class="highlight">$1</span>');
        });
    });
}
window.addEventListener('DOMContentLoaded', highlightSearch);
</script>
</head>
<body class="bg-light">
<div class="container my-4">
<h2 class="mb-4">👥 Customer Management</h2>

<?php if($errorMsg): ?>
<div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if($successMsg): ?>
<div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<!-- Add Customer Form -->
<div class="card mb-4">
<div class="card-header">Add New Customer</div>
<div class="card-body">
<form method="post" class="row g-2">
    <div class="col-md-4"><input type="text" name="name" class="form-control" placeholder="Name *" required></div>
    <div class="col-md-4"><input type="email" name="email" class="form-control" placeholder="Email"></div>
    <div class="col-md-4"><input type="text" name="phone" class="form-control" placeholder="Phone Number"></div>

    <!-- NEW: PAN with verify button -->
    <div class="col-md-6">
        <div class="input-group">
            <input type="text" name="pan" class="form-control" placeholder="PAN No. (optional)">
            <a class="btn btn-outline-primary" href="https://ird.gov.np/pan-search/" target="_blank" rel="noopener">
                Verify PAN
            </a>
        </div>
    </div>

    <div class="col-md-6"><input type="text" name="location" class="form-control" placeholder="Location"></div>
    <div class="col-md-12"><input type="text" name="remarks" class="form-control" placeholder="Remarks"></div>

    <div class="col-12"><button type="submit" name="add_customer" class="btn btn-primary">➕ Add Customer</button></div>
</form>
</div>
</div>

<!-- Search + Print + Export -->
<div class="d-flex mb-3">
<form method="get" class="flex-grow-1 me-2">
<input type="text" name="search" class="form-control" placeholder="Search anything in table" value="<?= htmlspecialchars($search) ?>">
<button type="submit" class="btn btn-secondary ms-2">🔍 Search</button>
</form>
<button class="btn btn-info me-2" onclick="window.print()">🖨 Print</button>
<a href="customer.php?export=xls<?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-success">⬇️ Export XLS</a>
</div>

<!-- Customers Table -->
<div class="card mt-3" id="printArea">
<div class="card-body p-0">
<div class="text-center mb-3">
<img src="assets/images/logo.png" class="print-logo" alt="Logo"><br>
<h3>Garga Copy Udhyog</h3>
<h4>Customer Records</h4>
<p><?= date("d M Y") ?></p>
<hr>
</div>

<table class="table table-striped mb-0" id="customerTable">
<thead class="table-dark">
<tr>
<th>Name</th>
<th>Email</th>
<th>Phone</th>
<th>PAN</th>
<th>Location</th>
<th>Remarks</th>
<th>Date Added</th>
<th class="no-print">Action</th>
</tr>
</thead>
<tbody>
<?php if(count($filteredCustomers) > 0): ?>
<?php foreach($filteredCustomers as $c): ?>
<tr>
<td><?= htmlspecialchars($c['name'] ?? '') ?></td>
<td><?= htmlspecialchars($c['email'] ?? '') ?></td>
<td><?= htmlspecialchars($c['phone'] ?? '') ?></td>
<td><?= htmlspecialchars($c['pan'] ?? '') ?></td>
<td><?= htmlspecialchars($c['location'] ?? '') ?></td>
<td><?= htmlspecialchars($c['remarks'] ?? '') ?></td>
<td><?= htmlspecialchars($c['date_added'] ?? '') ?></td>
<td class="no-print">
    <a href="customer.php?delete=<?= urlencode($c['id']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this customer?')">Delete</a>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="8" class="text-center">No customers found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

</div>
<?php include 'footer.php'; ?>
</body>
</html>
