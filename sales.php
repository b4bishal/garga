<?php
// sales.php

$productStockFile = __DIR__ . "/product-stock.json";
$salesFile        = __DIR__ . "/sales.json";
$customersFile    = __DIR__ . "/customers.json";
$creditFile       = __DIR__ . "/credit.json";

// -------------------------
// Load product stock
$productStocks = file_exists($productStockFile) ? json_decode(file_get_contents($productStockFile), true) : [];
if (!is_array($productStocks)) $productStocks = [];

// Load sales
$sales = file_exists($salesFile) ? json_decode(file_get_contents($salesFile), true) : [];
if (!is_array($sales)) $sales = [];

// Load credits
$credits = file_exists($creditFile) ? json_decode(file_get_contents($creditFile), true) : [];
if (!is_array($credits)) $credits = [];

// -------------------------
// Load customers
$customersData = file_exists($customersFile) ? json_decode(file_get_contents($customersFile), true) : [];
if (!is_array($customersData)) $customersData = [];

if (isset($customersData['customers']) && is_array($customersData['customers'])) {
    $customersData = $customersData['customers'];
}

// Build customers list
$customersList = [];
foreach ($customersData as $c) {
    if (is_string($c)) {
        $name = trim($c);
        if ($name !== '') {
            $customersList[] = [
                "name" => $name,
                "email" => "",
                "phone" => "",
                "pan" => "",
                "address" => ""
            ];
        }
    } elseif (is_array($c)) {
        $name = trim($c['name'] ?? $c['customer_name'] ?? $c['full_name'] ?? '');
        if ($name !== '') {
            $customersList[] = [
                "name" => $name,
                "email" => trim($c['email'] ?? ''),
                "phone" => trim($c['phone'] ?? $c['contact'] ?? $c['contact_number'] ?? ''),
                "pan" => trim($c['pan'] ?? ''),
                "address" => trim($c['address'] ?? $c['location'] ?? '')
            ];
        }
    }
}

// Deduplicate
$byName = [];
foreach ($customersList as $c) {
    $n = $c['name'];
    if (!isset($byName[$n])) {
        $byName[$n] = $c;
    } else {
        foreach (['email','phone','pan','address'] as $k) {
            if (($byName[$n][$k] ?? '') === '' && ($c[$k] ?? '') !== '') {
                $byName[$n][$k] = $c[$k];
            }
        }
    }
}
$customersList = array_values($byName);
usort($customersList, fn($a, $b) => strcmp($a['name'], $b['name']));

// =========================
// SaleID helpers (Year-000001)
function get_next_sale_id($sales, $year) {
    $prefix = $year . "-";
    $max = 0;

    foreach ($sales as $s) {
        $sid = (string)($s['sale_id'] ?? '');
        if ($sid === '' || strpos($sid, $prefix) !== 0) continue;

        $numPart = substr($sid, strlen($prefix));
        if ($numPart === '' || !ctype_digit($numPart)) continue;

        $n = (int)$numPart;
        if ($n > $max) $max = $n;
    }

    $next = $max + 1;
    return $year . "-" . sprintf("%06d", $next);
}

$currentYear = date("Y");
$nextSaleIdPreview = get_next_sale_id($sales, $currentYear);

