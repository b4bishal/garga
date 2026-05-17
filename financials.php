<?php
// financial.php

// Load all data files
$salesFile = __DIR__ . "/sales.json";
$stockExpensesFile = __DIR__ . "/stock-expenses.json";
$salariesFile = __DIR__ . "/salaries.json";
$billingsFile = __DIR__ . "/billings.json";
$creditFile = __DIR__ . "/credit.json";
$stockFile = __DIR__ . "/stock.json";
$productStockFile = __DIR__ . "/product-stock.json";

$sales = file_exists($salesFile) ? json_decode(file_get_contents($salesFile), true) : [];
$stockExpenses = file_exists($stockExpensesFile) ? json_decode(file_get_contents($stockExpensesFile), true) : [];
$salaries = file_exists($salariesFile) ? json_decode(file_get_contents($salariesFile), true) : [];
$billings = file_exists($billingsFile) ? json_decode(file_get_contents($billingsFile), true) : [];
$credits = file_exists($creditFile) ? json_decode(file_get_contents($creditFile), true) : [];
$stocks = file_exists($stockFile) ? json_decode(file_get_contents($stockFile), true) : [];
$productStocks = file_exists($productStockFile) ? json_decode(file_get_contents($productStockFile), true) : [];

if(!is_array($sales)) $sales = [];
if(!is_array($stockExpenses)) $stockExpenses = [];
if(!is_array($salaries)) $salaries = [];
if(!is_array($billings)) $billings = [];
if(!is_array($credits)) $credits = [];
if(!is_array($stocks)) $stocks = [];
if(!is_array($productStocks)) $productStocks = [];

// Filter parameters
$filterYear = trim($_GET['year'] ?? '');
$filterMonth = trim($_GET['month'] ?? '');

// Get available years from all data
$allYears = [];
foreach(array_merge($sales, $stockExpenses, $salaries, $billings) as $item) {
    $date = $item['date'] ?? $item['date_added'] ?? '';
    if($date) {
        $year = date('Y', strtotime($date));
        if($year && !in_array($year, $allYears)) {
            $allYears[] = $year;
        }
    }
}
sort($allYears);
if(empty($allYears)) $allYears[] = date('Y');

// If no filter, default to current year
if($filterYear === '') {
    $filterYear = date('Y');
}

// Filter helper function
function matchesDateFilter($dateStr, $filterYear, $filterMonth) {
    if(empty($dateStr)) return false;
    
    $timestamp = strtotime($dateStr);
    if(!$timestamp) return false;
    
    $itemYear = date('Y', $timestamp);
    $itemMonth = date('m', $timestamp);
    
    // Check year
    if($filterYear !== '' && $itemYear !== $filterYear) {
        return false;
    }
    
    // Check month
    if($filterMonth !== '' && $itemMonth !== $filterMonth) {
        return false;
    }
    
    return true;
}

// =========================
// PROFIT & LOSS CALCULATIONS
// =========================

// 1. REVENUE (from sales)
$totalRevenue = 0;
$totalSalesCount = 0;
foreach($sales as $sale) {
    $saleDate = $sale['date'] ?? '';
    if(matchesDateFilter($saleDate, $filterYear, $filterMonth)) {
        $totalRevenue += (float)($sale['total_price'] ?? 0);
        $totalSalesCount++;
    }
}

// 2. COST OF GOODS SOLD (from stock-expenses)
$totalCOGS = 0;
$totalPurchasesCount = 0;
foreach($stockExpenses as $expense) {
    $expenseDate = $expense['date'] ?? '';
    if(matchesDateFilter($expenseDate, $filterYear, $filterMonth)) {
        $totalCOGS += (float)($expense['total_bill'] ?? 0);
        $totalPurchasesCount++;
    }
}

// 3. GROSS PROFIT
$grossProfit = $totalRevenue - $totalCOGS;
$grossProfitMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;

// 4. OPERATING EXPENSES
// 4a. Salaries
$totalSalaries = 0;
$totalSalariesCount = 0;
foreach($salaries as $salary) {
    $salaryDate = $salary['date'] ?? '';
    if(matchesDateFilter($salaryDate, $filterYear, $filterMonth)) {
        $totalSalaries += (float)($salary['amount'] ?? 0);
        $totalSalariesCount++;
    }
}

