<?php
// billings.php

$billingsFile = __DIR__ . "/billings.json";
$billings = file_exists($billingsFile) ? json_decode(file_get_contents($billingsFile), true) : [];
if(!is_array($billings)) $billings = [];

$errorMsg = "";
$successMsg = "";
$lastAddedId = null;

// =========================
// Handle Add Billing
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_billing'])){
    $item_name = trim($_POST['item_name'] ?? '');
    $cost = (float)($_POST['cost'] ?? 0);
    $unit = (float)($_POST['unit'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if($item_name===''){
        $errorMsg = "Item Name is required.";
    } else {
        $total = $cost * $unit;
        $newBilling = [
            "id"=>time(),
            "item_name"=>$item_name,
            "cost"=>$cost,
            "unit"=>$unit,
            "total"=>$total,
            "remarks"=>$remarks,
            "date"=>date("Y-m-d H:i:s")
        ];
        $billings[] = $newBilling;
        file_put_contents($billingsFile, json_encode($billings, JSON_PRETTY_PRINT));
        $successMsg = "Billing added successfully.";
        $lastAddedId = $newBilling['id'];
    }
}

// =========================
// Handle Delete Billing
if(isset($_GET['delete'])){
    $deleteId = $_GET['delete'];
    $billings = array_values(array_filter($billings, fn($b)=>$b['id'] != $deleteId));
    file_put_contents($billingsFile, json_encode($billings, JSON_PRETTY_PRINT));
    header("Location: billings.php");
    exit();
}

// =========================
// Handle Search
$search = trim($_GET['search'] ?? '');
$filteredBillings = array_values(array_filter($billings, function($b) use($search){
    if($search==='') return true;
    foreach(['item_name','cost','unit','total','remarks','date'] as $f){
        if(stripos((string)($b[$f]??''), $search)!==false) return true;
    }
    return false;
}));

include 'navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Billings - Garga Copy Udhyog</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { position: absolute; top:0; left:0; width:100%; }
    table { width:100%; border-collapse: collapse; }
    table, th, td { border:1px solid black; }
    th, td { padding:5px; text-align:left; }
    .print-logo { width:100px; margin-bottom:10px; }
}
.print-logo { width:100px; margin-bottom:10px; }
</style>
<script>
function updateTotal(){
    const cost = parseFloat(document.getElementById('cost').value) || 0;
    const unit = parseFloat(document.getElementById('unit').value) || 0;
    document.getElementById('total').value = (cost * unit).toFixed(2);
}

function printBill(id){
    const billingsData = JSON.parse(document.getElementById('billings-data').textContent);
    const b = billingsData.find(x=>x.id==id);
    if(!b) return alert("Billing not found!");

    const printDiv = document.getElementById('printArea');
    printDiv.innerHTML = `
        <div class="text-center mb-3">
            <img src="assets/images/logo.png" class="print-logo" alt="Logo"><br>
            <h3>Garga Copy Udhyog</h3>
            <h4>Billing Slip</h4>
            <hr>
        </div>
        <table class="table table-bordered">
            <tr><th>Item Name</th><td>${b.item_name}</td></tr>
            <tr><th>Cost</th><td>${b.cost}</td></tr>
            <tr><th>Unit</th><td>${b.unit}</td></tr>
            <tr><th>Total</th><td>${b.total}</td></tr>
            <tr><th>Remarks</th><td>${b.remarks}</td></tr>
            <tr><th>Date</th><td>${b.date}</td></tr>
        </table>
    `;
    window.print();
}
</script>
</head>
<body class="bg-light">
<div class="container my-4">
<h2 class="mb-4">🧾 Billing Management</h2>

<?php if($errorMsg): ?>
<div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if($successMsg): ?>
<div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<!-- Add Billing Form -->
<div class="card mb-4">
<div class="card-header">Add Billing</div>
<div class="card-body">
<form method="post">
<div class="row g-2">
<div class="col-md-3"><input type="text" name="item_name" id="item_name" class="form-control" placeholder="Item Name (required)" required></div>
<div class="col-md-2"><input type="number" step="any" name="cost" id="cost" class="form-control" placeholder="Cost" oninput="updateTotal()"></div>
<div class="col-md-2"><input type="number" step="any" name="unit" id="unit" class="form-control" placeholder="Unit" oninput="updateTotal()"></div>
<div class="col-md-2"><input type="number" id="total" class="form-control" placeholder="Total" readonly></div>
<div class="col-md-3"><input type="text" name="remarks" class="form-control" placeholder="Remarks"></div>
<div class="col-12 mt-2"><button type="submit" name="add_billing" class="btn btn-primary">💾 Save Billing</button></div>
</div>
<?php if($lastAddedId): ?>
<div class="mt-2">
    <button class="btn btn-warning" onclick="printBill(<?= $lastAddedId ?>)">🖨 Print Last Billing</button>
</div>
<?php endif; ?>
</form>
</div>
</div>

<!-- Search + Export -->
<div class="d-flex mb-3">
<form method="get" class="flex-grow-1 me-2">
<input type="text" name="search" class="form-control" placeholder="Search keyword" value="<?= htmlspecialchars($search) ?>">
<button type="submit" class="btn btn-secondary ms-2">🔍 Search</button>
</form>
<a href="billings.php?export=xls<?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-success">⬇️ Export XLS</a>
</div>

<!-- Billing Table -->
<div class="card mt-3">
<div class="card-body p-0">
<table class="table table-striped mb-0">
<thead class="table-dark">
<tr>
<th>Item Name</th>
<th>Cost</th>
<th>Unit</th>
<th>Total</th>
<th>Remarks</th>
<th>Date</th>
<th class="no-print">Slip</th>
<th class="no-print">Action</th>
</tr>
</thead>
<tbody>
<?php if(count($filteredBillings)>0): ?>
<?php foreach($filteredBillings as $b): ?>
<tr>
<td><?= htmlspecialchars($b['item_name']) ?></td>
<td><?= htmlspecialchars($b['cost']) ?></td>
<td><?= htmlspecialchars($b['unit']) ?></td>
<td><?= htmlspecialchars($b['total']) ?></td>
<td><?= htmlspecialchars($b['remarks']) ?></td>
<td><?= htmlspecialchars($b['date']) ?></td>
<td class="no-print"><button class="btn btn-warning btn-sm" onclick="printBill(<?= $b['id'] ?>)">🖨 Slip</button></td>
<td class="no-print"><a href="?delete=<?= $b['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this billing?')">Delete</a></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="8" class="text-center">No billing records found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<!-- Hidden div for printing only -->
<div id="printArea" class="no-print"></div>

<script type="application/json" id="billings-data"><?= json_encode($billings) ?></script>
</div>
<?php include 'footer.php'; ?>
</body>
</html>
