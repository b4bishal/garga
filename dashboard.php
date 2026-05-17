<?php
// Include navbar (session check & logout included)
include 'navbar.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Garga Copy Udhyog - Dashboard</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #e0f7fa, #f9fbe7);
    min-height: 100vh;
    margin: 0;
    font-family: 'Poppins', sans-serif;
}

/* Container grid */
.container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 25px;
    padding: 40px;
    max-width: 1200px;
    margin: auto;
}

/* Cards */
.card {
    background: linear-gradient(135deg, #ffffff, #f0fdfd);
    padding: 35px 20px;
    border-radius: 20px;
    text-align: center;
    font-weight: 600;
    font-size: 18px;
    color: #00796b;
    cursor: pointer;
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
    transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    min-height: 140px;
}
.card:hover {
    transform: translateY(-6px);
    box-shadow: 0 10px 28px rgba(0,0,0,0.15);
    background: linear-gradient(135deg, #e0f7fa, #b2ebf2);
}
.card i {
    font-size: 36px;
    margin-bottom: 12px;
    color: #26a69a;
}

/* Responsive */
@media(max-width:600px){
    .card { min-height: 120px; font-size: 16px; }
    header img { height: 60px; }
}
</style>
</head>
<body>

<div class="container">
    <div class="card" onclick="location.href='suppliers.php'"><i class="fa-solid fa-users"></i>Suppliers Management</div>
    <div class="card" onclick="location.href='customer.php'"><i class="fa-solid fa-users"></i>Customer Management</div>
    <div class="card" onclick="location.href='stock-name.php'"><i class="fa-solid fa-boxes-stacked"></i>Add New Stocks</div>
    <div class="card" onclick="location.href='stock.php'"><i class="fa-solid fa-boxes-stacked"></i>Stock Management (Raw Materials)</div>
    <div class="card" onclick="location.href='product-stock.php'"><i class="fa-solid fa-boxes-stacked"></i>Stock Management (Product)</div>
    <div class="card" onclick="location.href='sales.php'"><i class="fa-solid fa-cart-shopping"></i>Sales</div>
    <div class="card" onclick="location.href='credit.php'"><i class="fa-solid fa-file-invoice-dollar"></i>Credit Management</div>
    
    <div class="card" onclick="location.href='salaries.php'"><i class="fa-solid fa-money-bill-wave"></i>Salaries</div>
    <div class="card" onclick="location.href='financials.php'"><i class="fa-solid fa-wallet"></i>Financial</div>
    <div class="card" onclick="location.href='billings.php'"><i class="fa-solid fa-file-invoice-dollar"></i>Billings</div>
</div>


</body>

</html>
