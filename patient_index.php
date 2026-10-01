<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';


require_role('patient');


/*
|--------------------------------------------------------------------------
| GET CURRENT PATIENT
|--------------------------------------------------------------------------
*/

$stmt = db()->prepare(

    'SELECT

        p.*,

        u.full_name,

        u.email

     FROM patients p

     JOIN users u

        ON u.id = p.user_id

     WHERE p.user_id = ?'

);


$stmt->execute([

    $_SESSION['user_id']

]);


$patient =
    $stmt->fetch();


/*
|--------------------------------------------------------------------------
| GET PATIENT INVOICES
|--------------------------------------------------------------------------
*/

$stmt = db()->prepare(

    'SELECT

        id,

        description,

        amount,

        COALESCE(payments.paid_amount, 0) AS amount_paid,

        status,

        created_at,

        paid_at

     FROM invoices

      LEFT JOIN (

          SELECT invoice_id, SUM(amount) AS paid_amount

          FROM invoice_payments

          WHERE voided_at IS NULL

          GROUP BY invoice_id

      ) payments ON payments.invoice_id = invoices.id

     WHERE patient_id =

        (

            SELECT id

            FROM patients

            WHERE user_id = ?

        )

     ORDER BY created_at DESC'

);


$stmt->execute([

    $_SESSION['user_id']

]);


$invoices =
    $stmt->fetchAll();

$amountPaid = 0.0;
$balanceRemaining = 0.0;

foreach ($invoices as $invoice) {
    $amountPaid += (float)$invoice['amount_paid'];

    if ($invoice['status'] === 'unpaid') {
        $balanceRemaining += max(
            (float)$invoice['amount'] - (float)$invoice['amount_paid'],
            0
        );
    }
}

?>

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
Patient Portal
</title>

<link
    rel="stylesheet"
    href="style.css"
>

</head>


<body>


<nav>

<strong>
Patient Portal
</strong>


<span>

<?= e($_SESSION['full_name']) ?>

|

<a href="change_password.php">
Change Password
</a>

|

<a href="logout.php">
Logout
</a>

</span>

</nav>


<main class="wide">


<h2>
My Profile
</h2>


<div class="panel">

<p>

<strong>
Date of Birth:
</strong>

<?= e(

$patient['date_of_birth']
?? ''

) ?>

</p>


<p>

<strong>
Name:
</strong>

<?= e(

$patient['full_name']
?? $_SESSION['full_name']

) ?>

</p>


<p>

<strong>
Email:
</strong>

<?= e(

$patient['email']
?? ''

) ?>

</p>


<p>

<strong>
Phone:
</strong>

<?= e(

$patient['phone']
?? ''

) ?>

</p>


<p>

<strong>
Address:
</strong>

<?= e(

$patient['address']
?? ''

) ?>

</p>


<p>

<strong>
Emergency Contact:
</strong>

<?= e(

$patient['emergency_contact']
?? ''

) ?>

</p>

</div>


<h2>
My Invoices
</h2>


<div class="grid">
    <div class="panel">
        <strong>Amount Paid</strong><br>
        GHS <?= number_format($amountPaid, 2) ?>
    </div>
    <div class="panel">
        <strong>Balance Left to Pay</strong><br>
        GHS <?= number_format($balanceRemaining, 2) ?>
    </div>
</div>

<?php if ($balanceRemaining > 0): ?>
<div class="alert warning">
    You have an outstanding balance of GHS <?= number_format($balanceRemaining, 2) ?>. Please contact the accounts office to arrange payment.
</div>
<?php endif; ?>


<table>

<tr>

<th>
Description
</th>

<th>
Amount
</th>

<th>
Paid
</th>

<th>
Balance
</th>

<th>
Status
</th>

<th>
Date
</th>

</tr>


<?php foreach ($invoices as $invoice): ?>

<tr>

<td>

<?= e(
    $invoice['description']
) ?>

</td>

<td>
GHS <?= number_format(
    (float)$invoice['amount'],
    2
) ?>
</td>

<td>
GHS <?= number_format(
    (float)$invoice['amount_paid'],
    2
) ?>
</td>

<td>
GHS <?= number_format(
    $invoice['status'] === 'unpaid'
        ? max((float)$invoice['amount'] - (float)$invoice['amount_paid'], 0)
        : 0,
    2
) ?>
</td>

<td>

<?= e(
    $invoice['status']
) ?>

</td>

<td>

<?= e(
    $invoice['created_at']
) ?>

</td>

</tr>

<?php endforeach; ?>

</table>


</main>

</body>

</html>