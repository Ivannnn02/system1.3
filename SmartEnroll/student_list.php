<?php
require_once __DIR__ . '/auth.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$currentUser = smartenroll_require_login();
$isAdmin = (($currentUser['role'] ?? '') === 'admin');

$rows = [];
$error = '';
$page = 1;
$perPage = 20;
$totalRows = 0;
$totalPages = 1;
$status = trim((string)($_GET['status'] ?? ''));

try {
    $conn = new mysqli('127.0.0.1', 'root', '', 'smartenroll');
    $conn->set_charset('utf8mb4');

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }

    $countRes = $conn->query("SELECT COUNT(*) AS total FROM enrollments");
    $totalRows = (int)($countRes->fetch_assoc()['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;
    $sql = "SELECT id, student_id, learner_lname, learner_fname, learner_mname, grade_level, street, barangay, municipality, province
            FROM enrollments
            ORDER BY id DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMARTENROLL | Student List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/student_list.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="dashboard-page dashboard-white-page">

<main class="dashboard-main">
    <div class="dashboard-header student-header">
        <div class="student-header-left">
            <a href="dashboard.php" class="dashboard-link back-left">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="student-header-title">
                <h1>Student List</h1>
                <p>All enrolled students recorded in SMARTENROLL.</p>
            </div>
            <?php if ($isAdmin): ?>
                <a href="student_manage.php" class="student-add-btn">
                    <i class="fa-solid fa-plus"></i>
                    <span>Add Enrollment</span>
                </a>
            <?php endif; ?>
        </div>
        <div class="student-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input id="studentSearch" type="text" placeholder="Search">
        </div>
    </div>

    <div class="student-list-card">
        <?php if ($status === 'created'): ?>
            <div class="student-success">
                <strong>Enrollment added.</strong>
                <p>The full student record was saved successfully.</p>
            </div>
        <?php elseif ($status === 'updated'): ?>
            <div class="student-success">
                <strong>Enrollment updated.</strong>
                <p>The student details were saved successfully.</p>
            </div>
        <?php elseif ($status === 'deleted'): ?>
            <div class="student-success">
                <strong>Enrollment deleted.</strong>
                <p>The student record was removed successfully.</p>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="student-error">
                <strong>Unable to load students.</strong>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php elseif (empty($rows)): ?>
            <div class="student-empty">
                <p>No student records found.</p>
            </div>
        <?php else: ?>
            <div class="student-table-wrap">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Grade Level</th>
                            <th>Address</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $m = trim((string)($row['learner_mname'] ?? ''));
                                $mi = $m !== '' ? strtoupper(mb_substr($m, 0, 1)) . '.' : '';
                                $fullName = trim(
                                    ($row['learner_lname'] ?? '') . ', ' .
                                    ($row['learner_fname'] ?? '') . ' ' . $mi
                                );
                                $fullName = trim(preg_replace('/\s+/', ' ', $fullName), " ,");
                                $addressParts = array_filter([
                                    trim((string)($row['street'] ?? '')),
                                    trim((string)($row['barangay'] ?? '')),
                                    trim((string)($row['municipality'] ?? '')),
                                    trim((string)($row['province'] ?? ''))
                                ], fn($v) => $v !== '');
                                $address = implode(', ', $addressParts);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($fullName !== '' ? $fullName : '—'); ?></td>
                                <td><?php echo htmlspecialchars($row['grade_level'] ?? ''); ?></td>
                                <td class="address-cell" title="<?php echo htmlspecialchars($address); ?>">
                                    <?php echo htmlspecialchars($address !== '' ? $address : '—'); ?>
                                </td>
                                <td>
                                    <div class="student-actions">
                                        <a href="student_view.php?id=<?php echo urlencode((string)($row['id'] ?? '')); ?>" class="action-btn view" title="View">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                        <?php if ($isAdmin): ?>
                                            <a href="student_manage.php?id=<?php echo urlencode((string)($row['id'] ?? '')); ?>" class="action-btn edit" title="Edit">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </a>
                                            <a href="student_delete.php?id=<?php echo urlencode((string)($row['id'] ?? '')); ?>" class="action-btn delete" title="Delete">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <a class="page-btn nav" href="?page=<?php echo max(1, $page - 1); ?>">Prev</a>
                    <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1) {
                            echo '<a class="page-btn" href="?page=1">1</a>';
                            if ($start > 2) {
                                echo '<span class="page-ellipsis">...</span>';
                            }
                        }
                        for ($p = $start; $p <= $end; $p++) {
                            $active = $p === $page ? ' active' : '';
                            echo '<a class="page-btn' . $active . '" href="?page=' . $p . '">' . $p . '</a>';
                        }
                        if ($end < $totalPages) {
                            if ($end < $totalPages - 1) {
                                echo '<span class="page-ellipsis">...</span>';
                            }
                            echo '<a class="page-btn" href="?page=' . $totalPages . '">' . $totalPages . '</a>';
                        }
                    ?>
                    <a class="page-btn nav" href="?page=<?php echo min($totalPages, $page + 1); ?>">Next</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php if ($isAdmin): ?>
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-icon" id="deleteIconBox">
      <img src="assets/logo.png" alt="Logo" class="modal-logo" id="deleteLogo">
      <i class="fa-solid fa-triangle-exclamation" id="deleteIcon"></i>
    </div>
    <h3>Delete student?</h3>
    <p>This action cannot be undone.</p>
    <div class="modal-actions">
      <button type="button" class="modal-btn cancel" id="cancelDelete">Cancel</button>
      <a class="modal-btn confirm" id="confirmDelete" href="#">Delete</a>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="js/student_list.js"></script>
</body>
</html>