// 4b. Other Expenses (billings)
$totalBillings = 0;
$totalBillingsCount = 0;
foreach($billings as $billing) {
    $billingDate = $billing['date'] ?? '';
    if(matchesDateFilter($billingDate, $filterYear, $filterMonth)) {
        $totalBillings += (float)($billing['total'] ?? 0);
        $totalBillingsCount++;
    }
}

$totalOperatingExpenses = $totalSalaries + $totalBillings;

// 5. NET PROFIT
$netProfit = $grossProfit - $totalOperatingExpenses;
$netProfitMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

// =========================
// BALANCE SHEET CALCULATIONS (Current/Overall)
// =========================

// ASSETS
// 1. Inventory - Raw Materials (stock.json)
$totalRawMaterialInventory = 0;
foreach($stocks as $stock) {
    $totalRawMaterialInventory += (float)($stock['total_price'] ?? 0);
}

// 2. Inventory - Finished Goods (product-stock.json)
$totalFinishedGoodsInventory = 0;
foreach($productStocks as $product) {
    $totalFinishedGoodsInventory += (float)($product['total_value'] ?? 0);
}

$totalInventory = $totalRawMaterialInventory + $totalFinishedGoodsInventory;

// 3. Accounts Receivable (credits with due > 0)
$totalAccountsReceivable = 0;
$totalCustomersWithDue = 0;
foreach($credits as $credit) {
    $dueAmount = (float)($credit['due_amount'] ?? 0);
    if($dueAmount > 0) {
        $totalAccountsReceivable += $dueAmount;
        $totalCustomersWithDue++;
    }
}

// 4. Cash (Calculated: Revenue received - Expenses paid - Accounts Receivable)
// This is simplified - in real accounting, you'd track actual cash transactions
$totalCashReceived = $totalRevenue; // From filtered sales
$totalCashPaid = $totalCOGS + $totalSalaries + $totalBillings; // From filtered expenses
$calculatedCash = $totalCashReceived - $totalCashPaid;

$totalCurrentAssets = $calculatedCash + $totalAccountsReceivable + $totalInventory;

// LIABILITIES (Simplified - typically you'd track payables)
$totalCurrentLiabilities = 0; // Could add accounts payable if tracked

// EQUITY
$totalEquity = $totalCurrentAssets - $totalCurrentLiabilities;

