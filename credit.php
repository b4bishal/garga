<?php
// credit.php

$creditFile = __DIR__ . "/credit.json";
$salesFile = __DIR__ . "/sales.json";
$paymentsFile = __DIR__ . "/payments.json";

// Load credits data
$credits = file_exists($creditFile) ? json_decode(file_get_contents($creditFile), true) : [];
if (!is_array($credits)) $credits = [];

// Load sales data (for printing bills)
$sales = file_exists($salesFile) ? json_decode(file_get_contents($salesFile), true) : [];
if (!is_array($sales)) $sales = [];

// Load payments data
$payments = file_exists($paymentsFile) ? json_decode(file_get_contents($paymentsFile), true) : [];
if (!is_array($payments)) $payments = [];

// Success/Error messages
$message = '';
$messageType = '';
$lastPaymentReceipt = null;

// =========================
// Function to generate Payment ID
function generatePaymentId($payments) {
    $year = date("Y");
    $prefix = "PMT-" . $year . "-";
    
    // Find the highest number for current year
    $maxNum = 0;
    foreach ($payments as $payment) {
        $paymentId = $payment['payment_id'] ?? '';
        if (strpos($paymentId, $prefix) === 0) {
            $num = (int)substr($paymentId, strlen($prefix));
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }
    
    $newNum = $maxNum + 1;
    return $prefix . str_pad($newNum, 6, '0', STR_PAD_LEFT);
}

// =========================
// Handle Customer Payment (Distributed across multiple bills)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_payment'])) {
    $customerName = trim($_POST['customer_name'] ?? '');
    $paymentAmount = max(0, (float)($_POST['payment_amount'] ?? 0));
    $discountAmount = max(0, (float)($_POST['discount_amount'] ?? 0));
    $settlementAmount = $paymentAmount + $discountAmount;
    $paymentDate = date("Y-m-d H:i:s");

    if ($customerName === '' || $settlementAmount <= 0) {
        $message = "❌ Invalid payment or discount amount.";
        $messageType = "danger";
    } else {
        // Get all dues for this customer, sorted by date (oldest first)
        $customerDues = array_filter($credits, fn($c) => ($c['customer_name'] ?? '') === $customerName && ($c['due_amount'] ?? 0) > 0);
        usort($customerDues, fn($a, $b) => strtotime($a['date'] ?? '') - strtotime($b['date'] ?? ''));
        
        if (empty($customerDues)) {
            $message = "❌ No pending dues for this customer.";
            $messageType = "danger";
        } else {
            $customerTotalDue = array_sum(array_map(fn($c) => (float)($c['due_amount'] ?? 0), $customerDues));
            if ($settlementAmount > $customerTotalDue) {
                $message = "❌ Payment + discount cannot exceed total pending due.";
                $messageType = "danger";
            } else {
            $remainingPayment = $paymentAmount;
            $remainingDiscount = $discountAmount;
            $paymentDistribution = [];
            
            // Generate Payment ID
            $paymentId = generatePaymentId($payments);
            
            // Distribute payment across dues (oldest first)
            foreach ($customerDues as $index => $due) {
                $saleId = $due['sale_id'] ?? '';
                $currentDue = (float)($due['due_amount'] ?? 0);
                
                if (($remainingPayment + $remainingDiscount) <= 0) break;

                $amountToPay = min($remainingPayment, $currentDue);
                $remainingPayment -= $amountToPay;

                $dueAfterPayment = $currentDue - $amountToPay;
                $discountToApply = min($remainingDiscount, $dueAfterPayment);
                $remainingDiscount -= $discountToApply;

                $totalAdjusted = $amountToPay + $discountToApply;
                if ($totalAdjusted <= 0) continue;

                // Update credit record
                foreach ($credits as &$credit) {
                    if (($credit['sale_id'] ?? '') === $saleId) {
                        $oldDue = (float)$credit['due_amount'];
                        $credit['paid_amount'] = (float)($credit['paid_amount'] ?? 0) + $amountToPay;
                        $credit['discount_amount'] = (float)($credit['discount_amount'] ?? 0) + $discountToApply;
                        $credit['due_amount'] = max(0, (float)($credit['due_amount'] ?? 0) - $totalAdjusted);                        
                        // Add to payment history
                        if (!isset($credit['payment_history'])) {
                            $credit['payment_history'] = [];
                        }
                        
                        $credit['payment_history'][] = [
                            'payment_id' => $paymentId,
                            'amount' => $amountToPay,
                            'discount' => $discountToApply,
                            'settled_amount' => $totalAdjusted,
                            'date' => $paymentDate,
                            'previous_due' => $oldDue,
                            'remaining_due' => $credit['due_amount'],
                            'type' => $discountToApply > 0 ? 'payment_with_discount' : 'partial_payment',
                            'payment_mode' => 'customer_payment'
                        ];
                        
                        $paymentDistribution[] = [
                            'sale_id' => $saleId,
                            'original_due' => $currentDue,
                            'paid' => $amountToPay,
                            'discount' => $discountToApply,
                            'settled_amount' => $totalAdjusted,
                            'remaining_due' => $credit['due_amount'],
                            'date' => $credit['date'] ?? ''
                        ];
                        
                        break;
                    }
                }
            }
            
            if (!empty($paymentDistribution)) {
                // Save credits
                file_put_contents($creditFile, json_encode($credits, JSON_PRETTY_PRINT));
                
                // Record payment in payments.json
                $payments[] = [
                    'payment_id' => $paymentId,
                    'customer_name' => $customerName,
                    'customer_phone' => $customerDues[0]['customer_phone'] ?? '',
                    'total_amount' => $paymentAmount,
                    'discount_amount' => $discountAmount,
                    'settled_amount' => $settlementAmount,
                    'date' => $paymentDate,
                    'bills_affected' => array_column($paymentDistribution, 'sale_id'),
                    'distribution' => $paymentDistribution
                ];
                file_put_contents($paymentsFile, json_encode($payments, JSON_PRETTY_PRINT));
                
                // Get all remaining dues for receipt
                $allRemainingDues = array_filter($credits, fn($c) => ($c['customer_name'] ?? '') === $customerName && ($c['due_amount'] ?? 0) > 0);
                
                $lastPaymentReceipt = [
                    'payment_id' => $paymentId,
                    'customer_name' => $customerName,
                    'customer_phone' => $customerDues[0]['customer_phone'] ?? '',
                    'payment_date' => $paymentDate,
                    'total_payment' => $paymentAmount,
                    'total_discount' => $discountAmount,
                    'total_settled' => $settlementAmount,
                    'distribution' => $paymentDistribution,
                    'remaining_dues' => array_values($allRemainingDues)
                ];
                
                $message = "✅ Payment ID: <strong>$paymentId</strong> - Payment ₹" . number_format($paymentAmount, 2) . " + Discount ₹" . number_format($discountAmount, 2) . " recorded successfully and distributed across " . count($paymentDistribution) . " bill(s)!";
                $messageType = "success";
            }
            }
        }
    }
}

