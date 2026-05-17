<?php
// salaries.php
$salariesFile = __DIR__ . "/salaries.json";
$salaries = file_exists($salariesFile) ? json_decode(file_get_contents($salariesFile), true) : [];
if(!is_array($salaries)) $salaries = [];

$errorMsg = "";
$successMsg = "";
$lastAddedId = null;

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_salary'])){
    $name = trim($_POST['name'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $issued_by = trim($_POST['issued_by'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if($name===''){
        $errorMsg = "Name is required.";
    } else {
        $newSalary = [
            "id"=>time(),
            "name"=>$name,
            "amount"=>$amount,
            "issued_by"=>$issued_by,
            "remarks"=>$remarks,
            "date"=>date("Y-m-d H:i:s")
        ];
        $salaries[] = $newSalary;
        file_put_contents($salariesFile, json_encode($salaries, JSON_PRETTY_PRINT));
        $successMsg = "Salary added successfully.";
        $lastAddedId = $newSalary['id'];
    }
}

if(isset($_GET['delete'])){
    $deleteId = $_GET['delete'];
    $salaries = array_values(array_filter($salaries, fn($s)=>$s['id'] != $deleteId));
    file_put_contents($salariesFile, json_encode($salaries, JSON_PRETTY_PRINT));
    header("Location: salaries.php");
    exit();
}

$search = trim($_GET['search'] ?? '');
$filteredSalaries = array_values(array_filter($salaries, function($s) use($search){
    if($search==='') return true;
    foreach(['name','amount','issued_by','remarks','date'] as $f){
        if(stripos((string)($s[$f]??''), $search)!==false) return true;
    }
    return false;
}));

include 'navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Salaries - Garga Copy Udhyog</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
/* Only print #printArea */
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { position: absolute; top:0; left:0; width:100%; }
    table { width:100%; border-collapse: collapse; }
    table, th, td { border:1px solid black; }
    th, td { padding:5px; text-align:left; }
    .print-logo { width:100px; margin-bottom:10px; }
    .signature { margin-top:50px; text-align:right; }
}
.print-logo { width:100px; margin-bottom:10px; }
</style>
<script>
function printSlip(id){
    const salariesData = JSON.parse(document.getElementById('salaries-data').textContent);
    const s = salariesData.find(x=>x.id==id);
    if(!s) return alert("Salary not found!");

    // Populate printArea only
    const printDiv = document.getElementById('printArea');
    printDiv.innerHTML = `
        <div class="text-center mb-3">
            <img src="assets/images/logo.png" class="print-logo" alt="Logo"><br>
            <h3>Garga Copy Udhyog</h3>
            <h4>Salary Slip</h4>
            <hr>
        </div>
        <table class="table table-bordered">
            <tr><th>Name</th><td>${s.name}</td></tr>
            <tr><th>Amount</th><td>${s.amount}</td></tr>
            <tr><th>Issued By</th><td>${s.issued_by}</td></tr>
            <tr><th>Remarks</th><td>${s.remarks}</td></tr>
            <tr><th>Date</th><td>${s.date}</td></tr>
        </table>
        <div class="signature">Signature: ____________________</div>
    `;
    // Trigger print immediately
    window.print();
}
</script>
</head>
<body class="bg-light">
<div class="container my-4">
<h2 class="mb-4">💰 Salaries Management</h2>

<?php if($errorMsg): ?>
<div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if($successMsg): ?>
<div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<!-- Add Salary Form -->
<div class="card mb-4">
<div class="card-header">Add Salary</div>
<div class="card-body">
<form method="post">
<div class="row g-2">
<div class="col-md-3"><input type="text" name="name" class="form-control" placeholder="Name (required)" required></div>
<div class="col-md-3"><input type="number" step="any" name="amount" class="form-control" placeholder="Amount"></div>
<div class="col-md-3"><input type="text" name="issued_by" class="form-control" placeholder="Issued By"></div>
<div class="col-md-3"><input type="text" name="remarks" class="form-control" placeholder="Remarks"></div>
<div class="col-12 mt-2"><button type="submit" name="add_salary" class="btn btn-primary">💾 Save Salary</button></div>
</div>
</form>

<?php if($lastAddedId): ?>
<div class="mt-2">
    <button class="btn btn-warning" onclick="printSlip(<?= $lastAddedId ?>)">🖨 Print Last Salary Slip</button>
</div>
<?php endif; ?>
</div>
</div>

<!-- Search -->
<div class="d-flex mb-3">
<form method="get" class="flex-grow-1 me-2">
<input type="text" name="search" class="form-control" placeholder="Search keyword" value="<?= htmlspecialchars($search) ?>">
<button type="submit" class="btn btn-secondary ms-2">🔍 Search</button>
</form>
</div>

<!-- Salaries Table -->
<div class="card mt-3">
<div class="card-body p-0">
<table class="table table-striped mb-0">
<thead class="table-dark">
<tr>
<th>Name</th>
<th>Amount</th>
<th>Issued By</th>
<th>Remarks</th>
<th>Date</th>
<th class="no-print">Slip</th>
<th class="no-print">Action</th>
</tr>
</thead>
<tbody>
<?php if(count($filteredSalaries)>0): ?>
<?php foreach($filteredSalaries as $s): ?>
<tr>
<td><?= htmlspecialchars($s['name']) ?></td>
<td><?= htmlspecialchars($s['amount']) ?></td>
<td><?= htmlspecialchars($s['issued_by']) ?></td>
<td><?= htmlspecialchars($s['remarks']) ?></td>
<td><?= htmlspecialchars($s['date']) ?></td>
<td class="no-print"><button class="btn btn-warning btn-sm" onclick="printSlip(<?= $s['id'] ?>)">🖨 Slip</button></td>
<td class="no-print"><a href="?delete=<?= $s['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this salary?')">Delete</a></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" class="text-center">No salary records found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<!-- Hidden div for printing only -->
<div id="printArea" class="no-print"></div>

<script type="application/json" id="salaries-data"><?= json_encode($salaries) ?></script>
</div>
<?php include 'footer.php'; ?>
</body>
</html>