// =========================
// Export to Excel
// =========================
if(isset($_GET['export']) && $_GET['export'] === 'xls') {
    $filename = "financial_report_" . ($filterYear ?: 'all') . ($filterMonth ? '_' . $filterMonth : '') . "_" . date("Ymd_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF";
    
    $periodText = "Period: ";
    if($filterYear !== '') {
        $periodText .= $filterYear;
        if($filterMonth !== '') {
            $periodText .= " - " . date('F', mktime(0, 0, 0, $filterMonth, 1));
        }
    } else {
        $periodText .= "All Time";
    }
    
    echo "GARGA COPY UDHYOG\n";
    echo "FINANCIAL REPORT\n";
    echo $periodText . "\n\n";
    
    echo "PROFIT & LOSS ACCOUNT\n";
    echo "Description\tAmount\n";
    echo "Revenue\t" . number_format($totalRevenue, 2) . "\n";
    echo "Cost of Goods Sold\t" . number_format($totalCOGS, 2) . "\n";
    echo "Gross Profit\t" . number_format($grossProfit, 2) . "\n";
    echo "Salaries\t" . number_format($totalSalaries, 2) . "\n";
    echo "Other Expenses\t" . number_format($totalBillings, 2) . "\n";
    echo "Total Operating Expenses\t" . number_format($totalOperatingExpenses, 2) . "\n";
    echo "Net Profit\t" . number_format($netProfit, 2) . "\n\n";
    
    echo "BALANCE SHEET\n";
    echo "ASSETS\n";
    echo "Cash\t" . number_format($calculatedCash, 2) . "\n";
    echo "Accounts Receivable\t" . number_format($totalAccountsReceivable, 2) . "\n";
    echo "Inventory - Raw Materials\t" . number_format($totalRawMaterialInventory, 2) . "\n";
    echo "Inventory - Finished Goods\t" . number_format($totalFinishedGoodsInventory, 2) . "\n";
    echo "Total Current Assets\t" . number_format($totalCurrentAssets, 2) . "\n\n";
    
    echo "LIABILITIES\n";
    echo "Current Liabilities\t" . number_format($totalCurrentLiabilities, 2) . "\n\n";
    
    echo "EQUITY\n";
    echo "Total Equity\t" . number_format($totalEquity, 2) . "\n";
    
    exit();
}

include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Financial Reports - Garga Copy Udhyog</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
@media print {
    .no-print { display: none !important; }
    body { padding: 20px; }
}
.profit { color: #28a745; font-weight: 600; }
.loss { color: #dc3545; font-weight: 600; }
.card-header { font-weight: 600; font-size: 1.1rem; }
.summary-card { border-left: 4px solid; }
.summary-card.revenue { border-left-color: #28a745; }
.summary-card.expense { border-left-color: #dc3545; }
.summary-card.profit { border-left-color: #17a2b8; }
.summary-card.asset { border-left-color: #ffc107; }
.table-financial th { background-color: #f8f9fa; }
.table-financial .indent-1 { padding-left: 2rem; }
.table-financial .indent-2 { padding-left: 3rem; }
.table-financial .total-row { font-weight: 700; background-color: #f8f9fa; border-top: 2px solid #dee2e6; }
.table-financial .subtotal-row { font-weight: 600; background-color: #f8f9fa; }
</style>
</head>
<body class="bg-light">
<div class="container my-4">
<h2 class="mb-4">📊 Financial Reports</h2>

<!-- Filter Section -->
<div class="card mb-4 no-print">
<div class="card-header bg-primary text-white">📅 Filter Period</div>
<div class="card-body">
<form method="get" class="row g-3">
    <div class="col-md-4">
        <label class="form-label fw-bold">Year</label>
        <select name="year" class="form-select">
            <option value="">All Years</option>
            <?php foreach($allYears as $year): ?>
                <option value="<?= $year ?>" <?= $filterYear === $year ? 'selected' : '' ?>>
                    <?= $year ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="col-md-4">
        <label class="form-label fw-bold">Month</label>
        <select name="month" class="form-select">
            <option value="">All Months</option>
            <?php 
            $months = [
                '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
                '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
                '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
            ];
            foreach($months as $num => $name): 
            ?>
                <option value="<?= $num ?>" <?= $filterMonth === $num ? 'selected' : '' ?>>
                    <?= $name ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="col-md-4 d-flex align-items-end">
        <button type="submit" class="btn btn-primary me-2">🔍 Apply Filter</button>
        
        <a href="?year=<?= urlencode($filterYear) ?>&month=<?= urlencode($filterMonth) ?>&export=xls" class="btn btn-success">📥 Export</a>
    </div>
</form>

<!-- Period Display -->
<div class="mt-3 alert alert-info mb-0">
    <strong>📆 Viewing Period:</strong> 
    <?php 
    if($filterYear !== '') {
        echo $filterYear;
        if($filterMonth !== '') {
            echo " - " . $months[$filterMonth];
        } else {
            echo " (Full Year)";
        }
    } else {
        echo "All Time";
    }
    ?>
</div>
</div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card summary-card revenue">
            <div class="card-body">
                <h6 class="text-muted">💰 Total Revenue</h6>
                <h3 class="mb-0">₹<?= number_format($totalRevenue, 2) ?></h3>
                <small class="text-muted"><?= $totalSalesCount ?> sales</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card summary-card expense">
            <div class="card-body">
                <h6 class="text-muted">💸 Total Expenses</h6>
                <h3 class="mb-0">₹<?= number_format($totalCOGS + $totalOperatingExpenses, 2) ?></h3>
                <small class="text-muted">COGS + Operating</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card summary-card profit">
            <div class="card-body">
                <h6 class="text-muted">📈 Net Profit</h6>
                <h3 class="mb-0 <?= $netProfit >= 0 ? 'profit' : 'loss' ?>">
                    ₹<?= number_format($netProfit, 2) ?>
                </h3>
                <small class="text-muted">Margin: <?= number_format($netProfitMargin, 2) ?>%</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card summary-card asset">
            <div class="card-body">
                <h6 class="text-muted">🏦 Total Assets</h6>
                <h3 class="mb-0">₹<?= number_format($totalCurrentAssets, 2) ?></h3>
                <small class="text-muted">Current assets</small>
            </div>
        </div>
    </div>
</div>

<!-- Profit & Loss Account -->
<div class="card mb-4">
<div class="card-header bg-success text-white">
    💹 Profit & Loss Account
    <?php if($filterYear): ?>
        - <?= $filterYear ?><?= $filterMonth ? ' (' . $months[$filterMonth] . ')' : '' ?>
    <?php endif; ?>
</div>
<div class="card-body">
<table class="table table-financial mb-0">
<tbody>
    <!-- Revenue Section -->
    <tr>
        <td><strong>REVENUE</strong></td>
        <td class="text-end"></td>
    </tr>
    <tr>
        <td class="indent-1">Sales Revenue</td>
        <td class="text-end">₹<?= number_format($totalRevenue, 2) ?></td>
    </tr>
    <tr class="subtotal-row">
        <td><strong>Total Revenue</strong></td>
        <td class="text-end"><strong>₹<?= number_format($totalRevenue, 2) ?></strong></td>
    </tr>
    
    <tr><td colspan="2">&nbsp;</td></tr>
    
    <!-- Cost of Goods Sold -->
    <tr>
        <td><strong>COST OF GOODS SOLD</strong></td>
        <td class="text-end"></td>
    </tr>
    <tr>
        <td class="indent-1">Stock Purchases</td>
        <td class="text-end">₹<?= number_format($totalCOGS, 2) ?></td>
    </tr>
    <tr class="subtotal-row">
        <td><strong>Total COGS</strong></td>
        <td class="text-end"><strong>₹<?= number_format($totalCOGS, 2) ?></strong></td>
    </tr>
    
    <tr><td colspan="2">&nbsp;</td></tr>
    
    <!-- Gross Profit -->
    <tr class="subtotal-row">
        <td><strong>GROSS PROFIT</strong></td>
        <td class="text-end <?= $grossProfit >= 0 ? 'profit' : 'loss' ?>">
            <strong>₹<?= number_format($grossProfit, 2) ?></strong>
            <br><small>(Margin: <?= number_format($grossProfitMargin, 2) ?>%)</small>
        </td>
    </tr>
    
    <tr><td colspan="2">&nbsp;</td></tr>
    
    <!-- Operating Expenses -->
    <tr>
        <td><strong>OPERATING EXPENSES</strong></td>
        <td class="text-end"></td>
    </tr>
    <tr>
        <td class="indent-1">Salaries & Wages</td>
        <td class="text-end">₹<?= number_format($totalSalaries, 2) ?></td>
    </tr>
    <tr>
        <td class="indent-1">Other Expenses (Billings)</td>
        <td class="text-end">₹<?= number_format($totalBillings, 2) ?></td>
    </tr>
    <tr class="subtotal-row">
        <td><strong>Total Operating Expenses</strong></td>
        <td class="text-end"><strong>₹<?= number_format($totalOperatingExpenses, 2) ?></strong></td>
    </tr>
    
    <tr><td colspan="2">&nbsp;</td></tr>
    
    <!-- Net Profit -->
    <tr class="total-row">
        <td><strong>NET PROFIT / (LOSS)</strong></td>
        <td class="text-end <?= $netProfit >= 0 ? 'profit' : 'loss' ?>">
            <strong>₹<?= number_format($netProfit, 2) ?></strong>
            <br><small>(Margin: <?= number_format($netProfitMargin, 2) ?>%)</small>
        </td>
    </tr>
</tbody>
</table>
</div>
</div>

<!-- Balance Sheet -->
<div class="card mb-4">
<div class="card-header bg-info text-white">⚖️ Balance Sheet (Current Position)</div>
<div class="card-body">
<div class="row">
    <!-- Assets Column -->
    <div class="col-md-6">
        <h5 class="text-primary">ASSETS</h5>
        <table class="table table-financial table-sm">
        <tbody>
            <tr>
                <td><strong>CURRENT ASSETS</strong></td>
                <td class="text-end"></td>
            </tr>
            <tr>
                <td class="indent-1">Cash</td>
                <td class="text-end">₹<?= number_format($calculatedCash, 2) ?></td>
            </tr>
            <tr>
                <td class="indent-1">Accounts Receivable</td>
                <td class="text-end">₹<?= number_format($totalAccountsReceivable, 2) ?></td>
            </tr>
            <tr>
                <td class="indent-1">Inventory - Raw Materials</td>
                <td class="text-end">₹<?= number_format($totalRawMaterialInventory, 2) ?></td>
            </tr>
            <tr>
                <td class="indent-1">Inventory - Finished Goods</td>
                <td class="text-end">₹<?= number_format($totalFinishedGoodsInventory, 2) ?></td>
            </tr>
            <tr class="total-row">
                <td><strong>TOTAL CURRENT ASSETS</strong></td>
                <td class="text-end"><strong>₹<?= number_format($totalCurrentAssets, 2) ?></strong></td>
            </tr>
        </tbody>
        </table>
    </div>
    
    <!-- Liabilities & Equity Column -->
    <div class="col-md-6">
        <h5 class="text-danger">LIABILITIES & EQUITY</h5>
        <table class="table table-financial table-sm">
        <tbody>
            <tr>
                <td><strong>CURRENT LIABILITIES</strong></td>
                <td class="text-end"></td>
            </tr>
            <tr>
                <td class="indent-1">Accounts Payable</td>
                <td class="text-end">₹<?= number_format($totalCurrentLiabilities, 2) ?></td>
            </tr>
            <tr class="subtotal-row">
                <td><strong>Total Current Liabilities</strong></td>
                <td class="text-end"><strong>₹<?= number_format($totalCurrentLiabilities, 2) ?></strong></td>
            </tr>
            
            <tr><td colspan="2">&nbsp;</td></tr>
            
            <tr>
                <td><strong>EQUITY</strong></td>
                <td class="text-end"></td>
            </tr>
            <tr>
                <td class="indent-1">Owner's Equity</td>
                <td class="text-end">₹<?= number_format($totalEquity, 2) ?></td>
            </tr>
            <tr class="total-row">
                <td><strong>TOTAL LIABILITIES & EQUITY</strong></td>
                <td class="text-end"><strong>₹<?= number_format($totalEquity + $totalCurrentLiabilities, 2) ?></strong></td>
            </tr>
        </tbody>
        </table>
    </div>
</div>
</div>
</div>

<!-- Detailed Breakdown -->
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-warning">📋 Breakdown</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td>Total Sales Transactions</td>
                        <td class="text-end fw-bold"><?= $totalSalesCount ?></td>
                    </tr>
                    <tr>
                        <td>Total Stock Purchases</td>
                        <td class="text-end fw-bold"><?= $totalPurchasesCount ?></td>
                    </tr>
                    <tr>
                        <td>Salary Payments</td>
                        <td class="text-end fw-bold"><?= $totalSalariesCount ?></td>
                    </tr>
                    <tr>
                        <td>Other Expense Items</td>
                        <td class="text-end fw-bold"><?= $totalBillingsCount ?></td>
                    </tr>
                    <tr>
                        <td>Customers with Pending Dues</td>
                        <td class="text-end fw-bold"><?= $totalCustomersWithDue ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-secondary text-white">📊 Key Ratios</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td>Gross Profit Margin</td>
                        <td class="text-end fw-bold"><?= number_format($grossProfitMargin, 2) ?>%</td>
                    </tr>
                    <tr>
                        <td>Net Profit Margin</td>
                        <td class="text-end fw-bold <?= $netProfitMargin >= 0 ? 'profit' : 'loss' ?>">
                            <?= number_format($netProfitMargin, 2) ?>%
                        </td>
                    </tr>
                    <tr>
                        <td>Operating Expense Ratio</td>
                        <td class="text-end fw-bold">
                            <?= $totalRevenue > 0 ? number_format(($totalOperatingExpenses / $totalRevenue) * 100, 2) : 0 ?>%
                        </td>
                    </tr>
                    <tr>
                        <td>Current Ratio</td>
                        <td class="text-end fw-bold">
                            <?= $totalCurrentLiabilities > 0 ? number_format($totalCurrentAssets / $totalCurrentLiabilities, 2) : '∞' ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Average Sale Value</td>
                        <td class="text-end fw-bold">
                            ₹<?= $totalSalesCount > 0 ? number_format($totalRevenue / $totalSalesCount, 2) : 0 ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="text-center mt-4 text-muted no-print">
    <small>Report generated on <?= date('d F Y, h:i A') ?></small>
</div>

</div>
<?php include 'footer.php'; ?>
</body>
</html>
