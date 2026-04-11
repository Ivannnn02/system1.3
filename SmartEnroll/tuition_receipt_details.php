<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/mail/PHPMailer/mail_helper.php';

$paymentHistory = [];
$selectedStudent = null;
$selectedPayment = null;
$error = '';
$lastEmailError = '';
$selectedId = trim((string)($_GET['student_id'] ?? $_POST['student_id'] ?? ''));
$successMessage = $_SESSION['pay_tuition_success'] ?? '';
$warningMessage = $_SESSION['pay_tuition_warning'] ?? '';
unset($_SESSION['pay_tuition_success'], $_SESSION['pay_tuition_warning']);

$tuitionMap = [
    'Toddler' => 63340.00,
    'Casa' => 69732.00,
    'Brave' => 79226.00,
    'Kindergarten' => 71612.00,
    'Grade 1' => 72740.00,
    'Grade 2' => 72740.00,
    'Grade 3' => 74240.00,
];

$gradeBreakdownMap = [
    'Toddler' => [
        'Tuition Fee' => 57340.00,
        'Registration Fee & Miscellaneous' => 6000.00,
    ],
    'Casa' => [
        'Tuition Fee' => 69732.00,
    ],
    'Brave' => [
        'Tuition Fee' => 73226.00,
        'Registration Fee & Miscellaneous' => 6000.00,
    ],
    'Kindergarten' => [
        'Tuition Fee' => 65612.00,
        'Registration Fee & Miscellaneous' => 6000.00,
    ],
    'Grade 1' => [
        'Tuition Fee' => 66740.00,
        'Registration Fee & Miscellaneous' => 2500.00,
        'Books' => 3500.00,
    ],
    'Grade 2' => [
        'Tuition Fee' => 66740.00,
        'Registration Fee & Miscellaneous' => 2000.00,
        'Books' => 4000.00,
    ],
    'Grade 3' => [
        'Tuition Fee' => 66740.00,
        'Registration Fee & Miscellaneous' => 1000.00,
        'Books' => 5000.00,
    ],
];

function format_name(array $row): string
{
    $m = trim((string)($row['learner_mname'] ?? ''));
    $mi = $m !== '' ? strtoupper(mb_substr($m, 0, 1)) . '.' : '';
    $full = trim(
        ($row['learner_lname'] ?? '') . ', ' .
        ($row['learner_fname'] ?? '') . ' ' . $mi
    );

    return trim(preg_replace('/\s+/', ' ', $full), ' ,');
}