// =========================
// Filter only entries with due > 0
$creditData = array_filter($credits, fn($c) => ($c['due_amount'] ?? 0) > 0);

// Sort by date (newest first)
usort($creditData, fn($a, $b) => strtotime($b['date'] ?? '') - strtotime($a['date'] ?? ''));

// Summary totals should use all credit records, not only pending dues.
// This keeps collected amount visible even after a customer's due is fully cleared.
$totalOutstanding = array_sum(array_map(fn($c) => (float)($c['due_amount'] ?? 0), $credits));
$totalCollectedTillDate = array_sum(array_map(fn($c) => (float)($c['paid_amount'] ?? 0), $credits));

// =========================
// Handle Search
$search = trim($_GET['search'] ?? '');
$filteredData = $creditData;
if ($search !== '') {
    $filteredData = array_filter($creditData, function($c) use ($search) {
        return stripos($c['customer_name'] ?? '', $search) !== false ||
               stripos($c['sale_id'] ?? '', $search) !== false ||
               stripos($c['customer_phone'] ?? '', $search) !== false;
    });
}

// Group by customer name for search results
$groupedByCustomer = [];
foreach ($filteredData as $c) {
    $name = $c['customer_name'] ?? 'Unknown';
    if (!isset($groupedByCustomer[$name])) {
        $groupedByCustomer[$name] = [];
    }
    $groupedByCustomer[$name][] = $c;
}

// Check if search results show same customer (one or more bills)
$showCustomerPayment = false;
$customerForPayment = '';
if ($search !== '' && count($groupedByCustomer) === 1) {
    $customerForPayment = array_key_first($groupedByCustomer);
    // Show button even for single bill
    if (count($groupedByCustomer[$customerForPayment]) >= 1) {
        $showCustomerPayment = true;
    }
}

// =========================
// Export XLS
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $filename = "credits_" . date("Ymd_His") . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF";
    
    echo "Bill Number\tCustomer Name\tPhone\tEmail\tDate\tTotal Bill\tPaid Amount\tDiscount Given\tDue Amount\n";
    foreach ($filteredData as $c) {
        echo ($c['sale_id'] ?? '') . "\t" .
             ($c['customer_name'] ?? '') . "\t" .
             ($c['customer_phone'] ?? '') . "\t" .
             ($c['customer_email'] ?? '') . "\t" .
             ($c['date'] ?? '') . "\t" .
             ($c['total_bill'] ?? 0) . "\t" .
             ($c['paid_amount'] ?? 0) . "\t" .
             ($c['discount_amount'] ?? 0) . "\t" .
             ($c['due_amount'] ?? 0) . "\n";
    }
    exit();
}