// =========================
// Handle Delete Sale (Restore Stock in UNITS)
if (isset($_GET['delete'])) {
    $deleteId = $_GET['delete'];
    $deletedSaleId = '';
    
    foreach ($sales as $key => $s) {
        if (($s['id'] ?? null) == $deleteId) {
            $deletedSaleId = $s['sale_id'] ?? '';
            
            // Restore stock using sold_unit (units)
            foreach ($productStocks as &$p) {
                if (($p['copy_category'] ?? '') === ($s['copy_category'] ?? '') && 
                    ($p['price_per_unit'] ?? 0) == ($s['price_per_unit'] ?? 0)) {
                    $p['total_quantity'] = (float)($p['total_quantity'] ?? 0) + (float)($s['sold_unit'] ?? 0);
                    $p['total_value'] = (float)$p['total_quantity'] * (float)$p['price_per_unit'];
                    break;
                }
            }
            unset($sales[$key]);
            break;
        }
    }
    
    // Check if all items deleted, remove from credit
    $billExists = false;
    foreach ($sales as $s) {
        if (($s['sale_id'] ?? '') === $deletedSaleId) {
            $billExists = true;
            break;
        }
    }
    
    if (!$billExists && $deletedSaleId !== '') {
        foreach ($credits as $key => $c) {
            if (($c['sale_id'] ?? '') === $deletedSaleId) {
                unset($credits[$key]);
                break;
            }
        }
        $credits = array_values($credits);
        file_put_contents($creditFile, json_encode($credits, JSON_PRETTY_PRINT));
    }
    
    file_put_contents($productStockFile, json_encode(array_values($productStocks), JSON_PRETTY_PRINT));
    file_put_contents($salesFile, json_encode(array_values($sales), JSON_PRETTY_PRINT));
    header("Location: sales.php");
    exit();
}