function format_money(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function is_loopback_host(string $host): bool
{
    $normalized = strtolower(trim($host));
    if ($normalized === '') {
        return true;
    }

    $normalized = preg_replace('/:\d+$/', '', $normalized);
    return in_array($normalized, ['localhost', '127.0.0.1', '::1'], true);
}

function build_app_url(string $path, array $query = []): string
{
    $config = get_email_config();
    $configuredBase = trim((string)($config['app_url'] ?? ''));
    if ($configuredBase !== '' && !is_loopback_host((string)parse_url($configuredBase, PHP_URL_HOST))) {
        $baseUrl = rtrim($configuredBase, '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $serverAddr = trim((string)($_SERVER['SERVER_ADDR'] ?? ''));

        if ($host === '') {
            $host = $serverAddr !== '' ? $serverAddr : 'localhost';
        }

        if (is_loopback_host($host) && $serverAddr !== '' && !is_loopback_host($serverAddr)) {
            $port = '';
            if (preg_match('/:(\d+)$/', $host, $portMatches)) {
                $port = ':' . $portMatches[1];
            }
            $host = $serverAddr . $port;
        }

        if (is_loopback_host($host) && $configuredBase !== '') {
            $configuredPath = trim((string)parse_url($configuredBase, PHP_URL_PATH));
            $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
            $scriptDir = $configuredPath !== '' ? rtrim($configuredPath, '/') : ($scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/'));
            $baseUrl = $scheme . '://' . $host . $scriptDir;
        } else {
            $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
            $scriptDir = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');
            $baseUrl = $scheme . '://' . $host . $scriptDir;
        }
    }

    if ($baseUrl === '') {
        $baseUrl = $configuredBase;
    }

    $url = $baseUrl . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function generate_payment_token(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return bin2hex(hash('sha256', uniqid('payment_', true), true));
    }
}

function get_student(mysqli $conn, string $studentId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, student_id, learner_lname, learner_fname, learner_mname, grade_level, school_year, email
         FROM enrollments
         WHERE student_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $student;
}

function get_school_year_paid_total(mysqli $conn, int $enrollmentId, string $schoolYear): float
{
    if ($enrollmentId <= 0) {
        return 0.0;
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
         FROM tuition_payments
         WHERE enrollment_id = ?
           AND COALESCE(school_year, '') = ?"
    );
    $stmt->bind_param('is', $enrollmentId, $schoolYear);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return round((float)($row['total_paid'] ?? 0), 2);
}

function backfill_tuition_balances(mysqli $conn): int
{
    $rows = [];
    $result = $conn->query(
        "SELECT id, enrollment_id, COALESCE(school_year, '') AS school_year, tuition_fee, amount_paid, balance_after, payment_date
         FROM tuition_payments
         ORDER BY enrollment_id ASC, school_year ASC, payment_date ASC, id ASC"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->close();
    }

    if (empty($rows)) {
        return 0;
    }

    $updated = 0;
    $runningByGroup = [];
    $updateStmt = $conn->prepare("UPDATE tuition_payments SET balance_after = ? WHERE id = ?");

    foreach ($rows as $row) {
        $enrollmentId = (int)($row['enrollment_id'] ?? 0);
        $schoolYear = (string)($row['school_year'] ?? '');
        $groupKey = $enrollmentId . '|' . $schoolYear;

        if (!array_key_exists($groupKey, $runningByGroup)) {
            $runningByGroup[$groupKey] = 0.0;
        }

        $runningByGroup[$groupKey] += (float)($row['amount_paid'] ?? 0);
        $tuitionFee = round((float)($row['tuition_fee'] ?? 0), 2);
        $computedBalance = max(0, round($tuitionFee - $runningByGroup[$groupKey], 2));
        $storedBalance = round((float)($row['balance_after'] ?? 0), 2);

        if (abs($computedBalance - $storedBalance) >= 0.01) {
            $rowId = (int)($row['id'] ?? 0);
            $updateStmt->bind_param('di', $computedBalance, $rowId);
            $updateStmt->execute();
            $updated++;
        }
    }

    $updateStmt->close();
    return $updated;
}

function parse_payment_items(string $rawJson, array $allowedOptions, array $feeDefaults, float $defaultTuitionAmount): array
{
    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Please add at least one payment row.');
    }

    $items = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $option = trim((string)($row['option'] ?? ''));
        if ($option === '') {
            continue;
        }

        if ($option === '' || !in_array($option, $allowedOptions, true)) {
            throw new RuntimeException('Please choose a valid payment item for every row.');
        }

        $amount = $option === 'Tuition Fee'
            ? round((float)($row['amount'] ?? 0), 2)
            : round((float)($feeDefaults[$option] ?? 0), 2);

        if ($amount <= 0) {
            if ($option === 'Tuition Fee') {
                throw new RuntimeException('Please enter a valid tuition fee amount.');
            }
            throw new RuntimeException('Please set a valid fixed amount for every selected payment item.');
        }

        $items[] = [
            'option' => $option,
            'label' => $option,
            'amount' => $amount,
        ];
    }

    if (empty($items)) {
        throw new RuntimeException('Please add at least one payment row.');
    }

    return $items;
}

function get_payment_total(array $items): float
{
    $total = 0.0;
    foreach ($items as $item) {
        $total += (float)($item['amount'] ?? 0);
    }

    return round($total, 2);
}

function sum_payment_history(array $historyRows): float
{
    $total = 0.0;
    foreach ($historyRows as $row) {
        $total += (float)($row['amount_paid'] ?? 0);
    }

    return round($total, 2);
}

function attach_running_balances(array $historyRows, float $programTotal): array
{
    usort($historyRows, static function (array $a, array $b): int {
        $dateCompare = strcmp((string)($a['payment_date'] ?? ''), (string)($b['payment_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    $paid = 0.0;
    foreach ($historyRows as &$row) {
        $paid += (float)($row['amount_paid'] ?? 0);
        $row['balance_after'] = max(0, round($programTotal - $paid, 2));
    }
    unset($row);

    usort($historyRows, static function (array $a, array $b): int {
        $dateCompare = strcmp((string)($b['payment_date'] ?? ''), (string)($a['payment_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    });

    return $historyRows;
}

function find_payment_by_id(array $historyRows, int $paymentId): ?array
{
    foreach ($historyRows as $row) {
        if ((int)($row['id'] ?? 0) === $paymentId) {
            return $row;
        }
    }

    return null;
}

function resolve_payment_balance_after(array $payment): float
{
    $storedBalance = round((float)($payment['balance_after'] ?? 0), 2);
    $tuitionFee = round((float)($payment['tuition_fee'] ?? 0), 2);
    $amountPaid = round((float)($payment['amount_paid'] ?? 0), 2);

    if ($storedBalance > 0 || $amountPaid >= $tuitionFee) {
        return max(0, $storedBalance);
    }

    return max(0, round($tuitionFee - $amountPaid, 2));
}

function decode_saved_payment_items(?string $rawJson, float $amountPaid): array
{
    $decoded = json_decode((string)$rawJson, true);
    if (!is_array($decoded) || empty($decoded)) {
        return [[
            'option' => 'Tuition Fee',
            'label' => 'Tuition Fee',
            'amount' => round($amountPaid, 2),
        ]];
    }

    $items = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string)($row['label'] ?? $row['option'] ?? ''));
        $option = trim((string)($row['option'] ?? ($label !== '' ? $label : 'Other')));
        $amount = round((float)($row['amount'] ?? 0), 2);

        if ($label === '' || $amount <= 0) {
            continue;
        }

        $items[] = [
            'option' => $option,
            'label' => $label,
            'amount' => $amount,
        ];
    }

    if (empty($items)) {
        $items[] = [
            'option' => 'Tuition Fee',
            'label' => 'Tuition Fee',
            'amount' => round($amountPaid, 2),
        ];
    }

    return $items;
}

function render_receipt_items_html(array $items): string
{
    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr>'
            . '<td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">' . htmlspecialchars((string)$item['label']) . '</td>'
            . '<td style="padding:10px;border:1px solid #e5e7eb;text-align:right;">' . htmlspecialchars(format_money((float)$item['amount'])) . '</td>'
            . '</tr>';
    }

    return $rows;
}

function render_receipt_items_text(array $items): string
{
    $lines = [];
    foreach ($items as $item) {
        $lines[] = '- ' . ($item['label'] ?? 'Payment Item') . ': ' . format_money((float)($item['amount'] ?? 0));
    }

    return implode("\r\n", $lines);
}

function send_receipt_email(array $student, array $payment): bool
{
    global $lastEmailError;

    $to = trim((string)($student['email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $lastEmailError = 'This student does not have a valid enrollment email address.';
        return false;
    }

    $studentName = format_name($student);
    $receiptNo = trim((string)($payment['receipt_no'] ?? '')) !== '' ? (string)$payment['receipt_no'] : 'N/A';
    $items = decode_saved_payment_items((string)($payment['payment_items'] ?? ''), (float)($payment['amount_paid'] ?? 0));
    $remainingBalance = resolve_payment_balance_after($payment);
    $subject = 'SMARTENROLL Tuition Breakdown - ' . ($student['student_id'] ?? '');
    $html = '
    <html>
    <body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,sans-serif;color:#1f2937;">
        <div style="max-width:720px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb;">
            <div style="padding:24px 28px;background:linear-gradient(135deg,#19325a,#1e88e5);color:#ffffff;">
                <h2 style="margin:0 0 8px;">Tuition Breakdown Receipt</h2>
                <p style="margin:0;font-size:14px;opacity:.92;">This billing breakdown was sent to the email entered on the enrollment form.</p>
            </div>
            <div style="padding:28px;">
                <p style="margin-top:0;">Good day,</p>
                <p>This is the tuition breakdown for <strong>' . htmlspecialchars($studentName) . '</strong>.</p>
                <table style="width:100%;border-collapse:collapse;margin:18px 0;">
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">Student ID</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars((string)($student['student_id'] ?? '')) . '</td></tr>
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">Student Name</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars($studentName) . '</td></tr>
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">Grade Level</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars((string)($student['grade_level'] ?? '')) . '</td></tr>
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">School Year</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars((string)($student['school_year'] ?? '')) . '</td></tr>
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">Payment Date</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars((string)($payment['payment_date'] ?? '')) . '</td></tr>
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">Receipt No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars($receiptNo) . '</td></tr>
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">Tuition Fee</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars(format_money((float)($payment['tuition_fee'] ?? 0))) . '</td></tr>
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">Total Breakdown</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars(format_money((float)($payment['amount_paid'] ?? 0))) . '</td></tr>
                    <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;">Remaining Balance</td><td style="padding:10px;border:1px solid #e5e7eb;">' . htmlspecialchars(format_money($remainingBalance)) . '</td></tr>
                </table>
                <h3 style="margin:24px 0 12px;font-size:18px;color:#19325a;">Payment Breakdown</h3>
                <table style="width:100%;border-collapse:collapse;">'
                    . render_receipt_items_html($items) .
                '</table>
                <p style="margin-bottom:0;margin-top:24px;">Thank you.<br>SMARTENROLL / Adreo Montessori Inc.</p>
            </div>
        </div>
    </body>
    </html>';

    $text = implode("\r\n", [
        'Tuition Breakdown Receipt',
        '',
        'Student ID: ' . ($student['student_id'] ?? ''),
        'Student Name: ' . $studentName,
        'Grade Level: ' . ($student['grade_level'] ?? ''),
        'School Year: ' . ($student['school_year'] ?? ''),
        'Payment Date: ' . ($payment['payment_date'] ?? ''),
        'Receipt No.: ' . $receiptNo,
        'Tuition Fee: ' . format_money((float)($payment['tuition_fee'] ?? 0)),
        'Total Breakdown: ' . format_money((float)($payment['amount_paid'] ?? 0)),
        'Remaining Balance: ' . format_money($remainingBalance),
        '',
        'Payment Breakdown:',
        render_receipt_items_text($items),
        '',
        'SMARTENROLL / Adreo Montessori Inc.',
    ]);

    return smtp_send_mail($to, $subject, $html, $text, $lastEmailError);
}

try {
    $conn = new mysqli('127.0.0.1', 'root', '', 'smartenroll');
    $conn->set_charset('utf8mb4');

    $conn->query(
        "CREATE TABLE IF NOT EXISTS tuition_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enrollment_id INT NOT NULL,
            student_id VARCHAR(100) NOT NULL,
            email VARCHAR(255) DEFAULT '',
            school_year VARCHAR(100) DEFAULT '',
            grade_level VARCHAR(100) DEFAULT '',
            payment_date DATE NOT NULL,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tuition_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            receipt_no VARCHAR(100) DEFAULT '',
            payment_note VARCHAR(255) DEFAULT '',
            payment_items LONGTEXT DEFAULT NULL,
            payment_token VARCHAR(64) DEFAULT '',
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_payment_token (payment_token),
            KEY idx_student_id (student_id),
            KEY idx_enrollment_id (enrollment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columnCheck = $conn->query("SHOW COLUMNS FROM tuition_payments LIKE 'payment_items'");
    if ($columnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE tuition_payments ADD COLUMN payment_items LONGTEXT DEFAULT NULL AFTER payment_note");
    }
    $columnCheck->close();

    $paymentTokenCheck = $conn->query("SHOW COLUMNS FROM tuition_payments LIKE 'payment_token'");
    if ($paymentTokenCheck->num_rows === 0) {
        $conn->query("ALTER TABLE tuition_payments ADD COLUMN payment_token VARCHAR(64) DEFAULT '' AFTER payment_items");
        $conn->query("ALTER TABLE tuition_payments ADD UNIQUE KEY uniq_payment_token (payment_token)");
    }
    $paymentTokenCheck->close();

    $emptyTokenResult = $conn->query("SELECT id FROM tuition_payments WHERE payment_token = '' OR payment_token IS NULL");
    if ($emptyTokenResult) {
        while ($tokenRow = $emptyTokenResult->fetch_assoc()) {
            $generatedToken = generate_payment_token();
            $tokenStmt = $conn->prepare("UPDATE tuition_payments SET payment_token = ? WHERE id = ?");
            $tokenStmt->bind_param('si', $generatedToken, $tokenRow['id']);
            $tokenStmt->execute();
            $tokenStmt->close();
        }
        $emptyTokenResult->close();
    }

    backfill_tuition_balances($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string)($_POST['action'] ?? 'save_payment'));
        if ($selectedId === '') {
            throw new RuntimeException('Please select a student first.');
        }

        $selectedStudent = get_student($conn, $selectedId);
        if (!$selectedStudent) {
            throw new RuntimeException('The selected student could not be found.');
        }

        if ($action === 'save_payment') {
            $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
            $receiptNo = trim((string)($_POST['receipt_no'] ?? ''));
            $paymentNote = trim((string)($_POST['payment_note'] ?? ''));
            $gradeLevel = trim((string)($selectedStudent['grade_level'] ?? ''));

            if (!array_key_exists($gradeLevel, $tuitionMap)) {
                throw new RuntimeException('No tuition fee is configured yet for this grade level.');
            }

            $tuitionFee = (float)$tuitionMap[$gradeLevel];
            $gradeFeeDefaults = $gradeBreakdownMap[$gradeLevel] ?? ['Tuition Fee' => $tuitionFee];
            $fixedFeeTotal = 0.0;
            foreach ($gradeFeeDefaults as $option => $defaultAmount) {
                if ($option !== 'Tuition Fee') {
                    $fixedFeeTotal += (float)$defaultAmount;
                }
            }

            $fullTuitionAmount = max(0, round($tuitionFee - $fixedFeeTotal, 2));
            $paymentConfig = $gradeFeeDefaults;
            $paymentConfig['Tuition Fee'] = $tuitionFee;

            $registrationKey = 'Registration Fee & Miscellaneous';
            if (array_key_exists($registrationKey, $paymentConfig)) {
                $catalogWithMonthly = [];
                foreach ($paymentConfig as $option => $amount) {
                    $catalogWithMonthly[$option] = $amount;
                    if ($option === $registrationKey) {
                        $catalogWithMonthly['Monthly Payment'] = round($fullTuitionAmount / 10, 2);
                    }
                }
                $paymentConfig = $catalogWithMonthly;
            } else {
                $paymentConfig['Monthly Payment'] = round($fullTuitionAmount / 10, 2);
            }

            $paymentOptions = array_keys($paymentConfig);
            $paymentItems = parse_payment_items(
                (string)($_POST['payment_items_json'] ?? ''),
                $paymentOptions,
                $paymentConfig,
                $tuitionFee
            );

            $paymentDateTime = DateTime::createFromFormat('Y-m-d', $paymentDate);
            if (!$paymentDateTime || $paymentDateTime->format('Y-m-d') !== $paymentDate) {
                throw new RuntimeException('Please enter a valid payment date.');
            }

            $amountPaid = get_payment_total($paymentItems);
            if ($amountPaid <= 0) {
                throw new RuntimeException('Please select at least one billing item.');
            }

            $selectedSchoolYear = trim((string)($selectedStudent['school_year'] ?? ''));
            $alreadyPaid = get_school_year_paid_total($conn, (int)$selectedStudent['id'], $selectedSchoolYear);
            $remainingBefore = max(0, round($tuitionFee - $alreadyPaid, 2));

            if ($remainingBefore <= 0) {
                throw new RuntimeException('Tuition is already fully paid for this school year.');
            }

            if ($amountPaid > $remainingBefore) {
                throw new RuntimeException('The entered amount exceeds the remaining balance of ' . format_money($remainingBefore) . '.');
            }

            $runningPaid = round($alreadyPaid + $amountPaid, 2);
            $balanceAfter = max(0, round($tuitionFee - $runningPaid, 2));
            $emailValue = trim((string)($selectedStudent['email'] ?? ''));
            $paymentItemsJson = json_encode($paymentItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $paymentToken = generate_payment_token();

            $insertStmt = $conn->prepare(
                "INSERT INTO tuition_payments (
                    enrollment_id, student_id, email, school_year, grade_level, payment_date,
                    amount_paid, tuition_fee, balance_after, receipt_no, payment_note, payment_items, payment_token, email_sent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
            );
            $insertStmt->bind_param(
                'isssssdddssss',
                $selectedStudent['id'],
                $selectedStudent['student_id'],
                $emailValue,
                $selectedStudent['school_year'],
                $selectedStudent['grade_level'],
                $paymentDate,
                $amountPaid,
                $tuitionFee,
                $balanceAfter,
                $receiptNo,
                $paymentNote,
                $paymentItemsJson,
                $paymentToken
            );
            $insertStmt->execute();
            $newPaymentId = (int)$insertStmt->insert_id;
            $insertStmt->close();

            $_SESSION['pay_tuition_success'] = 'Receipt created. You can now send it to ' . ($emailValue !== '' ? $emailValue : 'the registered enrollment email') . '.';
            if (!filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['pay_tuition_warning'] = 'The student has no valid email in the enrollment form, so the receipt cannot be sent yet.';
            }

            header('Location: tuition_receipt_details.php?student_id=' . urlencode($selectedId) . '&payment_id=' . $newPaymentId);
            exit;
        }

        if ($action === 'send_receipt') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                throw new RuntimeException('No saved payment receipt was selected.');
            }

            $paymentStmt = $conn->prepare(
                "SELECT id, student_id, payment_date, amount_paid, tuition_fee, balance_after, receipt_no, payment_note, payment_items, payment_token, email_sent
                 FROM tuition_payments
                 WHERE id = ? AND student_id = ?
                 LIMIT 1"
            );
            $paymentStmt->bind_param('is', $paymentId, $selectedId);
            $paymentStmt->execute();
            $payment = $paymentStmt->get_result()->fetch_assoc();
            $paymentStmt->close();

            if (!$payment) {
                throw new RuntimeException('The selected receipt could not be found.');
            }

            $studentEmail = trim((string)($selectedStudent['email'] ?? ''));
            if (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('This student does not have a valid email saved in the enrollment form.');
            }

            $sent = send_receipt_email($selectedStudent, $payment);
            if (!$sent) {
                throw new RuntimeException($lastEmailError ?: 'The receipt email could not be sent from this server.');
            }

            $updateStmt = $conn->prepare("UPDATE tuition_payments SET email_sent = 1, email = ? WHERE id = ?");
            $updateStmt->bind_param('si', $studentEmail, $paymentId);
            $updateStmt->execute();
            $updateStmt->close();

            $_SESSION['pay_tuition_success'] = 'Receipt sent to ' . $studentEmail . '.';
            header('Location: tuition_receipt_details.php?student_id=' . urlencode($selectedId) . '&payment_id=' . $paymentId);
            exit;
        }
    }

    $selectedPaymentId = (int)($_GET['payment_id'] ?? 0);
    if ($selectedId === '') {
        throw new RuntimeException('Please choose a student from the student list first.');
    }

    $selectedStudent = get_student($conn, $selectedId);
    if (!$selectedStudent) {
        throw new RuntimeException('The selected student could not be found.');
    }

    $historyStmt = $conn->prepare(
        "SELECT id, payment_date, amount_paid, tuition_fee, balance_after, receipt_no, payment_note, payment_items, payment_token, email_sent, created_at
         FROM tuition_payments
         WHERE enrollment_id = ?
           AND COALESCE(school_year, '') = ?
         ORDER BY payment_date DESC, id DESC"
    );
    $selectedSchoolYear = trim((string)($selectedStudent['school_year'] ?? ''));
    $selectedEnrollmentId = (int)($selectedStudent['id'] ?? 0);
    $historyStmt->bind_param('is', $selectedEnrollmentId, $selectedSchoolYear);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    while ($historyRow = $historyResult->fetch_assoc()) {
        $historyRow['items'] = decode_saved_payment_items((string)($historyRow['payment_items'] ?? ''), (float)($historyRow['amount_paid'] ?? 0));
        $paymentHistory[] = $historyRow;
        if (($selectedPaymentId > 0 && (int)$historyRow['id'] === $selectedPaymentId) || ($selectedPaymentId <= 0 && $selectedPayment === null)) {
            $selectedPayment = $historyRow;
        }
    }
    $historyStmt->close();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$selectedGradeLevel = $selectedStudent['grade_level'] ?? '';
$selectedTuitionFee = $selectedStudent && array_key_exists($selectedGradeLevel, $tuitionMap)
    ? (float)$tuitionMap[$selectedGradeLevel]
    : 0.0;
$gradeFeeDefaults = $selectedStudent
    ? ($gradeBreakdownMap[$selectedGradeLevel] ?? ['Tuition Fee' => $selectedTuitionFee])
    : [];
$fixedFeeTotal = 0.0;
foreach ($gradeFeeDefaults as $option => $defaultAmount) {
    if ($option !== 'Tuition Fee') {
        $fixedFeeTotal += (float)$defaultAmount;
    }
}
$fullTuitionAmount = max(0, round($selectedTuitionFee - $fixedFeeTotal, 2));
$monthlyPaymentAmount = max(0, round($fullTuitionAmount / 10, 2));
$savedReceiptCount = count($paymentHistory);
$remainingBalance = max(0, round($selectedTuitionFee - sum_payment_history($paymentHistory), 2));
$studentName = $selectedStudent ? format_name($selectedStudent) : '';
$studentEmail = trim((string)($selectedStudent['email'] ?? ''));

$paymentCatalogConfig = $gradeFeeDefaults;
$paymentCatalogConfig['Tuition Fee'] = $selectedTuitionFee;

$registrationKey = 'Registration Fee & Miscellaneous';
if (array_key_exists($registrationKey, $paymentCatalogConfig)) {
    $catalogWithMonthly = [];
    foreach ($paymentCatalogConfig as $option => $amount) {
        $catalogWithMonthly[$option] = $amount;
        if ($option === $registrationKey) {
            $catalogWithMonthly['Monthly Payment'] = $monthlyPaymentAmount;
        }
    }
    $paymentCatalogConfig = $catalogWithMonthly;
} else {
    $paymentCatalogConfig['Monthly Payment'] = $monthlyPaymentAmount;
}

$paymentOptions = array_keys($paymentCatalogConfig);
$paymentCatalog = [];
foreach ($paymentOptions as $option) {
    $defaultAmount = (float)($paymentCatalogConfig[$option] ?? 0);
    if ($option === 'Tuition Fee') {
        $hint = 'The brochure amount shows the annual program total. You can still enter the amount manually.';
    } elseif ($option === 'Monthly Payment') {
        $hint = 'This uses the brochure monthly tuition amount automatically.';
    } else {
        $hint = 'This uses the fixed amount from the grade-level brochure you provided.';
    }
    $paymentCatalog[] = [
        'option' => $option,
        'default_amount' => round($defaultAmount, 2),
        'hint' => $hint,
    ];
}

$paymentCatalogJson = htmlspecialchars(json_encode($paymentCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$emailConfig = get_email_config();
$configuredAppUrl = trim((string)($emailConfig['app_url'] ?? ''));
$uploadLinkNeedsRealHost = $configuredAppUrl === '' || is_loopback_host((string)parse_url($configuredAppUrl, PHP_URL_HOST));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMARTENROLL | Tuition Receipt Details</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/pay_tuition.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="dashboard-page dashboard-white-page">
<main class="dashboard-main">
    <div class="dashboard-header tuition-header">
        <div class="student-header-left">
            <a href="tuition_receipt.php" class="dashboard-link back-left"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="student-header-title">
                <h1>Tuition Receipt Details</h1>
                <p>Use the plus button to add the brochure breakdown items, then type the tuition amount and review the computed total below.</p>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="pay-alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="pay-alert success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <?php if ($warningMessage): ?>
        <div class="pay-alert info"><?php echo htmlspecialchars($warningMessage); ?></div>
    <?php endif; ?>
    <?php if ($uploadLinkNeedsRealHost): ?>
        <div class="pay-alert info">Set a real network or public URL in the mail config `app_url` value. Links using `localhost` can only be opened on this computer.</div>
    <?php endif; ?>

    <?php if ($selectedStudent): ?>
        <div class="selected-student-banner detail-student-banner">
            <div class="selected-student-copy">
                <span class="eyebrow">Selected Student</span>
                <h2><?php echo htmlspecialchars($studentName); ?></h2>
                <p>Grade Level: <?php echo htmlspecialchars((string)$selectedStudent['grade_level']); ?></p>
            </div>
            <div class="student-identity-grid">
                <div class="selected-student-email">
                    <span>School ID</span>
                    <strong><?php echo htmlspecialchars((string)$selectedStudent['student_id']); ?></strong>
                </div>
                <div class="selected-student-email">
                    <span>School Year</span>
                    <strong><?php echo htmlspecialchars((string)($selectedStudent['school_year'] ?: 'N/A')); ?></strong>
                </div>
                <div class="selected-student-email">
                    <span>Enrollment Email</span>
                    <strong><?php echo htmlspecialchars($studentEmail !== '' ? $studentEmail : 'No email saved on enrollment form'); ?></strong>
                </div>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <span>Annual Program Total</span>
                <strong><?php echo htmlspecialchars(format_money($selectedTuitionFee)); ?></strong>
            </div>
            <div class="summary-card">
                <span>Saved Receipts</span>
                <strong><?php echo htmlspecialchars((string)$savedReceiptCount); ?></strong>
            </div>
            <div class="summary-card accent">
                <span>Remaining Balance</span>
                <strong id="remainingBalanceDisplay"><?php echo htmlspecialchars(format_money($remainingBalance)); ?></strong>
            </div>
            <div class="summary-card">
                <span>Email Status</span>
                <strong><?php echo filter_var($studentEmail, FILTER_VALIDATE_EMAIL) ? 'Ready to send receipt' : 'Invalid student email'; ?></strong>
            </div>
        </div>

        <div class="detail-grid">
            <section class="card-block">
                <div class="block-head">
                    <h3>Step 2: Choose Payment Rows</h3>
                    <p>Select the brochure items you want to include. Tuition Fee now shows the annual program total, and Monthly Payment is available as a fixed choice.</p>
                </div>

                <div class="payment-catalog-card" id="paymentCatalog" data-catalog="<?php echo $paymentCatalogJson; ?>">
                    <div class="catalog-row catalog-header">
                        <span>Add</span>
                        <span>Payment Item</span>
                        <span>Brochure Amount</span>
                        <span>Details</span>
                    </div>
                    <?php foreach ($paymentCatalog as $catalogItem): ?>
                        <div
                            class="catalog-row"
                            data-option="<?php echo htmlspecialchars($catalogItem['option']); ?>"
                            data-default="<?php echo htmlspecialchars(number_format((float)$catalogItem['default_amount'], 2, '.', '')); ?>"
                        >
                            <button type="button" class="catalog-add-btn" aria-label="Add <?php echo htmlspecialchars($catalogItem['option']); ?>">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                            <strong><?php echo htmlspecialchars($catalogItem['option']); ?></strong>
                            <span><?php echo htmlspecialchars(format_money((float)$catalogItem['default_amount'])); ?></span>
                            <small><?php echo htmlspecialchars($catalogItem['hint']); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card-block">
                <div class="block-head">
                    <h3>Step 3: Review Total And Details</h3>
                    <p>Enter the receipt date and receipt number. Tuition Fee is editable, while the other rows use the brochure amount automatically. The remaining balance below updates as you add rows.</p>
                </div>

                <form class="payment-form" method="post" action="tuition_receipt_details.php" id="paymentBuilderForm">
                    <input type="hidden" name="action" value="save_payment">
                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars((string)$selectedStudent['student_id']); ?>">
                    <input type="hidden" name="payment_items_json" id="paymentItemsJson">

                    <div class="form-grid">
                        <label>
                            Payment Date
                            <input type="date" name="payment_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                        </label>
                        <label>
                            Receipt No.
                            <input type="text" name="receipt_no" placeholder="Official receipt number">
                        </label>
                    </div>

                    <div class="selected-payment-card">
                        <div class="selected-payment-head">
                            <h4>Selected Rows</h4>
                            <span>Tuition Fee can be edited. Other rows stay fixed.</span>
                        </div>
                        <div
                            class="selected-payment-table"
                            id="selectedPaymentTable"
                            data-remaining="<?php echo htmlspecialchars(number_format($remainingBalance, 2, '.', '')); ?>"
                            data-full-tuition="<?php echo htmlspecialchars(number_format($selectedTuitionFee, 2, '.', '')); ?>"
                        >
                            <div class="selected-payment-row selected-payment-header">
                                <span>Remove</span>
                                <span>Payment Item</span>
                                <span>Brochure Amount</span>
                                <span>Amount To Send</span>
                            </div>
                            <div class="selected-payment-empty" id="selectedPaymentEmpty">
                                Click the plus button from the left table to add a payment row.
                            </div>
                        </div>
                    </div>

                    <label>
                        Payment Note
                        <textarea name="payment_note" rows="3" placeholder="Optional note for this receipt"></textarea>
                    </label>

                    <div class="amount-preview">
                        <div>
                            <span>Full Tuition</span>
                            <strong><?php echo htmlspecialchars(format_money($selectedTuitionFee)); ?></strong>
                        </div>
                        <div>
                            <span>Total Breakdown</span>
                            <strong id="paymentPreview">PHP 0.00</strong>
                        </div>
                        <div>
                            <span>Remaining Balance</span>
                            <strong id="balanceAfterPreview"><?php echo htmlspecialchars(format_money($remainingBalance)); ?></strong>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="primary-btn">
                            <i class="fa-solid fa-receipt"></i>
                            Save Receipt
                        </button>
                        <a class="cancel-btn" href="tuition_receipt_details.php?student_id=<?php echo urlencode((string)$selectedStudent['student_id']); ?>">
                            <i class="fa-solid fa-rotate-left"></i>
                            Clear Form
                        </a>
                    </div>
                </form>
            </section>
        </div>

        <div class="detail-grid lower-detail-grid">
            <section class="card-block">
                <div class="block-head">
                    <h3>Latest Receipt Preview</h3>
                    <p>After you save a receipt, it will appear here with its row breakdown.</p>
                </div>

                <?php if ($selectedPayment): ?>
                    <div class="receipt-preview">
                        <div class="receipt-row"><span>Student</span><strong><?php echo htmlspecialchars($studentName); ?></strong></div>
                        <div class="receipt-row"><span>Grade Level</span><strong><?php echo htmlspecialchars((string)$selectedStudent['grade_level']); ?></strong></div>
                        <div class="receipt-row"><span>School ID</span><strong><?php echo htmlspecialchars((string)$selectedStudent['student_id']); ?></strong></div>
                        <div class="receipt-row"><span>Email</span><strong><?php echo htmlspecialchars($studentEmail !== '' ? $studentEmail : 'No email saved'); ?></strong></div>
                        <div class="receipt-row"><span>Payment Date</span><strong><?php echo htmlspecialchars((string)$selectedPayment['payment_date']); ?></strong></div>
                        <div class="receipt-row"><span>Receipt No.</span><strong><?php echo htmlspecialchars((string)($selectedPayment['receipt_no'] !== '' ? $selectedPayment['receipt_no'] : 'N/A')); ?></strong></div>
                        <div class="receipt-row"><span>Total Breakdown</span><strong><?php echo htmlspecialchars(format_money((float)$selectedPayment['amount_paid'])); ?></strong></div>
                        <div class="receipt-row"><span>Remaining Balance</span><strong><?php echo htmlspecialchars(format_money((float)$selectedPayment['balance_after'])); ?></strong></div>
                    </div>

                    <div class="receipt-breakdown">
                        <div class="receipt-breakdown-head">
                            <h4>Receipt Breakdown</h4>
                            <span><?php echo count($selectedPayment['items']); ?> row(s)</span>
                        </div>
                        <div class="receipt-breakdown-table">
                            <div class="receipt-breakdown-row receipt-breakdown-labels">
                                <span>Payment Item</span>
                                <span>Amount</span>
                            </div>
                            <?php foreach ($selectedPayment['items'] as $item): ?>
                                <div class="receipt-breakdown-row">
                                    <strong><?php echo htmlspecialchars((string)$item['label']); ?></strong>
                                    <strong><?php echo htmlspecialchars(format_money((float)$item['amount'])); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <form method="post" action="tuition_receipt_details.php" class="receipt-send-form">
                        <input type="hidden" name="action" value="send_receipt">
                        <input type="hidden" name="student_id" value="<?php echo htmlspecialchars((string)$selectedStudent['student_id']); ?>">
                        <input type="hidden" name="payment_id" value="<?php echo (int)$selectedPayment['id']; ?>">
                        <button type="submit" class="secondary-btn" <?php echo filter_var($studentEmail, FILTER_VALIDATE_EMAIL) ? '' : 'disabled'; ?>>
                            <i class="fa-solid fa-paper-plane"></i>
                            Send Receipt to Email
                        </button>
                    </form>
                <?php else: ?>
                    <div class="receipt-empty">
                    <p>Create a breakdown receipt first, then it can be emailed to the address entered on the enrollment form.</p>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card-block history-block" id="saved-receipts">
                <div class="block-head">
                    <h3>Saved Receipts</h3>
                    <p>Each saved receipt keeps the fee breakdown and can still be sent again.</p>
                </div>

                <div class="student-table-wrap">
                    <table class="student-table history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Receipt</th>
                                <th>Payment Items</th>
                                <th>Total Breakdown</th>
                                <th>Remaining Balance</th>
                                <th>Email Status</th>
                                <th>Send</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($paymentHistory)): ?>
                                <tr>
                                    <td colspan="7">No receipts saved yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($paymentHistory as $history): ?>
                                    <?php $isActiveReceipt = $selectedPayment && (int)$selectedPayment['id'] === (int)$history['id']; ?>
                                    <?php $historyItemNames = implode(', ', array_map(static fn($item) => (string)($item['label'] ?? ''), $history['items'])); ?>
                                    <tr class="<?php echo $isActiveReceipt ? 'active-history-row' : ''; ?>">
                                        <td>
                                            <a class="history-link" href="tuition_receipt_details.php?student_id=<?php echo urlencode((string)$selectedStudent['student_id']); ?>&payment_id=<?php echo (int)$history['id']; ?>#saved-receipts">
                                                <?php echo htmlspecialchars((string)$history['payment_date']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a class="history-link" href="tuition_receipt_details.php?student_id=<?php echo urlencode((string)$selectedStudent['student_id']); ?>&payment_id=<?php echo (int)$history['id']; ?>#saved-receipts">
                                                <?php echo htmlspecialchars((string)($history['receipt_no'] !== '' ? $history['receipt_no'] : 'N/A')); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a class="history-link" href="tuition_receipt_details.php?student_id=<?php echo urlencode((string)$selectedStudent['student_id']); ?>&payment_id=<?php echo (int)$history['id']; ?>#saved-receipts">
                                                <?php echo htmlspecialchars($historyItemNames !== '' ? $historyItemNames : 'N/A'); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a class="history-link" href="tuition_receipt_details.php?student_id=<?php echo urlencode((string)$selectedStudent['student_id']); ?>&payment_id=<?php echo (int)$history['id']; ?>#saved-receipts">
                                                <?php echo htmlspecialchars(format_money((float)$history['amount_paid'])); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a class="history-link" href="tuition_receipt_details.php?student_id=<?php echo urlencode((string)$selectedStudent['student_id']); ?>&payment_id=<?php echo (int)$history['id']; ?>#saved-receipts">
                                                <?php echo htmlspecialchars(format_money((float)$history['balance_after'])); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="status-pill <?php echo (int)$history['email_sent'] === 1 ? 'sent' : 'pending'; ?>">
                                                <?php echo (int)$history['email_sent'] === 1 ? 'Sent' : 'Not sent'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="post" action="tuition_receipt_details.php" class="table-send-form">
                                                <input type="hidden" name="action" value="send_receipt">
                                                <input type="hidden" name="student_id" value="<?php echo htmlspecialchars((string)$selectedStudent['student_id']); ?>">
                                                <input type="hidden" name="payment_id" value="<?php echo (int)$history['id']; ?>">
                                                <button type="submit" class="table-send-btn" <?php echo filter_var($studentEmail, FILTER_VALIDATE_EMAIL) ? '' : 'disabled'; ?>>
                                                    Send
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    <?php endif; ?>
</main>

<template id="selectedPaymentRowTemplate">
    <div class="selected-payment-row" data-option="">
        <button type="button" class="remove-selected-btn" aria-label="Remove payment row">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="selected-payment-label">
            <strong class="selected-item-name"></strong>
        </div>
        <span class="selected-suggested-amount">PHP 0.00</span>
        <div class="selected-row-entry">
            <span class="selected-row-status">Included</span>
            <label class="tuition-manual-wrap is-hidden">
                <input type="number" class="tuition-manual-input" min="0.01" step="0.01" placeholder="Enter tuition amount">
            </label>
        </div>
    </div>
</template>

<script src="js/pay_tuition.js"></script>
</body>
</html>