include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Management - Garga Copy Udhyog</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
.table-focus th, .table-focus td { vertical-align: middle; }
.due-highlight { color: #dc3545; font-weight: 600; }
.modal-body { max-height: 70vh; overflow-y: auto; }
@media print {
    body * { visibility: hidden; }
    #printReceipt, #printReceipt * { visibility: visible; }
    #printReceipt { position: absolute; top: 0; left: 0; width: 100%; }
}
.badge-initial { background-color: #0d6efd; }
.badge-partial { background-color: #28a745; }
.payment-history-card { background-color: #f8f9fa; }
</style>
</head>

<body class="bg-light">
<div class="container my-4">
<h2 class="mb-4">💳 Credit Management</h2>

<?php if($message): ?>
<div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
    <?= $message ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Show Print Slip Button after payment -->
<?php if ($lastPaymentReceipt): ?>
<div class="alert alert-success d-flex align-items-center gap-2 flex-wrap">
    <strong>Payment saved.</strong>
    <span>You can print the customer slip now:</span>
    <button class="btn btn-info btn-lg fw-bold" onclick='printPaymentReceipt(<?= json_encode($lastPaymentReceipt, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
        🖨️ Print Slip
    </button>
    <a href="credit.php" class="btn btn-outline-secondary ms-2">Close</a>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card bg-danger text-white">
            <div class="card-body">
                <h5 class="card-title">Total Outstanding</h5>
                <h3>₹<?= number_format($totalOutstanding, 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5 class="card-title">Total Customers</h5>
                <h3><?= count($creditData) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h5 class="card-title">Total Collected</h5>
                <h3>₹<?= number_format($totalCollectedTillDate, 2) ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Search + Export + Search Payment -->
<div class="d-flex mb-3">
    <form method="get" class="flex-grow-1 me-2 d-flex">
        <input type="text" name="search" class="form-control" placeholder="Search by customer name, bill number, or phone" value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-secondary ms-2">🔍 Search</button>
    </form>
    <button class="btn btn-warning me-2" data-bs-toggle="modal" data-bs-target="#searchPaymentModal">
        🔍 Search Payment
    </button>
    <a href="credit.php?export=xls<?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn btn-success">⬇️ Export XLS</a>
</div>

<!-- Show Customer Payment Button when one customer is found (single or multiple bills) -->
<?php if ($showCustomerPayment): ?>
<div class="alert alert-info d-flex align-items-center">
    <button class="btn btn-primary me-3" 
        data-bs-toggle="modal" 
        data-bs-target="#customerPaymentModal"
        onclick='openCustomerPaymentModal(<?= json_encode($groupedByCustomer[$customerForPayment], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
        💰 Make Payment
    </button>
    <div>
        <strong>Customer Found:</strong> <?= htmlspecialchars($customerForPayment) ?><br>
        <small>This customer has <?= count($groupedByCustomer[$customerForPayment]) ?> pending bill(s) with total due: 
            ₹<?= number_format(array_sum(array_column($groupedByCustomer[$customerForPayment], 'due_amount')), 2) ?></small>
    </div>
</div>
<?php endif; ?>

<!-- Credit Table -->
<div class="card">
<div class="card-body p-0">
    <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 table-focus">
        <thead class="table-dark">
        <tr>
            <th>Customer Name</th>
            <th>Bill Number</th>
            <th>Phone</th>
            <th>Date</th>
            <th>Total Bill</th>
            <th>Paid</th>
            <th>Discount</th>
            <th>Due</th>
        </tr>
        </thead>
        <tbody>
        <?php if(count($filteredData) > 0): ?>
        <?php foreach($filteredData as $c): ?>
        <tr>
            <td><strong><?= htmlspecialchars($c['customer_name'] ?? '') ?></strong></td>
            <td><?= htmlspecialchars($c['sale_id'] ?? '') ?></td>
            <td><?= htmlspecialchars($c['customer_phone'] ?? '') ?></td>
            <td><?= date('d-M-Y', strtotime($c['date'] ?? '')) ?></td>
            <td>₹<?= number_format($c['total_bill'] ?? 0, 2) ?></td>
            <td>₹<?= number_format($c['paid_amount'] ?? 0, 2) ?></td>
            <td>₹<?= number_format($c['discount_amount'] ?? 0, 2) ?></td>
            <td class="due-highlight">₹<?= number_format($c['due_amount'] ?? 0, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr><td colspan="8" class="text-center py-4">
            <?php if($search): ?>
                No credit records found matching your search.
            <?php else: ?>
                🎉 No pending credits! All customers have cleared their dues.
            <?php endif; ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
        </table>
    </div>
</div>
</div>

</div>

<!-- Customer Payment Modal -->
<div class="modal fade" id="customerPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">💰 Customer Payment - <span id="cust_modal_name"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Customer Summary -->
        <div class="card mb-3 bg-light">
          <div class="card-body">
            <div class="row">
              <div class="col-md-6">
                <p><strong>Customer:</strong> <span id="cust_name"></span></p>
                <p><strong>Phone:</strong> <span id="cust_phone"></span></p>
              </div>
              <div class="col-md-6">
                <p><strong>Total Bills:</strong> <span id="cust_bill_count"></span></p>
                <p><strong>Total Outstanding:</strong> <span class="text-danger fw-bold">₹<span id="cust_total_due"></span></span></p>
              </div>
            </div>
          </div>
        </div>

        <!-- Bill-wise Due List -->
        <div class="card mb-3">
          <div class="card-header">📋 Pending Bills (Oldest First)</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm table-bordered" id="customer_bills_table">
                <thead class="table-light">
                  <tr>
                    <th>Bill Number</th>
                    <th>Date</th>
                    <th>Total Bill</th>
                    <th>Already Paid</th>
                    <th>Discount Given</th>
                    <th>Due Amount</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="customer_bills_tbody">
                </tbody>
                <tfoot>
                  <tr class="fw-bold">
                    <td colspan="5" class="text-end">Total Due:</td>
                    <td class="text-danger">₹<span id="table_total_due"></span></td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>

        <!-- Payment History Section -->
        <div class="card mb-3 payment-history-card">
          <div class="card-header bg-secondary text-white">
            📜 Payment History
          </div>
          <div class="card-body" id="payment_history_section">
            <p class="text-muted">No payment history available.</p>
          </div>
        </div>

        <!-- Payment Form -->
        <div class="card">
          <div class="card-header bg-success text-white">💵 Make Payment</div>
          <div class="card-body">
            <form method="post" id="customerPaymentForm">
              <input type="hidden" name="customer_name" id="form_customer_name">
              
              <div class="row">
                <div class="col-md-4">
                  <label class="form-label fw-bold">Payment Amount (₹)</label>
                  <input type="number" name="payment_amount" id="cust_payment_amount" class="form-control form-control-lg" 
                         placeholder="Enter payment amount" step="0.01" min="0" value="0">
                  <small class="text-muted">Cash/customer paid amount</small>
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold">Discount Given (₹)</label>
                  <input type="number" name="discount_amount" id="cust_discount_amount" class="form-control form-control-lg" 
                         placeholder="Enter discount amount" step="0.01" min="0" value="0">
                  <small class="text-muted">Discount reduces due but is not cash collection</small>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-success w-100 me-2" onclick="payFullCustomer()">
                    💵 Pay Full Amount
                  </button>
                </div>
              </div>
              
              <div class="mt-3">
                <button type="submit" name="customer_payment" class="btn btn-primary btn-lg">
                  ✅ Record Payment
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Search Payment Modal -->
<div class="modal fade" id="searchPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title">🔍 Search Payment History</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Search Form -->
        <div class="card mb-3">
          <div class="card-body">
            <div class="input-group">
              <input type="text" id="payment_search_input" class="form-control form-control-lg" 
                     placeholder="Search by Payment ID or Customer Name">
              <button class="btn btn-primary" onclick="searchPayments()">
                🔍 Search
              </button>
            </div>
          </div>
        </div>

        <!-- Search Results -->
        <div id="payment_search_results">
          <div class="text-center text-muted py-4">
            <p>Enter Payment ID or Customer Name to search</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Hidden print area -->
<div id="printReceipt" style="display:none;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let customerBillsData = [];
let customerTotalDue = 0;
const salesData = <?= json_encode($sales, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const creditsData = <?= json_encode($credits, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const paymentsData = <?= json_encode($payments, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

function openCustomerPaymentModal(bills) {
    // Sort bills by date (oldest first)
    bills.sort((a, b) => new Date(a.date) - new Date(b.date));
    
    customerBillsData = bills;
    const customerName = bills[0]?.customer_name || 'Unknown';
    const customerPhone = bills[0]?.customer_phone || '-';
    
    let totalDue = 0;
    let tableRows = '';
    let paymentHistoryHtml = '';
    
    bills.forEach(bill => {
        const due = parseFloat(bill.due_amount || 0);
        totalDue += due;
        
        // Check if there's initial payment
        const hasInitialPayment = (bill.payment_history || []).some(h => h.type === 'initial');
        const initialBadge = hasInitialPayment ? '<span class="badge badge-initial ms-2" style="font-size:10px;">Initial Payment</span>' : '';
        
        tableRows += `<tr>
            <td>${bill.sale_id || '-'}${initialBadge}</td>
            <td>${new Date(bill.date).toLocaleDateString()}</td>
            <td>₹${parseFloat(bill.total_bill || 0).toFixed(2)}</td>
            <td>₹${parseFloat(bill.paid_amount || 0).toFixed(2)}</td>
            <td>₹${parseFloat(bill.discount_amount || 0).toFixed(2)}</td>
            <td class="text-danger fw-bold">₹${due.toFixed(2)}</td>
            <td>
                <button class="btn btn-info btn-sm" onclick='printSingleBill("${bill.sale_id}")'>
                    🖨️ Print
                </button>
            </td>
        </tr>`;
        
        // Build payment history for this bill
        const history = bill.payment_history || [];
        if (history.length > 0) {
            paymentHistoryHtml += `
            <div class="mb-3">
                <h6 class="fw-bold text-primary">Bill: ${bill.sale_id}</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Payment ID</th>
                                <th>Date & Time</th>
                                <th>Amount Paid</th>
                                <th>Discount</th>
                                <th>Total Settled</th>
                                <th>Previous Due</th>
                                <th>Remaining Due</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            history.forEach(h => {
                const paymentId = h.payment_id || '-';
                const paymentDate = new Date(h.date).toLocaleString();
                const amount = parseFloat(h.amount || 0).toFixed(2);
                const discount = parseFloat(h.discount || 0).toFixed(2);
                const settled = parseFloat(h.settled_amount || (parseFloat(h.amount || 0) + parseFloat(h.discount || 0))).toFixed(2);
                const prevDue = h.previous_due ? parseFloat(h.previous_due).toFixed(2) : '-';
                const remainingDue = parseFloat(h.remaining_due || 0).toFixed(2);
                
                let typeBadge = '';
                if (h.type === 'initial') {
                    typeBadge = '<span class="badge badge-initial">Initial Payment</span>';
                } else if (h.type === 'payment_with_discount') {
                    typeBadge = '<span class="badge bg-warning text-dark">Payment + Discount</span>';
                } else if (h.type === 'partial_payment' || h.payment_mode === 'customer_payment') {
                    typeBadge = '<span class="badge badge-partial">Partial Payment</span>';
                } else {
                    typeBadge = '<span class="badge bg-secondary">Additional</span>';
                }
                
                paymentHistoryHtml += `
                    <tr>
                        <td><strong>${paymentId}</strong></td>
                        <td>${paymentDate}</td>
                        <td class="text-success fw-bold">₹${amount}</td>
                        <td class="text-warning fw-bold">₹${discount}</td>
                        <td class="fw-bold">₹${settled}</td>
                        <td>${prevDue !== '-' ? '₹' + prevDue : prevDue}</td>
                        <td class="text-danger fw-bold">₹${remainingDue}</td>
                        <td>${typeBadge}</td>
                    </tr>`;
            });
            
            paymentHistoryHtml += `
                        </tbody>
                    </table>
                </div>
            </div>`;
        }
    });
    
    customerTotalDue = totalDue;
    
    document.getElementById('cust_modal_name').textContent = customerName;
    document.getElementById('cust_name').textContent = customerName;
    document.getElementById('cust_phone').textContent = customerPhone;
    document.getElementById('cust_bill_count').textContent = bills.length;
    document.getElementById('cust_total_due').textContent = totalDue.toFixed(2);
    document.getElementById('table_total_due').textContent = totalDue.toFixed(2);
    document.getElementById('customer_bills_tbody').innerHTML = tableRows;
    document.getElementById('form_customer_name').value = customerName;
    document.getElementById('cust_payment_amount').value = '0';
    document.getElementById('cust_discount_amount').value = '0';
    
    // Display payment history
    if (paymentHistoryHtml) {
        document.getElementById('payment_history_section').innerHTML = paymentHistoryHtml;
    } else {
        document.getElementById('payment_history_section').innerHTML = '<p class="text-muted">No payment history available yet.</p>';
    }
}

function payFullCustomer() {
    document.getElementById('cust_payment_amount').value = customerTotalDue.toFixed(2);
    document.getElementById('cust_discount_amount').value = '0';
}

// Validate payment
document.getElementById('customerPaymentForm')?.addEventListener('submit', function(e) {
    const paymentAmount = parseFloat(document.getElementById('cust_payment_amount').value || 0);
    const discountAmount = parseFloat(document.getElementById('cust_discount_amount').value || 0);
    const settlementAmount = paymentAmount + discountAmount;

    if (settlementAmount <= 0) {
        e.preventDefault();
        alert('❌ Please enter payment amount or discount amount.');
        return false;
    }

    if (settlementAmount > customerTotalDue) {
        e.preventDefault();
        alert(`❌ Payment + discount (₹${settlementAmount.toFixed(2)}) cannot exceed total due (₹${customerTotalDue.toFixed(2)}).`);
        return false;
    }

    return true;
});

// Search Payments Function
function searchPayments() {
    const searchTerm = document.getElementById('payment_search_input').value.trim().toLowerCase();
    const resultsDiv = document.getElementById('payment_search_results');
    
    if (searchTerm === '') {
        resultsDiv.innerHTML = '<div class="alert alert-warning">Please enter a search term</div>';
        return;
    }
    
    // Filter payments by payment ID or customer name
    const filteredPayments = paymentsData.filter(p => 
        (p.payment_id || '').toLowerCase().includes(searchTerm) ||
        (p.customer_name || '').toLowerCase().includes(searchTerm)
    );
    
    if (filteredPayments.length === 0) {
        resultsDiv.innerHTML = '<div class="alert alert-info">No payments found matching your search</div>';
        return;
    }
    
    // Sort by date (newest first)
    filteredPayments.sort((a, b) => new Date(b.date) - new Date(a.date));
    
    let resultHtml = `
    <div class="card">
        <div class="card-header bg-success text-white">
            <strong>Found ${filteredPayments.length} Payment(s)</strong>
        </div>
        <div class="card-body">`;
    
    filteredPayments.forEach(payment => {
        const paymentDate = new Date(payment.date).toLocaleString();
        const totalAmount = parseFloat(payment.total_amount || 0).toFixed(2);
        const discountAmount = parseFloat(payment.discount_amount || 0).toFixed(2);
        const settledAmount = parseFloat(payment.settled_amount || (parseFloat(payment.total_amount || 0) + parseFloat(payment.discount_amount || 0))).toFixed(2);
        
        resultHtml += `
        <div class="card mb-3 border-primary">
            <div class="card-header bg-light">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Payment ID:</strong> ${payment.payment_id}<br>
                        <strong>Customer:</strong> ${payment.customer_name}
                    </div>
                    <div class="col-md-6 text-end">
                        <strong>Date:</strong> ${paymentDate}<br>
                        <strong class="text-success">Payment: ₹${totalAmount}</strong><br>
                        <strong class="text-warning">Discount: ₹${discountAmount}</strong><br>
                        <strong>Total Settled: ₹${settledAmount}</strong>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <h6 class="text-primary">Bills Affected:</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Bill Number</th>
                                <th>Date</th>
                                <th>Original Due</th>
                                <th>Paid</th>
                                <th>Discount</th>
                                <th>Total Settled</th>
                                <th>Remaining Due</th>
                            </tr>
                        </thead>
                        <tbody>`;
        
        (payment.distribution || []).forEach(dist => {
            resultHtml += `
                            <tr>
                                <td>${dist.sale_id}</td>
                                <td>${new Date(dist.date).toLocaleDateString()}</td>
                                <td>₹${parseFloat(dist.original_due || 0).toFixed(2)}</td>
                                <td class="text-success fw-bold">₹${parseFloat(dist.paid || 0).toFixed(2)}</td>
                                <td class="text-warning fw-bold">₹${parseFloat(dist.discount || 0).toFixed(2)}</td>
                                <td class="fw-bold">₹${parseFloat(dist.settled_amount || (parseFloat(dist.paid || 0) + parseFloat(dist.discount || 0))).toFixed(2)}</td>
                                <td class="text-danger fw-bold">₹${parseFloat(dist.remaining_due || 0).toFixed(2)}</td>
                            </tr>`;
        });
        
        resultHtml += `
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-info btn-sm" onclick='printPaymentById("${payment.payment_id}")'>
                    🖨️ Print Receipt
                </button>
            </div>
        </div>`;
    });
    
    resultHtml += `
        </div>
    </div>`;
    
    resultsDiv.innerHTML = resultHtml;
}

// Print payment by ID
function printPaymentById(paymentId) {
    const payment = paymentsData.find(p => p.payment_id === paymentId);
    if (!payment) {
        alert('Payment not found');
        return;
    }
    
    // Get remaining dues at the time of payment
    const allRemainingDues = [];
    
    const receipt = {
        payment_id: payment.payment_id,
        customer_name: payment.customer_name,
        customer_phone: payment.customer_phone,
        payment_date: payment.date,
        total_payment: payment.total_amount,
        total_discount: payment.discount_amount || 0,
        total_settled: payment.settled_amount || (parseFloat(payment.total_amount || 0) + parseFloat(payment.discount_amount || 0)),
        distribution: payment.distribution,
        remaining_dues: allRemainingDues
    };
    
    printPaymentReceipt(receipt);
}

function printSingleBill(saleId) {
    // Get sales for this bill
    const billSales = salesData.filter(s => s.sale_id === saleId);
    const creditInfo = creditsData.find(c => c.sale_id === saleId);
    
    if (!billSales || billSales.length === 0) {
        alert('Bill data not found');
        return;
    }
    
    const first = billSales[0];
    const customer = first.customer_name || '';
    const email = first.customer_email || '';
    const phone = first.customer_phone || '';
    const customerPan = first.customer_pan || '';
    const address = first.customer_address || '';
    const date = first.date || '';
    const paidAmount = creditInfo ? parseFloat(creditInfo.paid_amount || 0) : 0;
    const dueAmount = creditInfo ? parseFloat(creditInfo.due_amount || 0) : 0;
    
    let rows = '';
    let totalGross = 0, totalDiscountAmt = 0, totalNet = 0;
    let sn = 1;
    
    billSales.forEach(s => {
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
            <td style="text-align:right">${soldDozen} dz</td>
            <td style="text-align:right">₹${ratePerDozen.toFixed(2)}/dz</td>
            <td style="text-align:right">₹${gross.toFixed(2)}</td>
            <td style="text-align:right">₹${discountAmt.toFixed(2)}</td>
            <td style="text-align:right">₹${net.toFixed(2)}</td>
        </tr>`;
    });
    
    const slip = `
    <div style="width: 85%; max-width: 900px; margin: 20px auto; font-family: Arial, sans-serif; font-size: 14px;">
        <div style="text-align: center; margin-bottom:15px;">
            <img src="assets/images/logo.png" style="height: 100px;"><br>
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
        
        <table style="width:100%; border-collapse: collapse; font-size: 14px; border:1px solid #ddd;">
            <thead style="background-color:#f8f9fa;">
                <tr>
                    <th style="border:1px solid #ddd; padding:8px; text-align:center;">S.N.</th>
                    <th style="border:1px solid #ddd; padding:8px;">Copy Category</th>
                    <th style="border:1px solid #ddd; padding:8px;">Description</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Quantity</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Rate</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Gross</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Discount</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Net</th>
                </tr>
            </thead>
            <tbody>
                ${rows}
            </tbody>
            <tfoot style="font-weight:700;">
                <tr style="background-color:#f8f9fa;">
                    <td colspan="5" style="border:1px solid #ddd; padding:8px; text-align:right;">Total</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right;">₹${totalGross.toFixed(2)}</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right;">₹${totalDiscountAmt.toFixed(2)}</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right;">₹${totalNet.toFixed(2)}</td>
                </tr>
                <tr style="font-weight:600;">
                    <td colspan="7" style="border:1px solid #ddd; padding:8px; text-align:right;">Paid Amount</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#28a745;">₹${paidAmount.toFixed(2)}</td>
                </tr>
                <tr style="font-weight:600;">
                    <td colspan="7" style="border:1px solid #ddd; padding:8px; text-align:right;">Due</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#dc3545;">₹${dueAmount.toFixed(2)}</td>
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
    
    const w = window.open('', 'PrintBill');
    w.document.write('<html><head><title>Print Bill</title>');
    w.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
    w.document.write('</head><body>' + slip + '</body></html>');
    w.document.close();
    w.print();
}

function printPaymentReceipt(receipt) {
    const paymentId = receipt.payment_id || '-';
    const customerName = receipt.customer_name || '-';
    const customerPhone = receipt.customer_phone || '-';
    const paymentDate = new Date(receipt.payment_date).toLocaleString();
    const totalPayment = parseFloat(receipt.total_payment || 0);
    const totalDiscount = parseFloat(receipt.total_discount || 0);
    const totalSettled = parseFloat(receipt.total_settled || (totalPayment + totalDiscount));
    const distribution = receipt.distribution || [];
    const remainingDues = receipt.remaining_dues || [];
    
    let rows = '';
    let totalOriginalDue = 0;
    let totalPaid = 0;
    let totalDiscountGiven = 0;
    let totalSettledAmount = 0;
    let totalRemainingDue = 0;
    let sn = 1;
    
    distribution.forEach(item => {
        const origDue = parseFloat(item.original_due || 0);
        const paid = parseFloat(item.paid || 0);
        const discount = parseFloat(item.discount || 0);
        const settled = parseFloat(item.settled_amount || (paid + discount));
        const remaining = parseFloat(item.remaining_due || 0);

        totalOriginalDue += origDue;
        totalPaid += paid;
        totalDiscountGiven += discount;
        totalSettledAmount += settled;
        totalRemainingDue += remaining;

        rows += `<tr>
            <td style="border:1px solid #ddd; padding:8px; text-align:center;">${sn++}</td>
            <td style="border:1px solid #ddd; padding:8px;">${item.sale_id || '-'}</td>
            <td style="border:1px solid #ddd; padding:8px;">${new Date(item.date).toLocaleDateString()}</td>
            <td style="border:1px solid #ddd; padding:8px; text-align:right;">₹${origDue.toFixed(2)}</td>
            <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#28a745; font-weight:600;">₹${paid.toFixed(2)}</td>
            <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#ffc107; font-weight:600;">₹${discount.toFixed(2)}</td>
            <td style="border:1px solid #ddd; padding:8px; text-align:right; font-weight:600;">₹${settled.toFixed(2)}</td>
            <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#dc3545; font-weight:600;">₹${remaining.toFixed(2)}</td>
        </tr>`;
    });
    
    // Remaining dues section
    let remainingRows = '';
    let grandTotalRemaining = 0;
    
    if (remainingDues.length > 0) {
        remainingRows = `
        <div style="margin-top:30px;">
            <h5 style="color:#dc3545; margin-bottom:15px;"> Remaining Outstanding Dues</h5>
            <table style="width:100%; border-collapse: collapse; font-size: 14px; border:1px solid #ddd;">
                <thead style="background-color:#f8f9fa;">
                    <tr>
                        <th style="border:1px solid #ddd; padding:8px; text-align:center;">S.N.</th>
                        <th style="border:1px solid #ddd; padding:8px;">Bill Number</th>
                        <th style="border:1px solid #ddd; padding:8px;">Date</th>
                        <th style="border:1px solid #ddd; padding:8px; text-align:right;">Due Amount</th>
                    </tr>
                </thead>
                <tbody>`;
        
        let rnSn = 1;
        remainingDues.forEach(rd => {
            const rdDue = parseFloat(rd.due_amount || 0);
            grandTotalRemaining += rdDue;
            remainingRows += `<tr>
                <td style="border:1px solid #ddd; padding:8px; text-align:center;">${rnSn++}</td>
                <td style="border:1px solid #ddd; padding:8px;">${rd.sale_id || '-'}</td>
                <td style="border:1px solid #ddd; padding:8px;">${new Date(rd.date).toLocaleDateString()}</td>
                <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#dc3545; font-weight:600;">₹${rdDue.toFixed(2)}</td>
            </tr>`;
        });
        
        remainingRows += `
                </tbody>
                <tfoot style="font-weight:700; background-color:#f8f9fa;">
                    <tr>
                        <td colspan="3" style="border:1px solid #ddd; padding:8px; text-align:right;">Total Remaining Due</td>
                        <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#dc3545;">₹${grandTotalRemaining.toFixed(2)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>`;
    }
    
    const receiptHtml = `
    <div style="width: 85%; max-width: 900px; margin: 20px auto; font-family: Arial, sans-serif; font-size: 14px;">
        <div style="text-align: center; margin-bottom:15px;">
            <img src="assets/images/logo.png" style="height: 100px;"><br>
            <strong style="font-size: 22px;">Garga Copy Udhyog</strong><br>
            <span style="font-size: 16px;">Payment Receipt</span><br>
            <span style="font-size: 14px;">PAN: 621544707</span>
            <hr style="margin-top:8px; margin-bottom:15px;">
        </div>
        
        <div style="display: flex; justify-content: space-between; margin-bottom:15px; font-size:15px;">
            <div style="flex: 1;">
                <strong>Payment ID:</strong> ${paymentId}<br>
                <strong>Customer:</strong> ${customerName}<br>
                <strong>Phone:</strong> ${customerPhone}<br>
                <strong>Payment Date:</strong> ${paymentDate}
            </div>
            <div style="flex: 1; text-align:right;">
                <strong style="font-size:18px;">Payment Received</strong><br>
                <span style="color:#28a745; font-weight:700; font-size:22px;">₹${totalPayment.toFixed(2)}</span><br>
                <strong>Discount Given:</strong> ₹${totalDiscount.toFixed(2)}<br>
                <strong>Total Settled:</strong> ₹${totalSettled.toFixed(2)}
            </div>
        </div>
        
        <h5 style="margin-top:20px; margin-bottom:15px;">Payment Distribution</h5>
        <table style="width:100%; border-collapse: collapse; font-size: 14px; border:1px solid #ddd;">
            <thead style="background-color:#f8f9fa;">
                <tr>
                    <th style="border:1px solid #ddd; padding:8px; text-align:center;">S.N.</th>
                    <th style="border:1px solid #ddd; padding:8px;">Bill Number</th>
                    <th style="border:1px solid #ddd; padding:8px;">Date</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Original Due</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Paid Now</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Discount</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Total Settled</th>
                    <th style="border:1px solid #ddd; padding:8px; text-align:right;">Remaining Due</th>
                </tr>
            </thead>
            <tbody>
                ${rows}
            </tbody>
            <tfoot style="font-weight:700; background-color:#f8f9fa;">
                <tr>
                    <td colspan="3" style="border:1px solid #ddd; padding:8px; text-align:right;">Total</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right;">₹${totalOriginalDue.toFixed(2)}</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#28a745;">₹${totalPaid.toFixed(2)}</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#ffc107;">₹${totalDiscountGiven.toFixed(2)}</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right;">₹${totalSettledAmount.toFixed(2)}</td>
                    <td style="border:1px solid #ddd; padding:8px; text-align:right; color:#dc3545;">₹${totalRemainingDue.toFixed(2)}</td>
                </tr>
            </tfoot>
        </table>
        
        ${remainingRows}
        
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
        
        <p style="text-align:center; margin-top: 30px;">Thank you for your payment!</p>
    </div>`;
    
    const printArea = document.getElementById('printReceipt');
    printArea.innerHTML = receiptHtml;
    printArea.style.display = 'block';
    
    window.print();
    
    setTimeout(() => {
        printArea.style.display = 'none';
    }, 100);
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