// =========================
// Handle New Sale (multiple products)
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sale'])) {
    $itemIds      = $_POST['item_id'] ?? [];
    $soldDozens   = $_POST['sold_dozen'] ?? [];
    $discounts    = $_POST['discount'] ?? [];
    $bulkQtys     = $_POST['bulk_qty'] ?? [];
    $paidAmount   = isset($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : 0;
    $remarksAll   = trim($_POST['remarks'] ?? '');

    $customerName = trim($_POST['customer_name'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $customerPan   = trim($_POST['customer_pan'] ?? '');
    $customerAddress = trim($_POST['customer_address'] ?? '');

    if ($customerName === '') {
        $error = "❌ Please select a customer.";
    }

    $createdIds = [];
    $saleId = get_next_sale_id($sales, date("Y"));
    $grandTotal = 0;

    if (!$error) {
        // First pass: calculate grand total
        foreach ($itemIds as $index => $itemId) {
            if (!$itemId) continue;
            
            $soldDozen = (int)($soldDozens[$index] ?? 0);
            $soldUnit = $soldDozen * 12;
            $discount = isset($discounts[$index]) ? (float)$discounts[$index] : 0;
            
            foreach ($productStocks as $p) {
                if (($p['id'] ?? null) == $itemId) {
                    $pricePerUnit = (float)($p['price_per_unit'] ?? 0);
                    $pricePerDozen = $pricePerUnit * 12;
                    $grossPrice = $soldDozen * $pricePerDozen;
                    $discountAmount = ($grossPrice * $discount) / 100;
                    $totalPrice = $grossPrice - $discountAmount;
                    $grandTotal += $totalPrice;
                    break;
                }
            }
        }
        
        $dueAmount = $grandTotal - $paidAmount;

        // Second pass: save items
        foreach ($itemIds as $index => $itemId) {
            if (!$itemId) continue;

            $soldDozen = (int)($soldDozens[$index] ?? 0);
            $soldUnit = $soldDozen * 12;
            $discount = isset($discounts[$index]) ? (float)$discounts[$index] : 0;
            $bulkQty = trim($bulkQtys[$index] ?? '');
            $bulkQty = preg_replace('/[^0-9a-zA-Z\s\.\,\-\_\/\(\)]/u', '', $bulkQty);
            $bulkQty = substr($bulkQty, 0, 50);

            foreach ($productStocks as &$p) {
                if (($p['id'] ?? null) == $itemId) {
                    $availableQty = (float)($p['total_quantity'] ?? 0);

                    if ($soldUnit > $availableQty) {
                        $error = "❌ Error: Sold units for '{$p['copy_category']}' exceed available stock ({$availableQty} units = " . floor($availableQty/12) . " dozen).";
                        break 2;
                    }

                    $pricePerUnit = (float)($p['price_per_unit'] ?? 0);
                    $pricePerDozen = $pricePerUnit * 12;
                    $grossPrice = $soldDozen * $pricePerDozen;
                    $discountAmount = ($grossPrice * $discount) / 100;
                    $totalPrice = $grossPrice - $discountAmount;

                    // Deduct stock in UNITS
                    $p['total_quantity'] = $availableQty - $soldUnit;
                    $p['total_value'] = (float)$p['total_quantity'] * $pricePerUnit;

                    $entryId = time() . rand(100, 999);

                    $sales[] = [
                        "id" => $entryId,
                        "sale_id" => $saleId,

                        "customer_name" => $customerName,
                        "customer_email" => $customerEmail,
                        "customer_phone" => $customerPhone,
                        "customer_pan" => $customerPan,
                        "customer_address" => $customerAddress,

                        "copy_category" => $p['copy_category'],
                        "bulk_qty" => $bulkQty,
                        "price_per_unit" => $pricePerUnit,
                        "price_per_dozen" => $pricePerDozen,
                        "sold_dozen" => $soldDozen,
                        "sold_unit" => $soldUnit,
                        "discount" => $discount,
                        "discount_amount" => $discountAmount,
                        "gross_price" => $grossPrice,
                        "total_price" => $totalPrice,
                        "paid_amount" => $paidAmount,
                        "due_amount" => $dueAmount,
                        "remarks" => $remarksAll,
                        "date" => date("Y-m-d H:i:s")
                    ];

                    $createdIds[] = $entryId;
                    break;
                }
            }
        }
        
        // Save to credit.json if due
        if (!$error && $dueAmount > 0) {
            $paymentHistory = [];
            
            if ($paidAmount > 0) {
                $paymentHistory[] = [
                    'amount' => $paidAmount,
                    'date' => date("Y-m-d H:i:s"),
                    'remaining_due' => $dueAmount,
                    'type' => 'initial'
                ];
            }
            
            $creditEntry = [
                "sale_id" => $saleId,
                "customer_name" => $customerName,
                "customer_email" => $customerEmail,
                "customer_phone" => $customerPhone,
                "customer_pan" => $customerPan,
                "customer_address" => $customerAddress,
                "date" => date("Y-m-d H:i:s"),
                "total_bill" => $grandTotal,
                "paid_amount" => $paidAmount,
                "due_amount" => $dueAmount,
                "payment_history" => $paymentHistory
            ];
            
            $credits[] = $creditEntry;
            file_put_contents($creditFile, json_encode($credits, JSON_PRETTY_PRINT));
        }
    }

    if (!$error) {
        file_put_contents($productStockFile, json_encode($productStocks, JSON_PRETTY_PRINT));
        file_put_contents($salesFile, json_encode($sales, JSON_PRETTY_PRINT));
        if (count($createdIds) > 0) {
            header("Location: sales.php?saved_ids=" . urlencode(implode(',', $createdIds)));
        } else {
            header("Location: sales.php");
        }
        exit();
    }
}

// =========================
// Handle Search
$search = trim($_GET['search'] ?? '');
$contains = fn($haystack, $needle) => stripos((string)$haystack, (string)$needle) !== false;

$matches_search = function($s, $searchTerm) use ($contains) {
    if ($searchTerm === '') return true;
    foreach ($s as $k => $v) {
        if (is_array($v) || is_object($v)) continue;
        if ($contains($v, $searchTerm)) return true;
    }
    return false;
};

$filteredSales = array_values(array_filter($sales, fn($s) => $matches_search($s, $search)));

// =========================
// Print group check
$filteredSaleIds = array_values(array_filter(array_map(fn($s) => (string)($s['sale_id'] ?? ''), $filteredSales)));
$uniqueSaleIds = array_values(array_unique($filteredSaleIds));

$printSaleGroup = [];
$printSaleId = '';
if (count($filteredSales) > 0 && count($uniqueSaleIds) === 1) {
    $printSaleId = $uniqueSaleIds[0];
    $printSaleGroup = array_values(array_filter($filteredSales, fn($s) => (string)($s['sale_id'] ?? '') === $printSaleId));
}

// =========================
// Export XLS
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $filename = "sales_" . date("Ymd_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF";

    echo "Bill Number\tCustomer\tEmail\tPhone\tPAN\tAddress\tCopy Category\tQuantity\tPrice per Dozen\tSold Dozen\tSold Units\tGross Price\tDiscount (%)\tDiscount Amount\tNet Amount\tRemarks\tDate\n";
    foreach ($filteredSales as $s) {
        echo ($s['sale_id'] ?? '') . "\t" .
             ($s['customer_name'] ?? '') . "\t" .
             ($s['customer_email'] ?? '') . "\t" .
             ($s['customer_phone'] ?? '') . "\t" .
             ($s['customer_pan'] ?? '') . "\t" .
             ($s['customer_address'] ?? '') . "\t" .
             ($s['copy_category'] ?? '') . "\t" .
             ($s['bulk_qty'] ?? '') . "\t" .
             ($s['price_per_dozen'] ?? '') . "\t" .
             (int)($s['sold_dozen'] ?? 0) . "\t" .
             ($s['sold_unit'] ?? '') . "\t" .
             ($s['gross_price'] ?? '') . "\t" .
             ($s['discount'] ?? 0) . "\t" .
             ($s['discount_amount'] ?? '') . "\t" .
             ($s['total_price'] ?? '') . "\t" .
             ($s['remarks'] ?? '') . "\t" .
             ($s['date'] ?? '') . "\n";
    }
    exit();
}

include 'navbar.php';

// Saved sales for printing
$savedSales = [];
if (!empty($_GET['saved_ids'])) {
    $ids = array_filter(explode(',', $_GET['saved_ids']));
    foreach ($sales as $s) {
        if (in_array(($s['id'] ?? ''), $ids)) $savedSales[] = $s;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales - Garga Copy Udhyog</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
@media print {
    body * { visibility: hidden; }
    #printableSlip, #printableSlip * { visibility: visible; }
    #printableSlip { position: absolute; top: 0; left: 0; width: 100%; }
}
.print-logo { width: 100px; margin-bottom: 10px; }
.table-focus th, .table-focus td { vertical-align: middle; }
</style>

<script>
function updateSaleInfo(select) {
    const selected = select.options[select.selectedIndex];
    const pricePerUnit = parseFloat(selected.getAttribute('data-price') || 0);
    const stock = parseFloat(selected.getAttribute('data-stock') || 0);
    const parent = select.closest('.product-group');
    
    const pricePerDozen = pricePerUnit * 12;
    const stockInDozen = Math.floor(stock / 12);
    
    parent.querySelector('.price_per_dozen').value = pricePerDozen.toFixed(2);
    parent.querySelector('.available_stock').value = stockInDozen;
    parent.querySelector('.available_stock_units').value = stock;
    parent.querySelector('.sold_dozen').value = '';
    parent.querySelector('.discount').value = '';
    parent.querySelector('.total_price').value = '';
    calculateGrandTotal();
}

function calculateTotalPrice(row) {
    const soldDozen = parseInt(row.querySelector('.sold_dozen').value) || 0;
    const pricePerDozen = parseFloat(row.querySelector('.price_per_dozen').value) || 0;
    const stockDozen = parseInt(row.querySelector('.available_stock').value) || 0;
    const discount = parseFloat(row.querySelector('.discount').value) || 0;

    if (soldDozen > stockDozen) {
        alert(`❌ Sold dozen cannot exceed available stock (${stockDozen} dozen)`);
        row.querySelector('.sold_dozen').value = stockDozen;
        return;
    }

    let gross = soldDozen * pricePerDozen;
    let discountAmt = (gross * (discount / 100));
    let total = gross - discountAmt;

    row.querySelector('.total_price').value = total.toFixed(2);
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let grandTotal = 0;
    document.querySelectorAll('.total_price').forEach(inp => {
        grandTotal += parseFloat(inp.value) || 0;
    });
    
    document.getElementById('grand_total_display').value = grandTotal.toFixed(2);
    calculateDue();
}

function calculateDue() {
    const grandTotal = parseFloat(document.getElementById('grand_total_display').value) || 0;
    const paidAmount = parseFloat(document.getElementById('paid_amount').value) || 0;
    const due = grandTotal - paidAmount;
    document.getElementById('due_amount').value = due.toFixed(2);
}

function addAnotherProduct() {
    const container = document.getElementById('productContainer');
    const template = container.querySelector('.product-group');
    const clone = template.cloneNode(true);
    clone.querySelectorAll('input').forEach(inp => inp.value = '');
    clone.querySelectorAll('select').forEach(sel => sel.selectedIndex = 0);
    container.appendChild(clone);
}

function fillCustomerDetails(sel) {
    const opt = sel.options[sel.selectedIndex];
    const email = opt.getAttribute('data-email') || '';
    const phone = opt.getAttribute('data-phone') || '';
    const pan = opt.getAttribute('data-pan') || '';
    const address = opt.getAttribute('data-address') || '';

    document.getElementById('customer_email').value = email;
    document.getElementById('customer_phone').value = phone;
    document.getElementById('customer_pan').value = pan;
    document.getElementById('customer_address').value = address;
}

function printCombinedSlip(savedSalesJson) {
    try {
        const arr = JSON.parse(savedSalesJson);
        if (!Array.isArray(arr) || arr.length === 0) return alert('No sale data found.');

        const first = arr[0] || {};
        const customer = first.customer_name || '';
        const email = first.customer_email || '';
        const phone = first.customer_phone || '';
        const customerPan = first.customer_pan || '';
        const address = first.customer_address || '';
        const date = first.date || '';
        const saleId = first.sale_id || '-';
        const paidAmount = Number(first.paid_amount || 0);
        const dueAmount = Number(first.due_amount || 0);

        let rows = '';
        let totalGross = 0, totalDiscountAmt = 0, totalNet = 0;
        let sn = 1;

        arr.forEach(s => {
            const ratePerDozen = Number(s.price_per_dozen || 0);
            const soldDozen = parseInt(s.sold_dozen || 0);
            const gross = Number(s.gross_price || 0);
            const discountAmt = Number(s.discount_amount || 0);
            const net = Number(s.total_price || 0);
            totalGross += gross;
            totalDiscountAmt += discountAmt;
            totalNet += net;

            const bulkText = (s.bulk_qty ?? '').toString();

            rows += `<tr>
                <td style="text-align:center">${sn++}</td>
                <td>${s.copy_category}</td>
                <td>${bulkText}</td>
                <td style="text-align:right">₹${ratePerDozen.toFixed(2)}/dz</td>
                <td style="text-align:right">₹${gross.toFixed(2)}</td>
                <td style="text-align:right">₹${discountAmt.toFixed(2)}</td>
                <td style="text-align:right">₹${net.toFixed(2)}</td>
            </tr>`;
        });

        const slip = `
        <div id="printableSlip" style="width: 85%; max-width: 900px; margin: 20px auto; font-family: Arial, sans-serif; font-size: 14px;">
            
            <div style="text-align: center; margin-bottom:15px;">
                <img src="assets/images/logo.png" style="height: 100px;" class="print-logo"><br>
                <strong style="font-size: 22px;">Garga Copy Udhyog</strong><br>
                <span style="font-size: 16px;">Sales Slip</span><br>
                <span style="font-size: 14px;">PAN: 621544707</span>
                <hr style="margin-top:8px; margin-bottom:15px;">
            </div>

            <div style="display: flex; justify-content: space-between; margin-bottom:15px; font-size:15px;">
                <div style="flex: 1; padding-right: 20px;">
                    <strong>Bill Number:</strong> ${saleId} <br>
                    <strong>Date:</strong> ${date} <br>
                    <strong>Customer:</strong> ${customer} <br>
                    ${address ? `<strong>Address:</strong> ${address} <br>` : ''}
                </div>
                <div style="flex: 1; text-align: left;">
                    ${email ? `<strong>Email:</strong> ${email} <br>` : ''}
                    ${phone ? `<strong>Phone:</strong> ${phone} <br>` : ''}
                    ${customerPan ? `<strong>PAN:</strong> ${customerPan} <br>` : ''}
                </div>
            </div>

            <table style="width:100%; border-collapse: collapse; font-size: 14px;" class="table table-bordered table-focus">
                <thead class="table-light">
                    <tr>
                        <th style="text-align:center; width:50px;">S.N.</th>
                        <th>Copy Category</th>
                        <th>Quantity</th>
                        <th style="text-align:right">Rate</th>
                        <th style="text-align:right">Gross</th>
                        <th style="text-align:right">Discount</th>
                        <th style="text-align:right">Net</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;">
                        <td colspan="4" style="text-align:right">Total</td>
                        <td style="text-align:right">₹${totalGross.toFixed(2)}</td>
                        <td style="text-align:right">₹${totalDiscountAmt.toFixed(2)}</td>
                        <td style="text-align:right">₹${totalNet.toFixed(2)}</td>
                    </tr>
                    <tr style="font-weight:600;">
                        <td colspan="6" style="text-align:right">Paid Amount</td>
                        <td style="text-align:right">₹${paidAmount.toFixed(2)}</td>
                    </tr>
                    <tr style="font-weight:600; ${dueAmount > 0 ? 'color: #dc3545;' : ''}">
                        <td colspan="6" style="text-align:right">Due</td>
                        <td style="text-align:right">₹${dueAmount.toFixed(2)}</td>
                    </tr>
                </tfoot>
            </table>
            
            <hr style="margin-top: 40px;">
            
            <div style="display: flex; justify-content: space-between; margin-top: 50px; padding: 0 20px;">
                <div style="text-align: center; width: 40%;">
                    <div style="border-top: 2px solid #000; padding-top: 5px; margin-bottom: 5px;"></div>
                    <strong>Customer Signature</strong>
                </div>
                <div style="text-align: center; width: 40%;">
                    <div style="border-top: 2px solid #000; padding-top: 5px; margin-bottom: 5px;"></div>
                    <strong>Authorized Signature</strong>
                </div>
            </div>
            
            <p style="text-align:center; margin-top: 30px;">Thank you for your purchase!</p>
        </div>`;

        const w = window.open('', 'PrintCombinedSlip');
        w.document.write('<html><head><title>Print Slip</title>');
        w.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
        w.document.write('</head><body>' + slip + '</body></html>');
        w.document.close();
        w.print();
    } catch (e) {
        alert('Failed to prepare slip: ' + e.message);
    }
}
</script>
</head>

<body class="bg-light">
<div class="container my-4">
<h2 class="mb-4">💰 Sales Management (Per Dozen)</h2>

<?php if($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- New Sale Form -->
<div class="card mb-4">
<div class="card-header">Add Sale (Per Dozen)</div>
<div class="card-body">
<form method="post" id="saleForm">
    <div class="mb-3 row g-2">
        <div class="col-md-3">
            <input type="text" class="form-control" value="<?= htmlspecialchars($nextSaleIdPreview) ?>" readonly>
            <small class="text-muted">Bill Number (auto)</small>
        </div>

        <div class="col-md-3">
            <select name="customer_name" class="form-select" onchange="fillCustomerDetails(this)" required>
                <option value="">Select Customer</option>
                <?php foreach ($customersList as $c): ?>
                    <option
                        value="<?= htmlspecialchars($c['name']) ?>"
                        data-email="<?= htmlspecialchars($c['email']) ?>"
                        data-phone="<?= htmlspecialchars($c['phone']) ?>"
                        data-pan="<?= htmlspecialchars($c['pan']) ?>"
                        data-address="<?= htmlspecialchars($c['address']) ?>"
                    >
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6 text-end d-flex align-items-center justify-content-end">
            <small class="text-muted">📦 Note: Quantities are in <strong>Dozen</strong> (1 dozen = 12 units)</small>
        </div>
    </div>

    <!-- Customer details -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="text" name="customer_email" id="customer_email" class="form-control" placeholder="Email" readonly>
        </div>
        <div class="col-md-3">
            <input type="text" name="customer_phone" id="customer_phone" class="form-control" placeholder="Phone" readonly>
        </div>
        <div class="col-md-3">
            <input type="text" name="customer_pan" id="customer_pan" class="form-control" placeholder="PAN" readonly>
        </div>
        <div class="col-md-3">
            <input type="text" name="customer_address" id="customer_address" class="form-control" placeholder="Address" readonly>
        </div>
    </div>

    <div id="productContainer">
        <div class="product-group border rounded p-3 mb-3 bg-light">
            <div class="mb-2">
                <select name="item_id[]" class="form-select" onchange="updateSaleInfo(this)" required>
                    <option value="">Select Item</option>
                    <?php foreach($productStocks as $p): ?>
                    <option value="<?= $p['id'] ?>" data-price="<?= $p['price_per_unit'] ?>" data-stock="<?= $p['total_quantity'] ?>">
                        <?= htmlspecialchars($p['copy_category']) ?> (Available: <?= floor($p['total_quantity']/12) ?> dozen / <?= $p['total_quantity'] ?> units)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row mb-2 g-2">
                <div class="col">
                    <input type="number" class="form-control price_per_dozen" placeholder="Price per Dozen" readonly>
                    <small class="text-muted">Per Dozen</small>
                </div>
                <div class="col">
                    <input type="number" class="form-control available_stock" placeholder="Stock" readonly>
                    <input type="hidden" class="available_stock_units">
                    <small class="text-muted">Dozen Available</small>
                </div>
                <div class="col">
                    <input type="number" name="sold_dozen[]" class="form-control sold_dozen" placeholder="Sold Dozen" min="0" oninput="calculateTotalPrice(this.closest('.product-group'))" required>
                    <small class="text-muted">Whole Numbers Only</small>
                </div>
                <div class="col">
                    <input type="number" name="discount[]" class="form-control discount" placeholder="Discount %" oninput="calculateTotalPrice(this.closest('.product-group'))" min="0" max="100">
                    <small class="text-muted">Discount %</small>
                </div>
                <div class="col">
                    <input type="number" class="form-control total_price" placeholder="Total" readonly>
                    <small class="text-muted">After Discount</small>
                </div>
            </div>

            <div class="mb-2">
                <input type="text" name="bulk_qty[]" class="form-control" placeholder="Description (e.g., 2 box, 5 dozen) (optional)">
            </div>
        </div>
    </div>

    <div class="mb-3">
        <button type="button" class="btn btn-outline-success" onclick="addAnotherProduct()">➕ Add Another Product</button>
    </div>

    <!-- Payment Section -->
    <div class="row g-2 mb-3 border rounded p-3 bg-light">
        <div class="col-md-4">
            <label class="form-label fw-bold">Grand Total</label>
            <input type="number" id="grand_total_display" class="form-control" placeholder="0.00" readonly>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">Paid Amount</label>
            <input type="number" value="0" name="paid_amount" id="paid_amount" class="form-control" placeholder="Enter paid amount" step="0.01" min="0" oninput="calculateDue()" required>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">Due</label>
            <input type="number" id="due_amount" class="form-control" placeholder="0.00" readonly>
        </div>
    </div>

    <div class="mb-2"><input type="text" name="remarks" class="form-control" placeholder="Remarks (optional)"></div>
    <button type="submit" name="add_sale" class="btn btn-primary">💾 Save Sale</button>
</form>
</div>
</div>

<!-- Search + Export -->
<div class="d-flex mb-3">
<form method="get" class="flex-grow-1 me-2 d-flex">
    <input type="text" name="search" class="form-control" placeholder="Search anything in table" value="<?= htmlspecialchars($search) ?>">
    <button type="submit" class="btn btn-secondary ms-2">🔍 Search</button>
</form>
<a href="sales.php?export=xls<?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-success ms-2">⬇️ Export XLS</a>
</div>

<!-- Print buttons -->
<?php if (!empty($savedSales)): ?>
<div class="mb-3">
    <button class="btn btn-info"
        onclick='printCombinedSlip(<?= json_encode(json_encode($savedSales), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
        🖨 Print Sale Slip
    </button>
    <a href="sales.php" class="btn btn-outline-secondary ms-2">Close</a>
</div>
<?php endif; ?>

<?php if (empty($savedSales) && !empty($printSaleGroup)): ?>
<div class="mb-3">
    <button class="btn btn-info"
        onclick='printCombinedSlip(<?= json_encode(json_encode($printSaleGroup), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
        🖨 Print Sale Slip (<?= htmlspecialchars($printSaleId) ?>)
    </button>
</div>
<?php endif; ?>

<!-- Sales Table -->
<div class="card mt-3">
<div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-striped mb-0">
        <thead class="table-dark">
        <tr>
        <th>Bill Number</th>
        <th>Customer</th>
        <th>Phone</th>
        <th>Copy Category</th>
        <th>Description</th>
        <th>Price/Dozen</th>
        <th>Sold Dozen</th>
        <th>Sold Units</th>
        <th>Gross Price</th>
        <th>Discount %</th>
        <th>Discount Amt</th>
        <th>Net Amount</th>
        <th>Date</th>
        <th class="no-print">Action</th>
        </tr>
        </thead>
        <tbody>
        <?php if(count($filteredSales) > 0): ?>
        <?php foreach($filteredSales as $s): ?>
        <tr>
        <td><?= htmlspecialchars($s['sale_id'] ?? '-') ?></td>
        <td><?= htmlspecialchars($s['customer_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($s['customer_phone'] ?? '') ?></td>
        <td><?= htmlspecialchars($s['copy_category'] ?? '') ?></td>
        <td><?= htmlspecialchars($s['bulk_qty'] ?? '') ?></td>
        <td>₹<?= number_format($s['price_per_dozen'] ?? 0, 2) ?></td>
        <td><?= (int)($s['sold_dozen'] ?? 0) ?> dz</td>
        <td><?= (int)($s['sold_unit'] ?? 0) ?></td>
        <td>₹<?= number_format($s['gross_price'] ?? 0, 2) ?></td>
        <td><?= number_format($s['discount'] ?? 0, 1) ?>%</td>
        <td>₹<?= number_format($s['discount_amount'] ?? 0, 2) ?></td>
        <td>₹<?= number_format($s['total_price'] ?? 0, 2) ?></td>
        <td><?= date('d-M-Y', strtotime($s['date'] ?? '')) ?></td>
        <td class="no-print">
            <a href="sales.php?delete=<?= urlencode($s['id']) ?>"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Delete this sale? Stock will be restored.')">
               Delete
            </a>
        </td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="14" class="text-center">No sales records found.</td></tr>
        <?php endif; ?>
        </tbody>
        </table>
    </div>
</div>
</div>

</div>
<?php include 'footer.php'; ?>
</body>
</html>
